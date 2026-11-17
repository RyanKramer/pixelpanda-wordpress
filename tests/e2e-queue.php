<?php
// Local end-to-end test. Run with: wp eval-file. The PixelPanda API is mocked.
$GLOBALS['pp_mock_status'] = 200;
$GLOBALS['pp_api_calls']   = array();
function pp_make_image( $w, $h, $rgb, $type = 'jpg' ) {
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	ob_start();
	'png' === $type ? imagepng( $im ) : imagejpeg( $im, null, 90 );
	return ob_get_clean();
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'pixelpanda.ai/api/v2' ) ) {
		$body = json_decode( $args['body'], true );
		$GLOBALS['pp_api_calls'][] = array( 'path' => substr( $url, strpos( $url, '/api/v2' ) + 7 ), 'body' => array_diff_key( (array) $body, array( 'image_base64' => 1 ) ), 'has_image' => ! empty( $body['image_base64'] ) || ! empty( $body['image_url'] ) );
		if ( 200 !== $GLOBALS['pp_mock_status'] ) {
			return array( 'response' => array( 'code' => $GLOBALS['pp_mock_status'], 'message' => 'x' ), 'body' => wp_json_encode( array( 'detail' => 'Insufficient credits' ) ), 'headers' => array(), 'cookies' => array() );
		}
		$fmt = isset( $body['output_format'] ) ? $body['output_format'] : 'png';
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'status' => 'completed', 'result_url' => 'https://mock.invalid/result.' . $fmt, 'credits_used' => 1, 'credits_remaining' => 99 ) ), 'headers' => array(), 'cookies' => array() );
	}
	if ( false !== strpos( $url, 'mock.invalid/result' ) ) {
		$png = '.png' === substr( $url, -4 );
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => pp_make_image( 640, 480, array( 10, 200, 10 ), $png ? 'png' : 'jpg' ), 'headers' => array(), 'cookies' => array() );
	}
	return $pre;
}, 10, 3 );

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fails++; }
}
function pp_attach( $w, $h, $rgb, $name ) {
	$up = wp_upload_bits( $name, null, pp_make_image( $w, $h, $rgb ) );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $up['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
	return $id;
}
function pp_finish( $run_id ) {
	for ( $i = 0; $i < 200; $i++ ) {
		if ( 'continue' !== PixelPanda_Queue::step( $run_id ) ) { break; }
	}
	return PixelPanda_Queue::get( $run_id );
}
wp_set_current_user( 1 );
update_option( 'pixelpanda_api_key', 'pk_test_local' );

// --- fixtures: a simple product (featured + 2 gallery, one duplicated) and a variable product with a variation image
$a1 = pp_attach( 800, 600, array( 200, 0, 0 ), 'a1.jpg' );
$a2 = pp_attach( 800, 600, array( 0, 0, 200 ), 'a2.jpg' );
$a3 = pp_attach( 800, 600, array( 200, 200, 0 ), 'a3.jpg' );
$big = pp_attach( 3000, 2000, array( 9, 9, 9 ), 'big.jpg' );
$v1 = pp_attach( 800, 600, array( 0, 200, 200 ), 'v1.jpg' );
$simple = new WC_Product_Simple();
$simple->set_name( 'Simple' ); $simple->set_image_id( $a1 ); $simple->set_gallery_image_ids( array( $a1, $a2, $a3 ) ); $simple->save();
$variable = new WC_Product_Variable();
$variable->set_name( 'Variable' ); $variable->set_image_id( $a2 ); $variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() ); $variation->set_image_id( $v1 ); $variation->set_regular_price( '5' ); $variation->save();
$bare = new WC_Product_Variation();
$bare->set_parent_id( $variable->get_id() ); $bare->set_regular_price( '6' ); $bare->save();
WC_Product_Variable::sync( $variable->get_id() );

$targets = PixelPanda_WooCommerce::targets_for_products( array( $simple->get_id(), $variable->get_id() ) );
$ids     = wp_list_pluck( $targets, 'attachment_id' );
ok( $ids === array( $a1, $a2, $a3, $v1 ), 'targets: featured+gallery deduped, variation image included, imageless variation adds nothing (' . implode( ',', $ids ) . ')' );
ok( in_array( $simple->get_id(), PixelPanda_WooCommerce::all_product_ids(), true ) && ! in_array( $variation->get_id(), PixelPanda_WooCommerce::all_product_ids(), true ), 'all_product_ids lists products, not variations' );

