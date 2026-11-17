<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Library integration: row actions + bulk actions on upload.php.
 * Bulk processing runs client-side one request at a time (assets/admin.js)
 * so a big batch can't hit PHP execution limits.
 */
class PixelPanda_Media {

	public static function init() {
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'attachment_submitbox_misc_actions', array( __CLASS__, 'submitbox' ) );
	}

	public static function row_actions( $actions, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		foreach ( pixelpanda_operations() as $key => $op ) {
			$actions[ 'pixelpanda_' . $key ] = sprintf(
				'<a href="#" class="pixelpanda-action" data-attachment="%d" data-operation="%s">%s</a>',
				(int) $post->ID,
				esc_attr( $key ),
				esc_html( 'PixelPanda: ' . $op['label'] )
			);
		}
		if ( get_post_meta( $post->ID, '_pixelpanda_backup_file', true ) ) {
			$actions['pixelpanda_restore'] = sprintf(
				'<a href="#" class="pixelpanda-restore" data-attachment="%d">%s</a>',
				(int) $post->ID,
				esc_html__( 'PixelPanda: Restore original', 'pixelpanda-ai-image-tools' )
			);
		}
		return $actions;
	}

	public static function bulk_actions( $actions ) {
		foreach ( pixelpanda_operations() as $key => $op ) {
			$actions[ 'pixelpanda_' . $key ] = 'PixelPanda: ' . $op['label'];
		}
		return $actions;
	}

	/**
	 * Bulk actions redirect back with the ids; admin.js picks them up and
	 * processes sequentially with a progress bar.
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'pixelpanda_' ) ) {
			return $redirect;
		}
		$op  = substr( $action, strlen( 'pixelpanda_' ) );
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		return add_query_arg( array(
			'pixelpanda_bulk_op'  => sanitize_key( $op ),
			'pixelpanda_bulk_ids' => implode( ',', $ids ),
		), $redirect );
	}

	public static function submitbox() {
		global $post;
		if ( ! $post || ! wp_attachment_is_image( $post->ID ) ) {
			return;
		}
		$src = get_post_meta( $post->ID, '_pixelpanda_operation', true );
		if ( $src ) {
			echo '<div class="misc-pub-section">' . esc_html(
				sprintf(
					/* translators: %s: operation key */
					__( 'Processed with PixelPanda (%s)', 'pixelpanda-ai-image-tools' ),
					str_replace( '_', ' ', $src )
				)
			) . '</div>';
		}
	}
}
