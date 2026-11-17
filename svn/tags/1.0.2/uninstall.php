<?php
/**
 * Uninstall cleanup.
 *
 * Options and the plugin's post meta are removed. Processed images are user
 * content and are kept, as are any stored originals in
 * uploads/pixelpanda-ai-image-tools/ — for images that were replaced in place
 * those files are the only remaining copy of the original, so deleting them on
 * uninstall would destroy user content. The folder itself is removed when it
 * holds nothing but our guard files.
 *
 * @package PixelPanda_AI_Image_Tools
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pixelpanda_api_key' );
delete_option( 'pixelpanda_default_mode' );
delete_option( 'pixelpanda_cutout_format' );

delete_post_meta_by_key( '_pixelpanda_backup_file' );
delete_post_meta_by_key( '_pixelpanda_operation' );

$pixelpanda_uploads = wp_upload_dir();
if ( empty( $pixelpanda_uploads['error'] ) && ! empty( $pixelpanda_uploads['basedir'] ) ) {
	$pixelpanda_dir = trailingslashit( $pixelpanda_uploads['basedir'] ) . 'pixelpanda-ai-image-tools';
	if ( is_dir( $pixelpanda_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();

		$pixelpanda_files = glob( $pixelpanda_dir . '/*' );
		$pixelpanda_files = is_array( $pixelpanda_files ) ? $pixelpanda_files : array();
		$pixelpanda_left  = array();
		foreach ( $pixelpanda_files as $pixelpanda_file ) {
			if ( ! in_array( wp_basename( $pixelpanda_file ), array( 'index.php', '.htaccess' ), true ) ) {
				$pixelpanda_left[] = $pixelpanda_file;
			}
		}
		if ( empty( $pixelpanda_left ) && $wp_filesystem ) {
			$wp_filesystem->delete( $pixelpanda_dir, true );
		}
	}
}
