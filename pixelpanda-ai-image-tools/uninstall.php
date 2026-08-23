<?php
// Clean up options on uninstall. Processed images are user content and are kept.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'pixelpanda_api_key' );
delete_option( 'pixelpanda_default_mode' );
