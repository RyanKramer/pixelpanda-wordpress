<?php
// Local test only: fake the PixelPanda API so the admin UI can be exercised without spending credits.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'pixelpanda.ai/api/v2' ) ) {
		if ( false !== strpos( $url, '/account' ) ) {
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'email' => 'local@test', 'credits' => 99 ) ), 'headers' => array(), 'cookies' => array() );
		}
		sleep( 2 );
		$body = json_decode( $args['body'], true );
		$fmt  = isset( $body['output_format'] ) ? $body['output_format'] : 'png';
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'status' => 'completed', 'result_url' => 'https://mock.invalid/result.' . $fmt, 'credits_used' => 1, 'credits_remaining' => 99 ) ), 'headers' => array(), 'cookies' => array() );
	}
	if ( false !== strpos( $url, 'mock.invalid/result' ) ) {
		$im = imagecreatetruecolor( 640, 480 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 20, 170, 60 ) );
		imagestring( $im, 5, 240, 230, 'PROCESSED', imagecolorallocate( $im, 255, 255, 255 ) );
		ob_start();
		'.png' === substr( $url, -4 ) ? imagepng( $im ) : imagejpeg( $im, null, 90 );
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => ob_get_clean(), 'headers' => array(), 'cookies' => array() );
	}
	return $pre;
}, 10, 3 );
