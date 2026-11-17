<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background runs.
 *
 * A "run" is one bulk job: an operation applied to a list of images. It is
 * stored in the database and processed one image at a time, so it survives a
 * closed browser tab, can be cancelled, and can be undone as a whole.
 *
 * Two things move a run forward, and a lock makes sure only one does at a time:
 *  - the admin page, while it is open, asks the server to process the next image;
 *  - a scheduled action (Action Scheduler when WooCommerce is active, WP-Cron
 *    otherwise) keeps going after the tab is closed.
 */
class PixelPanda_Queue {

	const INDEX_OPTION = 'pixelpanda_runs';
	const ITEMS_PREFIX = 'pixelpanda_run_items_';
	const LOCK_PREFIX  = 'pixelpanda_run_lock_';
	const HOOK         = 'pixelpanda_run_tick';
	const KEEP_RUNS    = 15;
	const LOCK_TTL     = 300;
	/** Upscaling an image whose long edge is already past this is wasted credit: WordPress scales it back down. */
	const UPSCALE_SKIP_PX = 2560;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ), 10, 1 );
		add_action( 'wp_ajax_pixelpanda_run_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_pixelpanda_run_drive', array( __CLASS__, 'ajax_drive' ) );
		add_action( 'wp_ajax_pixelpanda_run_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_pixelpanda_run_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_pixelpanda_run_undo', array( __CLASS__, 'ajax_undo' ) );
		add_action( 'wp_ajax_pixelpanda_run_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_pixelpanda_backup_image', array( __CLASS__, 'ajax_backup_image' ) );
	}

	// ---- Storage -------------------------------------------------------------

	/** @return array<string,array> Runs keyed by id, newest first. */
	public static function runs() {
		$runs = get_option( self::INDEX_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	public static function get( $id ) {
		$runs = self::runs();
		return isset( $runs[ $id ] ) ? $runs[ $id ] : null;
	}

	private static function save( $run ) {
		$runs               = self::runs();
		$runs[ $run['id'] ] = $run;
		uasort( $runs, function ( $a, $b ) {
			return $b['created'] - $a['created'];
		} );
		// Drop the oldest finished runs (and their item lists) past the cap.
		foreach ( array_slice( array_keys( $runs ), self::KEEP_RUNS ) as $old ) {
			delete_option( self::ITEMS_PREFIX . $old );
			unset( $runs[ $old ] );
		}
		update_option( self::INDEX_OPTION, $runs, false );
	}

	public static function items( $id ) {
		$items = get_option( self::ITEMS_PREFIX . $id, array() );
		return is_array( $items ) ? $items : array();
	}

	private static function save_items( $id, $items ) {
		update_option( self::ITEMS_PREFIX . $id, $items, false );
	}

	// ---- Creating a run --------------------------------------------------------

	/**
	 * @param string $operation Key from pixelpanda_operations().
	 * @param string $mode      'new' | 'replace' | 'gallery'.
	 * @param array  $targets   List of [ 'attachment_id' => int, 'product_id' => int ].
	 * @return array|WP_Error   The run.
	 */
	public static function create( $operation, $mode, $targets ) {
		$ops = pixelpanda_operations();
		if ( ! isset( $ops[ $operation ] ) ) {
			return new WP_Error( 'pixelpanda_op', __( 'Unknown operation.', 'pixelpanda-ai-image-tools' ) );
		}
		if ( ! in_array( $mode, array( 'new', 'replace', 'gallery' ), true ) ) {
			$mode = 'new';
		}
		$items = array();
		$seen  = array();
		foreach ( $targets as $t ) {
			$aid = isset( $t['attachment_id'] ) ? absint( $t['attachment_id'] ) : 0;
			if ( ! $aid || isset( $seen[ $aid ] ) || ! wp_attachment_is_image( $aid ) ) {
				continue;
			}
			$seen[ $aid ] = true;
			$items[]      = array(
				'a' => $aid,
				'p' => isset( $t['product_id'] ) ? absint( $t['product_id'] ) : 0,
				's' => 'pending',
				'r' => 0,
				'e' => '',
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'pixelpanda_empty_run', __( 'No images to process.', 'pixelpanda-ai-image-tools' ) );
		}
		$run = array(
			'id'      => substr( md5( uniqid( 'pp', true ) ), 0, 12 ),
			'op'      => $operation,
			'mode'    => $mode,
			'status'  => 'running',
			'total'   => count( $items ),
			'done'    => 0,
			'failed'  => 0,
			'skipped' => 0,
			'credits' => 0,
			'created' => time(),
			'user'    => get_current_user_id(),
			'message' => '',
		);
		self::save_items( $run['id'], $items );
		self::save( $run );
		self::schedule( $run['id'] );
		return $run;
	}

	private static function schedule( $id, $delay = 30 ) {
		// The delay gives an open admin page first go; the schedule only matters once the tab is gone.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_next_scheduled_action( self::HOOK, array( $id ), 'pixelpanda' ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), 'pixelpanda' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, array( $id ) );
		}
	}

	// ---- Processing ------------------------------------------------------------

	private static function lock( $id ) {
		$key = self::LOCK_PREFIX . $id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		// A lock older than the TTL belongs to a request that died mid-image.
		if ( time() - (int) get_option( $key ) > self::LOCK_TTL ) {
			update_option( $key, time(), false );
			return true;
		}
		return false;
	}

	private static function unlock( $id ) {
		delete_option( self::LOCK_PREFIX . $id );
	}

	/** Scheduled entry point: work for a while, then hand over to the next tick. */
	public static function tick( $id ) {
		$deadline = time() + 20;
		do {
			$state = self::step( $id );
		} while ( 'continue' === $state && time() < $deadline );
		if ( 'continue' === $state || 'busy' === $state ) {
			self::schedule( $id, 'busy' === $state ? 60 : 5 );
		}
	}

	/**
	 * Process the next pending image of a run.
	 *
	 * @return string 'continue' (more to do) | 'busy' (another request holds it) | 'finished'.
	 */
	public static function step( $id ) {
		$run = self::get( $id );
		if ( ! $run || 'running' !== $run['status'] ) {
			return 'finished';
		}
		if ( ! self::lock( $id ) ) {
			return 'busy';
		}
		$items = self::items( $id );
		$index = null;
		foreach ( $items as $i => $item ) {
			if ( 'pending' === $item['s'] ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			$run['status'] = 'done';
			self::save( $run );
			self::unlock( $id );
			return 'finished';
		}

		$item = $items[ $index ];
		$skip = self::skip_reason( $item['a'], $run['op'] );
		if ( $skip ) {
			$item['s'] = 'skip';
			$item['e'] = $skip;
			$run['skipped']++;
		} else {
			$res = PixelPanda_Processor::run( $item['a'], $run['op'], $run['mode'], $item['p'] );
			if ( is_wp_error( $res ) ) {
				$item['s'] = 'fail';
				$item['e'] = $res->get_error_message();
				$run['failed']++;
				// Nothing else will succeed without credits or a valid key: stop instead of failing the rest.
				if ( in_array( $res->get_error_code(), array( 'pixelpanda_http_402', 'pixelpanda_http_401', 'pixelpanda_no_key' ), true ) ) {
					$run['status']  = 'stopped';
					$run['message'] = $res->get_error_message();
				}
			} else {
				$item['s'] = 'ok';
				$item['r'] = (int) $res['attachment_id'];
				$run['done']++;
				$run['credits'] += (int) $res['credits_used'];
			}
		}
		$items[ $index ] = $item;
		self::save_items( $id, $items );

		$remaining = 0;
		foreach ( $items as $it ) {
			if ( 'pending' === $it['s'] ) {
				$remaining++;
			}
		}
		if ( 0 === $remaining && 'running' === $run['status'] ) {
			$run['status'] = 'done';
		}
		// A cancel may have landed while this image was processing; keep it.
		$fresh = self::get( $id );
		if ( $fresh && 'cancelled' === $fresh['status'] ) {
			$run['status'] = 'cancelled';
		}
		self::save( $run );
		self::unlock( $id );
		return 'running' === $run['status'] ? 'continue' : 'finished';
	}

	private static function skip_reason( $attachment_id, $operation ) {
		if ( 0 === strpos( $operation, 'upscale' ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['width'] ) && max( (int) $meta['width'], (int) $meta['height'] ) >= self::UPSCALE_SKIP_PX ) {
				return __( 'Already high resolution — no credit used.', 'pixelpanda-ai-image-tools' );
			}
		}
		return '';
	}

	// ---- Undo --------------------------------------------------------------------

	/**
	 * Reverse up to $limit processed images of a run.
	 *
	 * Replace mode restores the stored original. New / gallery mode deletes the
	 * image the run created (and takes it out of the product gallery).
	 *
	 * @return int Number of images still to undo.
	 */
	public static function undo( $id, $limit = 10 ) {
		$run = self::get( $id );
		if ( ! $run ) {
			return 0;
		}
		if ( 'running' === $run['status'] ) {
			$run['status'] = 'cancelled';
		}
		$items = self::items( $id );
		$count = 0;
		$left  = 0;
		foreach ( $items as $i => $item ) {
			if ( 'ok' !== $item['s'] ) {
				continue;
			}
			if ( $count >= $limit ) {
				$left++;
				continue;
			}
			if ( 'replace' === $run['mode'] ) {
				PixelPanda_Processor::restore( $item['a'] );
			} elseif ( $item['r'] && $item['r'] !== $item['a'] ) {
				if ( $item['p'] ) {
					PixelPanda_Processor::remove_from_gallery( $item['p'], $item['r'] );
				}
				wp_delete_attachment( $item['r'], true );
			}
			$items[ $i ]['s'] = 'undone';
			$count++;
		}
		self::save_items( $id, $items );
		if ( 0 === $left ) {
			$run['status'] = 'undone';
		}
		self::save( $run );
		return $left;
	}

	// ---- AJAX ----------------------------------------------------------------------

	private static function guard() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
	}

	private static function run_from_request() {
		$id  = isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$run = self::get( $id );
		if ( ! $run ) {
			wp_send_json_error( array( 'message' => __( 'That run no longer exists.', 'pixelpanda-ai-image-tools' ) ), 404 );
		}
		return $run;
	}

	/** Resolve the request's selection (attachments, products, or the whole catalogue) to targets. */
	private static function targets_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$targets = array();
		if ( ! empty( $_POST['all_products'] ) && class_exists( 'PixelPanda_WooCommerce' ) && current_user_can( 'edit_products' ) ) {
			$targets = PixelPanda_WooCommerce::targets_for_products( PixelPanda_WooCommerce::all_product_ids() );
		} elseif ( ! empty( $_POST['product_ids'] ) && class_exists( 'PixelPanda_WooCommerce' ) && current_user_can( 'edit_products' ) ) {
			$ids     = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ) ) ) );
			$targets = PixelPanda_WooCommerce::targets_for_products( $ids );
		} elseif ( ! empty( $_POST['attachment_ids'] ) ) {
			foreach ( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['attachment_ids'] ) ) ) ) ) as $aid ) {
				if ( current_user_can( 'edit_post', $aid ) ) {
					$targets[] = array( 'attachment_id' => $aid, 'product_id' => 0 );
				}
			}
		}
		// phpcs:enable
		return $targets;
	}

	/** How many images a selection covers, so the dialog can show the cost before anything is spent. */
	public static function ajax_preview() {
		self::guard();
		$targets = self::targets_from_request();
		$unique  = array();
		foreach ( $targets as $t ) {
			$unique[ $t['attachment_id'] ] = true;
		}
		wp_send_json_success( array( 'images' => count( $unique ) ) );
	}

	public static function ajax_create() {
		self::guard();
		if ( ! PixelPanda_API::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Add your PixelPanda API key under Settings → PixelPanda.', 'pixelpanda-ai-image-tools' ) ) );
		}
		$op   = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'new'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run  = self::create( $op, $mode, self::targets_from_request() );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ) );
		}
		wp_send_json_success( self::public_run( $run ) );
	}

	/** The open admin page calls this in a loop: process one image, report progress. */
	public static function ajax_drive() {
		self::guard();
		$run = self::run_from_request();
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 240 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- one image can take over a minute.
		}
		$state = self::step( $run['id'] );
		$out   = self::public_run( self::get( $run['id'] ) );
		$out['state'] = $state;
		wp_send_json_success( $out );
	}

	public static function ajax_status() {
		self::guard();
		wp_send_json_success( self::public_run( self::run_from_request() ) );
	}

	public static function ajax_cancel() {
		self::guard();
		$run = self::run_from_request();
		if ( 'running' === $run['status'] ) {
			$run['status'] = 'cancelled';
			self::save( $run );
		}
		wp_send_json_success( self::public_run( $run ) );
	}

	public static function ajax_undo() {
		self::guard();
		$run  = self::run_from_request();
		$left = self::undo( $run['id'], 5 );
		$out  = self::public_run( self::get( $run['id'] ) );
		$out['left'] = $left;
		wp_send_json_success( $out );
	}

	/**
	 * Stream a stored original (replace mode keeps them in an access-protected
	 * folder) so the review screen can show "before" next to "after".
	 */
	public static function ajax_backup_image() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		$id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			status_header( 403 );
			exit;
		}
		$path = PixelPanda_Processor::backup_path_for( $id );
		if ( ! $path || ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		$type = wp_check_filetype( $path );
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: private, max-age=300' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Run summary for the browser. */
	public static function public_run( $run ) {
		$ops = pixelpanda_operations();
		return array(
			'id'        => $run['id'],
			'operation' => isset( $ops[ $run['op'] ] ) ? $ops[ $run['op'] ]['label'] : $run['op'],
			'mode'      => $run['mode'],
			'status'    => $run['status'],
			'total'     => (int) $run['total'],
			'done'      => (int) $run['done'],
			'failed'    => (int) $run['failed'],
			'skipped'   => (int) $run['skipped'],
			'credits'   => (int) $run['credits'],
			'message'   => $run['message'],
			'url'       => admin_url( 'options-general.php?page=pixelpanda&run=' . $run['id'] ),
		);
	}
}
