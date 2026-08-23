<?php
/**
 * Plugin Name:       PixelPanda AI Image Tools
 * Plugin URI:        https://pixelpanda.ai/wordpress
 * Description:       AI background removal, upscaling, enhancement and text removal for your Media Library and WooCommerce product images.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PixelPanda
 * Author URI:        https://pixelpanda.ai
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixelpanda-ai-image-tools
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIXELPANDA_VERSION', '1.0.0' );
define( 'PIXELPANDA_FILE', __FILE__ );
define( 'PIXELPANDA_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELPANDA_URL', plugin_dir_url( __FILE__ ) );
define( 'PIXELPANDA_API_BASE', 'https://pixelpanda.ai/api/v2' );

require_once PIXELPANDA_DIR . 'includes/class-pixelpanda-api.php';
require_once PIXELPANDA_DIR . 'includes/class-pixelpanda-processor.php';
require_once PIXELPANDA_DIR . 'includes/class-pixelpanda-admin.php';
require_once PIXELPANDA_DIR . 'includes/class-pixelpanda-media.php';
require_once PIXELPANDA_DIR . 'includes/class-pixelpanda-woocommerce.php';

/**
 * The operations exposed to users. Key => [label, credits, api path, extra body].
 */
function pixelpanda_operations() {
	return array(
		'remove_background' => array(
			'label'   => __( 'Remove background', 'pixelpanda-ai-image-tools' ),
			'credits' => 1,
			'path'    => '/remove-background',
			'body'    => array(),
		),
		'upscale_2x'        => array(
			'label'   => __( 'Upscale 2x', 'pixelpanda-ai-image-tools' ),
			'credits' => 1,
			'path'    => '/upscale',
			'body'    => array( 'scale' => 2, 'quality' => 'fast' ),
		),
		'upscale_4x'        => array(
			'label'   => __( 'Upscale 4x', 'pixelpanda-ai-image-tools' ),
			'credits' => 1,
			'path'    => '/upscale',
			'body'    => array( 'scale' => 4, 'quality' => 'balanced' ),
		),
		'enhance'           => array(
			'label'   => __( 'AI enhance', 'pixelpanda-ai-image-tools' ),
			'credits' => 1,
			'path'    => '/enhance',
			'body'    => array(),
		),
		'remove_text'       => array(
			'label'   => __( 'Remove text & watermarks', 'pixelpanda-ai-image-tools' ),
			'credits' => 1,
			'path'    => '/remove-text',
			'body'    => array(),
		),
	);
}

add_action( 'plugins_loaded', function () {
	PixelPanda_Admin::init();
	PixelPanda_Media::init();
	if ( class_exists( 'WooCommerce' ) ) {
		PixelPanda_WooCommerce::init();
	}
} );

// Declare WooCommerce HPOS compatibility (we never touch order storage).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
