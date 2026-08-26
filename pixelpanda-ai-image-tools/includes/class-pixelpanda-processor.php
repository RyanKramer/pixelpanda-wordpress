<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an operation on a Media Library attachment and stores the result
 * as a new attachment (default) or replaces the original file in place.
 */
class PixelPanda_Processor {

	/**
	 * @param int    $attachment_id
	 * @param string $operation  Key from pixelpanda_operations().
	 * @param string $mode       'new' (new attachment) or 'replace' (overwrite original).
	 * @return array|WP_Error  ['attachment_id','url','credits_used','credits_remaining']
	 */
	public static function run( $attachment_id, $operation, $mode = null ) {
		$ops = pixelpanda_operations();
		if ( ! isset( $ops[ $operation ] ) ) {
			return new WP_Error( 'pixelpanda_op', __( 'Unknown operation.', 'pixelpanda-ai-image-tools' ) );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'pixelpanda_not_image', __( 'Attachment is not an image.', 'pixelpanda-ai-image-tools' ) );
		}
		$mode = $mode ? $mode : get_option( 'pixelpanda_default_mode', 'new' );
		$op   = $ops[ $operation ];
		$file = get_attached_file( $attachment_id );
		$url  = wp_get_attachment_url( $attachment_id );

		$result = PixelPanda_API::process( $op['path'], $op['body'], $url, $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['result_url'] ) ) {
			return new WP_Error( 'pixelpanda_empty', __( 'The API returned no image.', 'pixelpanda-ai-image-tools' ) );
		}

		$bytes = self::download( $result['result_url'] );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$stored = ( 'replace' === $mode )
			? self::replace_original( $attachment_id, $bytes, $operation )
			: self::create_attachment( $attachment_id, $bytes, $operation, $op['label'] );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		update_post_meta( $stored, '_pixelpanda_operation', sanitize_key( $operation ) );
		update_post_meta( $stored, '_pixelpanda_source_id', (int) $attachment_id );
		update_post_meta( $stored, '_pixelpanda_processed_at', time() );

		return array(
			'attachment_id'     => $stored,
			'url'               => wp_get_attachment_url( $stored ),
			'credits_used'      => isset( $result['credits_used'] ) ? (int) $result['credits_used'] : $op['credits'],
			'credits_remaining' => isset( $result['credits_remaining'] ) ? (int) $result['credits_remaining'] : null,
		);
	}

	private static function download( $url ) {
		$resp = wp_remote_get( $url, array( 'timeout' => 120 ) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return new WP_Error( 'pixelpanda_download', __( 'Could not download the processed image.', 'pixelpanda-ai-image-tools' ) );
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return new WP_Error( 'pixelpanda_download', __( 'Processed image was empty.', 'pixelpanda-ai-image-tools' ) );
		}
		return $body;
	}

	private static function extension_for( $bytes, $fallback ) {
		$info = getimagesizefromstring( $bytes );
		if ( $info && ! empty( $info['mime'] ) ) {
			$map = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif' );
			if ( isset( $map[ $info['mime'] ] ) ) {
				return $map[ $info['mime'] ];
			}
		}
		return $fallback;
	}

	private static function create_attachment( $source_id, $bytes, $operation, $label ) {
		$src_file = get_attached_file( $source_id );
		$base     = pathinfo( $src_file, PATHINFO_FILENAME );
		$ext      = self::extension_for( $bytes, pathinfo( $src_file, PATHINFO_EXTENSION ) );
		$suffix   = str_replace( '_', '-', $operation );
		$upload   = wp_upload_bits( sanitize_file_name( "{$base}-{$suffix}.{$ext}" ), null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'pixelpanda_upload', $upload['error'] );
		}
		$source = get_post( $source_id );
		$id     = wp_insert_attachment( array(
			'post_mime_type' => $upload['type'],
			/* translators: 1: original image title, 2: operation label */
			'post_title'     => sprintf( __( '%1$s (%2$s)', 'pixelpanda-ai-image-tools' ), $source ? $source->post_title : $base, $label ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $source ? (int) $source->post_parent : 0,
		), $upload['file'] );
		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new WP_Error( 'pixelpanda_insert', __( 'Could not create attachment.', 'pixelpanda-ai-image-tools' ) );
		}
		$alt = get_post_meta( $source_id, '_wp_attachment_image_alt', true );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return $id;
	}

	/**
	 * Folder that holds pre-processing originals.
	 *
	 * Lives inside the uploads directory, resolved at runtime with wp_upload_dir()
	 * (never a hard-coded path), and is protected from direct access.
	 *
	 * @return string|WP_Error Absolute path without a trailing slash, or WP_Error.
	 */
	private static function backup_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'pixelpanda_uploads_dir', __( 'The uploads directory is not available.', 'pixelpanda-ai-image-tools' ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'pixelpanda-ai-image-tools';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'pixelpanda_uploads_dir', __( 'Could not create the backup folder inside the uploads directory.', 'pixelpanda-ai-image-tools' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();
		if ( $wp_filesystem ) {
			if ( ! $wp_filesystem->exists( $dir . '/index.php' ) ) {
				$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}
			if ( ! $wp_filesystem->exists( $dir . '/.htaccess' ) ) {
				$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
			}
		}
		return $dir;
	}

	/**
	 * Absolute path of the stored original for an attachment, or '' when there is none.
	 *
	 * Only the file name is kept in post meta and it is always resolved inside our
	 * own uploads folder, so a stored value can never point elsewhere on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function backup_path( $attachment_id ) {
		$name = get_post_meta( $attachment_id, '_pixelpanda_backup_file', true );
		if ( ! $name ) {
			return '';
		}
		$dir = self::backup_dir();
		if ( is_wp_error( $dir ) ) {
			return '';
		}
		return $dir . '/' . wp_basename( $name );
	}

	private static function replace_original( $attachment_id, $bytes, $operation ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! wp_is_writable( dirname( $file ) ) ) {
			return new WP_Error( 'pixelpanda_replace', __( 'Original file is not writable.', 'pixelpanda-ai-image-tools' ) );
		}
		// Background removal yields PNG with alpha; keep the extension honest so browsers render it.
		$ext = self::extension_for( $bytes, pathinfo( $file, PATHINFO_EXTENSION ) );
		$new = preg_replace( '/\.[^.]+$/', '.' . $ext, $file );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();

		// Keep one backup of the original, in our own uploads folder, so the action is reversible.
		if ( ! get_post_meta( $attachment_id, '_pixelpanda_backup_file', true ) ) {
			$backup_dir = self::backup_dir();
			if ( ! is_wp_error( $backup_dir ) ) {
				$name   = wp_unique_filename( $backup_dir, $attachment_id . '-' . wp_basename( $file ) );
				$backup = trailingslashit( $backup_dir ) . $name;
				if ( $wp_filesystem->copy( $file, $backup, true, FS_CHMOD_FILE ) ) {
					update_post_meta( $attachment_id, '_pixelpanda_backup_file', $name );
				}
			}
		}

		if ( ! $wp_filesystem->put_contents( $new, $bytes, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'pixelpanda_write', __( 'Could not write the processed image.', 'pixelpanda-ai-image-tools' ) );
		}
		if ( $new !== $file ) {
			wp_delete_file( $file );
			update_attached_file( $attachment_id, $new );
			$mime = wp_check_filetype( $new );
			wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $mime['type'] ) );
		}
		// Purge old intermediate sizes and regenerate.
		$old_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $old_meta['sizes'] ) ) {
			$dir = dirname( $new );
			foreach ( $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					wp_delete_file( $dir . '/' . $size['file'] );
				}
			}
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $new ) );
		clean_attachment_cache( $attachment_id );
		return $attachment_id;
	}

	/** Restore the pre-processing original (replace mode only). */
	public static function restore( $attachment_id ) {
		$backup = self::backup_path( $attachment_id );
		if ( ! $backup || ! is_readable( $backup ) ) {
			return new WP_Error( 'pixelpanda_no_backup', __( 'No original backup found.', 'pixelpanda-ai-image-tools' ) );
		}
		$bytes = file_get_contents( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$res   = self::replace_original( $attachment_id, $bytes, 'restore' );
		if ( ! is_wp_error( $res ) ) {
			wp_delete_file( $backup );
			delete_post_meta( $attachment_id, '_pixelpanda_backup_file' );
			delete_post_meta( $attachment_id, '_pixelpanda_operation' );
		}
		return $res;
	}
}
