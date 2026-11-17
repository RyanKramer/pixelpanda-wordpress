<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce: bulk actions on the Products list that process the featured
 * image, gallery and variation images of each selected product as a
 * background run (see PixelPanda_Queue).
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

	/** Every published or draft product id (variations are reached through their parent). */
	public static function all_product_ids() {
		return array_map( 'intval', get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) ) );
	}

	/**
	 * Product ids → the images to process: featured image, gallery images, and
	 * each variation's own image. An image shared by several products appears
	 * once, against the first product that uses it.
	 *
	 * @param int[] $product_ids
	 * @return array[] [ 'attachment_id' => int, 'product_id' => int ]
	 */
	public static function targets_for_products( $product_ids ) {
		$targets = array();
		$seen    = array();
		$add     = function ( $attachment_id, $product_id ) use ( &$targets, &$seen ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id && ! isset( $seen[ $attachment_id ] ) ) {
				$seen[ $attachment_id ] = true;
				$targets[]              = array( 'attachment_id' => $attachment_id, 'product_id' => (int) $product_id );
			}
		};
		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$add( $product->get_image_id(), $pid );
			foreach ( $product->get_gallery_image_ids() as $gid ) {
				$add( $gid, $pid );
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						// A variation with no image of its own reports the parent's; $seen drops it.
						$add( $variation->get_image_id( 'edit' ), $vid );
					}
				}
			}
		}
		return $targets;
	}

	/** Resolve product ids → unique attachment ids (featured + gallery). */
	public static function ajax_product_images() {
		check_ajax_referer( 'pixelpanda', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelpanda-ai-image-tools' ) ), 403 );
		}
		$ids = isset( $_POST['product_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['product_ids'] ) ) ) ) ) : array();
		$attachments = wp_list_pluck( self::targets_for_products( $ids ), 'attachment_id' );
		wp_send_json_success( array( 'attachment_ids' => array_values( array_unique( $attachments ) ) ) );
	}
}
