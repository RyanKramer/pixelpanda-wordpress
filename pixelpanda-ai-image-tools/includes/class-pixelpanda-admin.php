<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page (Settings → PixelPanda) + the AJAX endpoints the admin JS calls.
 */
class PixelPanda_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_missing_key' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PIXELPANDA_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'wp_ajax_pixelpanda_process', array( __CLASS__, 'ajax_process' ) );
		add_action( 'wp_ajax_pixelpanda_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_pixelpanda_validate_key', array( __CLASS__, 'ajax_validate_key' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'PixelPanda AI Image Tools', 'pixelpanda-ai-image-tools' ),
			__( 'PixelPanda', 'pixelpanda-ai-image-tools' ),
			'manage_options',
			'pixelpanda',
			array( __CLASS__, 'render' )
		);
	}

	public static function settings() {
		register_setting( 'pixelpanda', 'pixelpanda_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );
		register_setting( 'pixelpanda', 'pixelpanda_default_mode', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return 'replace' === $v ? 'replace' : 'new';
			},
			'default'           => 'new',
		) );
		register_setting( 'pixelpanda', 'pixelpanda_cutout_format', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return 'png' === $v ? 'png' : 'webp';
			},
		) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=pixelpanda' ) ) . '">' . esc_html__( 'Settings', 'pixelpanda-ai-image-tools' ) . '</a>' );
		return $links;
	}

	public static function notice_missing_key() {
		if ( PixelPanda_API::is_configured() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'upload', 'edit-product', 'plugins' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'PixelPanda AI Image Tools needs an API key before it can process images.', 'pixelpanda-ai-image-tools' ),
			esc_url( admin_url( 'options-general.php?page=pixelpanda' ) ),
			esc_html__( 'Add your key', 'pixelpanda-ai-image-tools' )
		);
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'upload.php', 'edit.php', 'post.php', 'settings_page_pixelpanda' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pixelpanda-admin', PIXELPANDA_URL . 'assets/admin.css', array(), PIXELPANDA_VERSION );
		wp_enqueue_script( 'pixelpanda-admin', PIXELPANDA_URL . 'assets/admin.js', array( 'jquery' ), PIXELPANDA_VERSION, true );
		wp_localize_script( 'pixelpanda-admin', 'PixelPanda', array(
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'pixelpanda' ),
			'operations' => pixelpanda_operations(),
			'configured' => PixelPanda_API::is_configured(),
			'settings'   => admin_url( 'options-general.php?page=pixelpanda' ),
			'defaultMode' => get_option( 'pixelpanda_default_mode', 'new' ),
			'hasWoo'     => class_exists( 'WooCommerce' ),
			'i18n'       => array(
				'chooseTitle'  => __( 'PixelPanda bulk job', 'pixelpanda-ai-image-tools' ),
				/* translators: 1: operation name, 2: number of images */
				'chooseIntro'  => __( '%1$s on %2$d image(s).', 'pixelpanda-ai-image-tools' ),
				/* translators: %d: number of credits */
				'chooseCost'   => __( 'Uses up to %d credit(s). Images that are skipped or fail are not charged.', 'pixelpanda-ai-image-tools' ),
				'modeReplace'  => __( 'Replace the original images', 'pixelpanda-ai-image-tools' ),
				'modeReplaceD' => __( 'Your store shows the new images straight away. Every original is backed up and the whole job can be undone in one click.', 'pixelpanda-ai-image-tools' ),
				'modeGallery'  => __( 'Add to each product\'s gallery', 'pixelpanda-ai-image-tools' ),
				'modeGalleryD' => __( 'Keeps the originals and adds the new image next to them.', 'pixelpanda-ai-image-tools' ),
				'modeNew'      => __( 'Only save to the Media Library', 'pixelpanda-ai-image-tools' ),
				'modeNewD'     => __( 'Nothing on your site changes. You place the new images yourself.', 'pixelpanda-ai-image-tools' ),
				'start'        => __( 'Start', 'pixelpanda-ai-image-tools' ),
				'cancel'       => __( 'Cancel', 'pixelpanda-ai-image-tools' ),
				'counting'     => __( 'Counting images…', 'pixelpanda-ai-image-tools' ),
				'noImages'     => __( 'No images found in that selection.', 'pixelpanda-ai-image-tools' ),
				/* translators: 1: processed count, 2: total, 3: failed count, 4: skipped count */
				'progress'     => __( 'PixelPanda: %1$d of %2$d done (%3$d failed, %4$d skipped). Runs in the background — you can leave this page.', 'pixelpanda-ai-image-tools' ),
				/* translators: 1: done count, 2: failed count, 3: skipped count */
				'finished'     => __( 'PixelPanda: finished. %1$d done, %2$d failed, %3$d skipped.', 'pixelpanda-ai-image-tools' ),
				'review'       => __( 'Review results / undo', 'pixelpanda-ai-image-tools' ),
				'stopJob'      => __( 'Stop', 'pixelpanda-ai-image-tools' ),
				'stopped'      => __( 'PixelPanda: stopped.', 'pixelpanda-ai-image-tools' ),
				'undoConfirm'  => __( 'Undo this whole job? Replaced images go back to their originals; images the job added are deleted.', 'pixelpanda-ai-image-tools' ),
				/* translators: %d: images left to undo */
				'undoing'      => __( 'Undoing… %d left', 'pixelpanda-ai-image-tools' ),
				'undone'       => __( 'Job undone.', 'pixelpanda-ai-image-tools' ),
				'processing' => __( 'Processing…', 'pixelpanda-ai-image-tools' ),
				'done'       => __( 'Done', 'pixelpanda-ai-image-tools' ),
				'failed'     => __( 'Failed', 'pixelpanda-ai-image-tools' ),
				/* translators: %d: number of credits the operation will use */
				'confirm'    => __( 'This will use %d credit(s). Continue?', 'pixelpanda-ai-image-tools' ),
				/* translators: %d: remaining credit balance */
				'credits'    => __( 'Credits remaining: %d', 'pixelpanda-ai-image-tools' ),
			),
		) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key  = PixelPanda_API::api_key();
		$mode = get_option( 'pixelpanda_default_mode', 'new' );
		?>
		<div class="wrap pixelpanda-settings">
			<h1><?php esc_html_e( 'PixelPanda AI Image Tools', 'pixelpanda-ai-image-tools' ); ?></h1>
			<p><?php esc_html_e( 'Remove backgrounds, upscale, enhance and strip text from any image in your Media Library or WooCommerce catalog. Each operation uses PixelPanda credits.', 'pixelpanda-ai-image-tools' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'pixelpanda' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pixelpanda_api_key"><?php esc_html_e( 'API key', 'pixelpanda-ai-image-tools' ); ?></label></th>
						<td>
							<input type="password" id="pixelpanda_api_key" name="pixelpanda_api_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" autocomplete="off" placeholder="pk_live_…" />
							<button type="button" class="button" id="pixelpanda-validate"><?php esc_html_e( 'Test key', 'pixelpanda-ai-image-tools' ); ?></button>
							<span id="pixelpanda-validate-result"></span>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to API settings */
									esc_html__( 'Get your key from %s (sign in → Profile → API).', 'pixelpanda-ai-image-tools' ),
									'<a href="https://pixelpanda.ai/profile" target="_blank" rel="noopener">pixelpanda.ai/profile</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Result handling', 'pixelpanda-ai-image-tools' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="pixelpanda_default_mode" value="new" <?php checked( $mode, 'new' ); ?> /> <?php esc_html_e( 'Save as a new image (keeps the original)', 'pixelpanda-ai-image-tools' ); ?></label><br />
								<label><input type="radio" name="pixelpanda_default_mode" value="replace" <?php checked( $mode, 'replace' ); ?> /> <?php esc_html_e( 'Replace the original in place (a backup is kept; you can restore)', 'pixelpanda-ai-image-tools' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Background removal format', 'pixelpanda-ai-image-tools' ); ?></th>
						<td>
							<?php $cutout_format = pixelpanda_cutout_format(); ?>
							<fieldset>
								<label><input type="radio" name="pixelpanda_cutout_format" value="webp" <?php checked( $cutout_format, 'webp' ); ?> /> <?php esc_html_e( 'WebP — transparent and much smaller (recommended for product images)', 'pixelpanda-ai-image-tools' ); ?></label><br />
								<label><input type="radio" name="pixelpanda_cutout_format" value="png" <?php checked( $cutout_format, 'png' ); ?> /> <?php esc_html_e( 'PNG — transparent, lossless, larger files', 'pixelpanda-ai-image-tools' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::render_runs(); ?>
			<h2><?php esc_html_e( 'How to use', 'pixelpanda-ai-image-tools' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Media → Library: hover an image and pick a PixelPanda action, or select several and use the Bulk actions menu.', 'pixelpanda-ai-image-tools' ); ?></li>
				<li><?php esc_html_e( 'WooCommerce → Products: select products and choose a PixelPanda bulk action to process every product image (featured, gallery and variation images). You choose whether results replace the originals, are added to the gallery, or only go to the Media Library.', 'pixelpanda-ai-image-tools' ); ?></li>
				<li><?php esc_html_e( 'Bulk jobs run in the background: you can close the tab and come back. Every job is listed above, where you can review before/after and undo the whole job.', 'pixelpanda-ai-image-tools' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Pricing: 1 credit per image for every operation above. Credits never expire.', 'pixelpanda-ai-image-tools' ); ?> <a href="https://pixelpanda.ai/pricing" target="_blank" rel="noopener">pixelpanda.ai/pricing</a></p>
		</div>
		<?php
	}

	private static function status_label( $status ) {
		$labels = array(
			'running'   => __( 'Running', 'pixelpanda-ai-image-tools' ),
			'done'      => __( 'Finished', 'pixelpanda-ai-image-tools' ),
			'stopped'   => __( 'Stopped', 'pixelpanda-ai-image-tools' ),
			'cancelled' => __( 'Stopped by you', 'pixelpanda-ai-image-tools' ),
			'undone'    => __( 'Undone', 'pixelpanda-ai-image-tools' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function mode_label( $mode ) {
		$labels = array(
			'replace' => __( 'Replaced originals', 'pixelpanda-ai-image-tools' ),
			'gallery' => __( 'Added to gallery', 'pixelpanda-ai-image-tools' ),
			'new'     => __( 'Media Library only', 'pixelpanda-ai-image-tools' ),
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	/** Whole-catalogue launcher, the list of bulk jobs, and one job's before/after review. */
	private static function render_runs() {
		$ops  = pixelpanda_operations();
		$runs = PixelPanda_Queue::runs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.
		$view = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';

		if ( $view && isset( $runs[ $view ] ) ) {
			self::render_run_detail( $runs[ $view ] );
			return;
		}

		if ( class_exists( 'WooCommerce' ) ) {
			?>
			<h2><?php esc_html_e( 'Whole catalogue', 'pixelpanda-ai-image-tools' ); ?></h2>
			<p><?php esc_html_e( 'Process every image of every product in one background job. You will see the image count and credit cost before anything is spent.', 'pixelpanda-ai-image-tools' ); ?></p>
			<p>
				<select id="pixelpanda-catalogue-op">
					<?php foreach ( $ops as $key => $op ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'sharpen' ); ?>><?php echo esc_html( $op['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-secondary" id="pixelpanda-catalogue-start"><?php esc_html_e( 'Process all product images…', 'pixelpanda-ai-image-tools' ); ?></button>
			</p>
			<?php
		}
		?>
		<h2><?php esc_html_e( 'Bulk jobs', 'pixelpanda-ai-image-tools' ); ?></h2>
		<?php if ( ! $runs ) : ?>
			<p class="description"><?php esc_html_e( 'No bulk jobs yet. Start one from Media → Library or WooCommerce → Products.', 'pixelpanda-ai-image-tools' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pixelpanda-runs">
				<thead><tr>
					<th><?php esc_html_e( 'Started', 'pixelpanda-ai-image-tools' ); ?></th>
					<th><?php esc_html_e( 'Operation', 'pixelpanda-ai-image-tools' ); ?></th>
					<th><?php esc_html_e( 'Result', 'pixelpanda-ai-image-tools' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'pixelpanda-ai-image-tools' ); ?></th>
					<th><?php esc_html_e( 'Credits', 'pixelpanda-ai-image-tools' ); ?></th>
					<th><?php esc_html_e( 'Status', 'pixelpanda-ai-image-tools' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $runs as $run ) : ?>
					<tr data-run="<?php echo esc_attr( $run['id'] ); ?>" data-status="<?php echo esc_attr( $run['status'] ); ?>">
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run['created'] ) ); ?></td>
						<td><?php echo esc_html( isset( $ops[ $run['op'] ] ) ? $ops[ $run['op'] ]['label'] : $run['op'] ); ?></td>
						<td><?php echo esc_html( self::mode_label( $run['mode'] ) ); ?></td>
						<td class="pixelpanda-run-progress">
							<?php
							/* translators: 1: done, 2: total, 3: failed, 4: skipped */
							echo esc_html( sprintf( __( '%1$d of %2$d (%3$d failed, %4$d skipped)', 'pixelpanda-ai-image-tools' ), $run['done'], $run['total'], $run['failed'], $run['skipped'] ) );
							?>
						</td>
						<td class="pixelpanda-run-credits"><?php echo esc_html( (int) $run['credits'] ); ?></td>
						<td class="pixelpanda-run-status"><?php echo esc_html( self::status_label( $run['status'] ) ); ?><?php echo $run['message'] ? ' — ' . esc_html( $run['message'] ) : ''; ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'options-general.php?page=pixelpanda&run=' . $run['id'] ) ); ?>"><?php esc_html_e( 'Review', 'pixelpanda-ai-image-tools' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_run_detail( $run ) {
		$ops    = pixelpanda_operations();
		$items  = PixelPanda_Queue::items( $run['id'] );
		$nonce  = wp_create_nonce( 'pixelpanda' );
		$undo   = in_array( $run['status'], array( 'done', 'stopped', 'cancelled', 'running' ), true ) && $run['done'] > 0;
		?>
		<h2>
			<?php echo esc_html( isset( $ops[ $run['op'] ] ) ? $ops[ $run['op'] ]['label'] : $run['op'] ); ?>
			— <?php echo esc_html( self::mode_label( $run['mode'] ) ); ?>
		</h2>
		<p class="pixelpanda-run-summary" data-run="<?php echo esc_attr( $run['id'] ); ?>" data-status="<?php echo esc_attr( $run['status'] ); ?>">
			<strong class="pixelpanda-run-status"><?php echo esc_html( self::status_label( $run['status'] ) ); ?></strong>
			<span class="pixelpanda-run-progress">
				<?php
				/* translators: 1: done, 2: total, 3: failed, 4: skipped */
				echo esc_html( sprintf( __( '%1$d of %2$d (%3$d failed, %4$d skipped)', 'pixelpanda-ai-image-tools' ), $run['done'], $run['total'], $run['failed'], $run['skipped'] ) );
				?>
			</span>
			· <?php
			/* translators: %d: credits used */
			echo esc_html( sprintf( __( '%d credit(s) used', 'pixelpanda-ai-image-tools' ), $run['credits'] ) );
			?>
			<?php echo $run['message'] ? ' · ' . esc_html( $run['message'] ) : ''; ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=pixelpanda' ) ); ?>">&larr; <?php esc_html_e( 'All jobs', 'pixelpanda-ai-image-tools' ); ?></a>
			<?php if ( 'running' === $run['status'] ) : ?>
				<button type="button" class="button pixelpanda-run-stop" data-run="<?php echo esc_attr( $run['id'] ); ?>"><?php esc_html_e( 'Stop', 'pixelpanda-ai-image-tools' ); ?></button>
			<?php endif; ?>
			<?php if ( $undo ) : ?>
				<button type="button" class="button button-link-delete pixelpanda-run-undo" data-run="<?php echo esc_attr( $run['id'] ); ?>"><?php esc_html_e( 'Undo this whole job', 'pixelpanda-ai-image-tools' ); ?></button>
			<?php endif; ?>
		</p>
		<div class="pixelpanda-review">
			<?php
			foreach ( array_slice( $items, 0, 300 ) as $item ) :
				$title  = get_the_title( $item['a'] );
				$before = '';
				$after  = '';
				if ( 'ok' === $item['s'] ) {
					if ( 'replace' === $run['mode'] ) {
						$before = add_query_arg( array( 'action' => 'pixelpanda_backup_image', 'attachment_id' => $item['a'], 'nonce' => $nonce ), admin_url( 'admin-ajax.php' ) );
						$after  = wp_get_attachment_image_url( $item['a'], 'medium' );
					} else {
						$before = wp_get_attachment_image_url( $item['a'], 'medium' );
						$after  = wp_get_attachment_image_url( $item['r'], 'medium' );
					}
				} else {
					$before = wp_get_attachment_image_url( $item['a'], 'medium' );
				}
				?>
				<div class="pixelpanda-review-item pixelpanda-is-<?php echo esc_attr( $item['s'] ); ?>">
					<div class="pixelpanda-review-pair">
						<?php if ( $before ) : ?>
							<a href="<?php echo esc_url( 'ok' === $item['s'] && 'replace' === $run['mode'] ? $before : wp_get_attachment_url( $item['a'] ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $before ); ?>" alt="" loading="lazy" /><span><?php esc_html_e( 'Before', 'pixelpanda-ai-image-tools' ); ?></span></a>
						<?php endif; ?>
						<?php if ( $after ) : ?>
							<a href="<?php echo esc_url( wp_get_attachment_url( 'replace' === $run['mode'] ? $item['a'] : $item['r'] ) ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $after ); ?>" alt="" loading="lazy" /><span><?php esc_html_e( 'After', 'pixelpanda-ai-image-tools' ); ?></span></a>
						<?php endif; ?>
					</div>
					<div class="pixelpanda-review-meta">
						<?php echo esc_html( $title ? $title : '#' . $item['a'] ); ?>
						<?php if ( 'fail' === $item['s'] || 'skip' === $item['s'] ) : ?>
							<br /><em><?php echo esc_html( $item['e'] ); ?></em>
						<?php elseif ( 'pending' === $item['s'] ) : ?>
							<br /><em><?php esc_html_e( 'Waiting…', 'pixelpanda-ai-image-tools' ); ?></em>
						<?php elseif ( 'undone' === $item['s'] ) : ?>
							<br /><em><?php esc_html_e( 'Undone', 'pixelpanda-ai-image-tools' ); ?></em>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( count( $items ) > 300 ) : ?>
			<p class="description">
				<?php
				/* translators: %d: total images in the job */
				echo esc_html( sprintf( __( 'Showing the first 300 of %d images.', 'pixelpanda-ai-image-tools' ), count( $items ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function check( $cap = 'upload_files' ) {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
	}

	public static function ajax_validate_key() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		self::check( 'manage_options' );
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$acc = PixelPanda_API::account( $key );
		if ( is_wp_error( $acc ) ) {
			wp_send_json_error( array( 'message' => $acc->get_error_message() ) );
		}
		wp_send_json_success( array(
			'email'   => isset( $acc['email'] ) ? $acc['email'] : '',
			'credits' => isset( $acc['credits'] ) ? (int) $acc['credits'] : 0,
		) );
	}

	public static function ajax_process() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		self::check();
		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$op = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
		$res = PixelPanda_Processor::run( $id, $op );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_restore() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		self::check();
		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
		$res = PixelPanda_Processor::restore( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'url' => wp_get_attachment_url( $id ) ) );
	}
}
