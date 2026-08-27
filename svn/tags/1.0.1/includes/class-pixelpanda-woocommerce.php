<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce: bulk actions on the Products list that process the featured
 * image + gallery of each selected product via the same client-side queue.
 */
class PixelPanda_WooCommerce {

	public static function init() {
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'wp_ajax_pixelpanda_product_images', array( __CLASS__, 'ajax_product_images' ) );
	}

	public static function bulk_actions( $actions ) {
		foreach ( pixelpanda_operations() as $key => $op ) {
			$actions[ 'pixelpanda_' . $key ] = 'PixelPanda: ' . $op['label'] . ' ' . __( '(all product images)', 'pixelpanda-ai-image-tools' );
		}
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'pixelpanda_' ) ) {
			return $redirect;
		}
		$op  = substr( $action, strlen( 'pixelpanda_' ) );
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		return add_query_arg( array(
			'pixelpanda_bulk_op'       => sanitize_key( $op ),
			'pixelpanda_bulk_products' => implode( ',', $ids ),
		), $redirect );
	}

	/** Resolve product ids → unique attachment ids (featured + gallery). */
	public static function ajax_product_images() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
		$ids = isset( $_POST['product_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ) ) ) ) : array();
		$attachments = array();
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$img = $product->get_image_id();
			if ( $img ) {
				$attachments[] = (int) $img;
			}
			foreach ( $product->get_gallery_image_ids() as $gid ) {
				$attachments[] = (int) $gid;
			}
		}
		wp_send_json_success( array( 'attachment_ids' => array_values( array_unique( $attachments ) ) ) );
	}
}
