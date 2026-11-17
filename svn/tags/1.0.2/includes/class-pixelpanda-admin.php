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
			'i18n'       => array(
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
			<h2><?php esc_html_e( 'How to use', 'pixelpanda-ai-image-tools' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Media → Library: hover an image and pick a PixelPanda action, or select several and use the Bulk actions menu.', 'pixelpanda-ai-image-tools' ); ?></li>
				<li><?php esc_html_e( 'WooCommerce → Products: select products and choose a PixelPanda bulk action to process every product image (featured + gallery).', 'pixelpanda-ai-image-tools' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Pricing: 1 credit per image for every operation above. Credits never expire.', 'pixelpanda-ai-image-tools' ); ?> <a href="https://pixelpanda.ai/pricing" target="_blank" rel="noopener">pixelpanda.ai/pricing</a></p>
		</div>
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