// --- replace mode + undo
$orig_hash = md5_file( get_attached_file( $a1 ) );
$run = PixelPanda_Queue::create( 'sharpen', 'replace', $targets );
ok( ! is_wp_error( $run ) && 4 === $run['total'] && 'running' === $run['status'], 'run created with 4 images' );
ok( (bool) ( function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( PixelPanda_Queue::HOOK, array( $run['id'] ), 'pixelpanda' ) : wp_next_scheduled( PixelPanda_Queue::HOOK, array( $run['id'] ) ) ), 'background tick scheduled (' . ( function_exists( 'as_next_scheduled_action' ) ? 'Action Scheduler' : 'WP-Cron' ) . ')' );
$run = pp_finish( $run['id'] );
ok( 'done' === $run['status'] && 4 === $run['done'] && 0 === $run['failed'] && 4 === $run['credits'], 'replace run finished: 4 done, 4 credits' );
ok( md5_file( get_attached_file( $a1 ) ) !== $orig_hash, 'featured image file was replaced' );
ok( (int) $simple->get_image_id() === $a1 && wc_get_product( $simple->get_id() )->get_gallery_image_ids() == array( $a1, $a2, $a3 ), 'product still points at the same attachments' );
ok( '' !== PixelPanda_Processor::backup_path_for( $a1 ) && is_readable( PixelPanda_Processor::backup_path_for( $a1 ) ), 'original backed up' );
$call = $GLOBALS['pp_api_calls'][0];
ok( '/enhance' === $call['path'] && 'faithful' === $call['body']['mode'] && 'jpg' === $call['body']['output_format'] && $call['has_image'], 'API called with mode=faithful, output_format=jpg' );
ok( 'image/jpeg' === get_post_mime_type( $a1 ) && '.jpg' === substr( get_attached_file( $a1 ), -4 ), 'replaced file is still a JPEG' );
$left = PixelPanda_Queue::undo( $run['id'], 100 );
ok( 0 === $left && 'undone' === PixelPanda_Queue::get( $run['id'] )['status'], 'undo completed' );
ok( md5_file( get_attached_file( $a1 ) ) === $orig_hash, 'original restored byte-for-byte' );
ok( '' === PixelPanda_Processor::backup_path_for( $a1 ), 'backup cleared after restore' );

// --- gallery mode + undo
$run = PixelPanda_Queue::create( 'sharpen', 'gallery', PixelPanda_WooCommerce::targets_for_products( array( $simple->get_id() ) ) );
$run = pp_finish( $run['id'] );
$items = PixelPanda_Queue::items( $run['id'] );
$gallery = array_map( 'intval', wc_get_product( $simple->get_id() )->get_gallery_image_ids() );
ok( 3 === $run['done'] && 6 === count( $gallery ), 'gallery run: 3 new images added (gallery ' . implode( ',', $gallery ) . ')' );
$pos = array_search( $a2, $gallery, true );
ok( $gallery[ $pos + 1 ] === $items[1]['r'], 'new image sits right after the one it was made from' );
ok( (int) wc_get_product( $simple->get_id() )->get_image_id() === $a1, 'featured image unchanged in gallery mode' );
$created = wp_list_pluck( $items, 'r' );
PixelPanda_Queue::undo( $run['id'], 100 );
ok( array_map( 'intval', wc_get_product( $simple->get_id() )->get_gallery_image_ids() ) === array( $a1, $a2, $a3 ) && null === get_post( $created[0] ), 'gallery undo: gallery back to original, created images deleted' );

// --- upscale skip for an already-large image; PNG format request for upscale of jpg
$GLOBALS['pp_api_calls'] = array();
$run = PixelPanda_Queue::create( 'upscale_2x', 'new', array( array( 'attachment_id' => $big, 'product_id' => 0 ), array( 'attachment_id' => $a3, 'product_id' => 0 ) ) );
$run = pp_finish( $run['id'] );
ok( 1 === $run['skipped'] && 1 === $run['done'] && 1 === $run['credits'] && 1 === count( $GLOBALS['pp_api_calls'] ), 'upscale: 3000px image skipped without an API call, other processed' );
ok( 'jpg' === $GLOBALS['pp_api_calls'][0]['body']['output_format'] && 2 === $GLOBALS['pp_api_calls'][0]['body']['scale'], 'upscale asks for jpg output' );

// --- background removal keeps its own format setting
$GLOBALS['pp_api_calls'] = array();
PixelPanda_Processor::run( $a3, 'remove_background', 'new' );
ok( in_array( $GLOBALS['pp_api_calls'][0]['body']['output_format'], array( 'webp', 'png' ), true ), 'background removal sends ' . $GLOBALS['pp_api_calls'][0]['body']['output_format'] );

// --- out of credits stops the run instead of failing every image
$GLOBALS['pp_mock_status'] = 402;
$run = PixelPanda_Queue::create( 'sharpen', 'new', $targets );
$run = pp_finish( $run['id'] );
ok( 'stopped' === $run['status'] && 1 === $run['failed'] && '' !== $run['message'], 'out of credits: run stopped after 1 failure (' . $run['message'] . ')' );
$GLOBALS['pp_mock_status'] = 200;

// --- cancel, lock, scheduled tick
$run = PixelPanda_Queue::create( 'sharpen', 'new', $targets );
add_option( PixelPanda_Queue::LOCK_PREFIX . $run['id'], time(), '', false );
ok( 'busy' === PixelPanda_Queue::step( $run['id'] ), 'a held lock makes a second worker back off' );
delete_option( PixelPanda_Queue::LOCK_PREFIX . $run['id'] );
PixelPanda_Queue::tick( $run['id'] );
$r = PixelPanda_Queue::get( $run['id'] );
ok( 'done' === $r['status'] && 4 === $r['done'], 'scheduled tick processes the run to the end' );
ok( false === get_option( PixelPanda_Queue::LOCK_PREFIX . $run['id'] ), 'lock released' );
ok( count( PixelPanda_Queue::runs() ) <= PixelPanda_Queue::KEEP_RUNS, 'run history capped' );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
