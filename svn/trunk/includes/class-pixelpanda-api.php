<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin HTTP client for the PixelPanda API v2 (https://pixelpanda.ai/api).
 */
class PixelPanda_API {

	public static function api_key() {
		return trim( (string) get_option( 'pixelpanda_api_key', '' ) );
	}

	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * GET /account — validates the key; returns ['email','credits'] or WP_Error.
	 */
	public static function account( $key = null ) {
		return self::request( 'GET', '/account', null, $key );
	}

	/**
	 * Run one processing operation against an image.
	 *
	 * @param string $path  API path, e.g. /upscale.
	 * @param array  $body  Extra body fields (scale, quality, ...).
	 * @param string $image_url Public URL of the image, or '' to force base64.
	 * @param string $file  Local file path, used for base64 when URL is not public.
	 * @return array|WP_Error  ['result_url','credits_used','credits_remaining']
	 */
	public static function process( $path, $body, $image_url, $file ) {
		if ( self::url_is_public( $image_url ) ) {
			$body['image_url'] = $image_url;
		} else {
			if ( ! is_readable( $file ) ) {
				return new WP_Error( 'pixelpanda_file', __( 'Image file could not be read.', 'pixelpanda-ai-image-tools' ) );
			}
			// Local / private sites: upload the bytes instead of a URL the API cannot fetch.
			$body['image_base64'] = base64_encode( file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return self::request( 'POST', $path, $body );
	}

	private static function url_is_public( $url ) {
		if ( empty( $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || 'localhost' === $host || preg_match( '/\.(local|test|localhost)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return (bool) apply_filters( 'pixelpanda_url_is_public', true, $url );
	}

	private static function request( $method, $path, $body = null, $key = null ) {
		$key = null === $key ? self::api_key() : $key;
		if ( '' === $key ) {
			return new WP_Error( 'pixelpanda_no_key', __( 'Add your PixelPanda API key under Settings → PixelPanda.', 'pixelpanda-ai-image-tools' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 180,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'pixelpanda-wordpress/' . PIXELPANDA_VERSION . '; ' . home_url(),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( PIXELPANDA_API_BASE . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : '';
			if ( is_array( $detail ) ) {
				$detail = wp_json_encode( $detail );
			}
			if ( 401 === $code ) {
				$detail = __( 'Invalid API key.', 'pixelpanda-ai-image-tools' );
			} elseif ( 402 === $code ) {
				$detail = __( 'Not enough credits. Top up at pixelpanda.ai/pricing.', 'pixelpanda-ai-image-tools' );
			}
			/* translators: 1: HTTP status, 2: error detail */
			return new WP_Error( 'pixelpanda_http_' . $code, sprintf( __( 'PixelPanda API error %1$d: %2$s', 'pixelpanda-ai-image-tools' ), $code, $detail ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
