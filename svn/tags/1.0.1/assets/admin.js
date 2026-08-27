/* global PixelPanda, jQuery */
( function ( $ ) {
	'use strict';

	function notice( type, html ) {
		var $n = $( '<div class="notice notice-' + type + ' is-dismissible pixelpanda-notice"><p></p></div>' );
		$n.find( 'p' ).html( html );
		$( '.wrap h1' ).first().after( $n );
		return $n;
	}

	function ajax( action, data ) {
		return $.post( PixelPanda.ajax, $.extend( { action: action, nonce: PixelPanda.nonce }, data ) );
	}

	function requireKey() {
		if ( ! PixelPanda.configured ) {
			notice( 'warning', 'PixelPanda needs an API key. <a href="' + PixelPanda.settings + '">Add it here</a>.' );
			return false;
		}
		return true;
	}

	// ---- Single row action --------------------------------------------------
	$( document ).on( 'click', '.pixelpanda-action', function ( e ) {
		e.preventDefault();
		if ( ! requireKey() ) {
			return;
		}
		var $link = $( this ),
			id = $link.data( 'attachment' ),
			op = $link.data( 'operation' ),
			credits = ( PixelPanda.operations[ op ] || { credits: 1 } ).credits,
			original = $link.text();
		if ( ! window.confirm( PixelPanda.i18n.confirm.replace( '%d', credits ) ) ) {
			return;
		}
		$link.text( PixelPanda.i18n.processing );
		ajax( 'pixelpanda_process', { attachment_id: id, operation: op } )
			.done( function ( res ) {
				if ( res.success ) {
					$link.text( PixelPanda.i18n.done );
					var msg = 'PixelPanda: done. <a href="' + res.data.url + '" target="_blank">View image</a>.';
					if ( null !== res.data.credits_remaining ) {
						msg += ' ' + PixelPanda.i18n.credits.replace( '%d', res.data.credits_remaining );
					}
					notice( 'success', msg );
				} else {
					$link.text( original );
					notice( 'error', 'PixelPanda: ' + ( res.data && res.data.message ? res.data.message : PixelPanda.i18n.failed ) );
				}
			} )
			.fail( function () {
				$link.text( original );
				notice( 'error', 'PixelPanda: ' + PixelPanda.i18n.failed );
			} );
	} );

	$( document ).on( 'click', '.pixelpanda-restore', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		ajax( 'pixelpanda_restore', { attachment_id: $link.data( 'attachment' ) } ).done( function ( res ) {
			if ( res.success ) {
				window.location.reload();
			} else {
				notice( 'error', 'PixelPanda: ' + ( res.data && res.data.message ? res.data.message : PixelPanda.i18n.failed ) );
			}
		} );
	} );

	// ---- Bulk queue (sequential, with progress) -----------------------------
	function runQueue( ids, op ) {
		if ( ! requireKey() || ! ids.length ) {
			return;
		}
		var total = ids.length,
			ok = 0,
			failed = 0,
			credits = ( PixelPanda.operations[ op ] || { credits: 1 } ).credits * total;
		if ( ! window.confirm( PixelPanda.i18n.confirm.replace( '%d', credits ) ) ) {
			return;
		}
		var $bar = notice( 'info', '' ),
			$p = $bar.find( 'p' );

		function step() {
			$p.text( 'PixelPanda: ' + ( ok + failed ) + ' / ' + total + ' (' + failed + ' failed)' );
			if ( ! ids.length ) {
				$bar.removeClass( 'notice-info' ).addClass( failed ? 'notice-warning' : 'notice-success' );
				$p.append( ' — ' + PixelPanda.i18n.done );
				return;
			}
			ajax( 'pixelpanda_process', { attachment_id: ids.shift(), operation: op } )
				.done( function ( res ) {
					if ( res.success ) {
						ok++;
					} else {
						failed++;
						if ( res.data && res.data.message && -1 !== res.data.message.indexOf( 'credits' ) ) {
							ids.length = 0; // out of credits: stop the queue
							notice( 'error', 'PixelPanda: ' + res.data.message );
						}
					}
				} )
				.fail( function () {
					failed++;
				} )
				.always( step );
		}
		step();
	}

	$( function () {
		var params = new URLSearchParams( window.location.search ),
			op = params.get( 'pixelpanda_bulk_op' );
		if ( ! op ) {
			return;
		}
		if ( params.get( 'pixelpanda_bulk_ids' ) ) {
			runQueue( params.get( 'pixelpanda_bulk_ids' ).split( ',' ).map( Number ).filter( Boolean ), op );
		} else if ( params.get( 'pixelpanda_bulk_products' ) ) {
			ajax( 'pixelpanda_product_images', { product_ids: params.get( 'pixelpanda_bulk_products' ) } ).done( function ( res ) {
				if ( res.success ) {
					runQueue( res.data.attachment_ids, op );
				}
			} );
		}
		// Clean the URL so a refresh doesn't re-trigger.
		params.delete( 'pixelpanda_bulk_op' );
		params.delete( 'pixelpanda_bulk_ids' );
		params.delete( 'pixelpanda_bulk_products' );
		window.history.replaceState( {}, '', window.location.pathname + ( params.toString() ? '?' + params.toString() : '' ) );
	} );

	// ---- Settings: test key -------------------------------------------------
	$( document ).on( 'click', '#pixelpanda-validate', function () {
		var $out = $( '#pixelpanda-validate-result' );
		$out.text( '…' );
		ajax( 'pixelpanda_validate_key', { key: $( '#pixelpanda_api_key' ).val() } )
			.done( function ( res ) {
				if ( res.success ) {
					$out.css( 'color', 'green' ).text( '✓ ' + res.data.email + ' — ' + res.data.credits + ' credits' );
				} else {
					$out.css( 'color', 'red' ).text( '✗ ' + ( res.data && res.data.message ? res.data.message : 'Invalid key' ) );
				}
			} )
			.fail( function () {
				$out.css( 'color', 'red' ).text( '✗ request failed' );
			} );
	} );
} )( jQuery );
