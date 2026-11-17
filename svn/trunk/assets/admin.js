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

	// ---- Bulk jobs: choose what happens to the results, then run in the background ----
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return str.replace( /%(\d+)\$d|%d/g, function ( m, n ) {
			return n ? args[ parseInt( n, 10 ) - 1 ] : args.shift();
		} );
	}

	function esc( text ) {
		return $( '<div>' ).text( text ).html();
	}

	// Ask where results should go before spending anything. `selection` is one of
	// { attachment_ids }, { product_ids } or { all_products: 1 }.
	function chooseAndStart( op, selection ) {
		if ( ! requireKey() ) {
			return;
		}
		var opInfo = PixelPanda.operations[ op ];
		if ( ! opInfo ) {
			return;
		}
		var forProducts = !! ( selection.product_ids || selection.all_products ),
			i18n = PixelPanda.i18n,
			modes = forProducts
				? [ [ 'replace', i18n.modeReplace, i18n.modeReplaceD ], [ 'gallery', i18n.modeGallery, i18n.modeGalleryD ], [ 'new', i18n.modeNew, i18n.modeNewD ] ]
				: [ [ 'new', i18n.modeNew, i18n.modeNewD ], [ 'replace', i18n.modeReplace, i18n.modeReplaceD ] ],
			// Products: default to replacing, because "save to the Media Library" leaves the
			// store looking exactly as it did and reads as "nothing happened".
			preferred = forProducts ? 'replace' : ( 'replace' === PixelPanda.defaultMode ? 'replace' : 'new' ),
			$back = $( '<div class="pixelpanda-modal-backdrop"><div class="pixelpanda-modal" role="dialog" aria-modal="true"></div></div>' ),
			$box = $back.find( '.pixelpanda-modal' );

		$box.append( '<h2>' + esc( i18n.chooseTitle ) + '</h2><p class="pixelpanda-modal-intro">' + esc( i18n.counting ) + '</p>' );
		$.each( modes, function ( _, m ) {
			$box.append(
				'<label' + ( m[ 0 ] === preferred ? ' class="is-checked"' : '' ) + '><input type="radio" name="pixelpanda_run_mode" value="' + m[ 0 ] + '"' +
				( m[ 0 ] === preferred ? ' checked' : '' ) + ' /> <strong>' + esc( m[ 1 ] ) + '</strong><small>' + esc( m[ 2 ] ) + '</small></label>'
			);
		} );
		$box.append(
			'<div class="pixelpanda-modal-actions"><button type="button" class="button pixelpanda-modal-cancel">' + esc( i18n.cancel ) +
			'</button><button type="button" class="button button-primary pixelpanda-modal-start" disabled>' + esc( i18n.start ) + '</button></div>'
		);
		$( 'body' ).append( $back );

		$box.on( 'change', 'input[type=radio]', function () {
			$box.find( 'label' ).removeClass( 'is-checked' );
			$( this ).closest( 'label' ).addClass( 'is-checked' );
		} );
		$back.on( 'click', '.pixelpanda-modal-cancel', function () {
			$back.remove();
		} );

		ajax( 'pixelpanda_run_preview', selection ).done( function ( res ) {
			var count = res.success ? res.data.images : 0;
			if ( ! count ) {
				$box.find( '.pixelpanda-modal-intro' ).text( i18n.noImages );
				return;
			}
			$box.find( '.pixelpanda-modal-intro' ).html(
				esc( i18n.chooseIntro.replace( '%1$s', opInfo.label ).replace( '%2$d', count ) ) + '<br />' +
				esc( fmt( i18n.chooseCost, count * opInfo.credits ) )
			);
			$box.find( '.pixelpanda-modal-start' ).prop( 'disabled', false );
		} );

		$back.on( 'click', '.pixelpanda-modal-start', function () {
			var mode = $box.find( 'input[type=radio]:checked' ).val();
			$( this ).prop( 'disabled', true );
			ajax( 'pixelpanda_run_create', $.extend( { operation: op, mode: mode }, selection ) ).done( function ( res ) {
				$back.remove();
				if ( ! res.success ) {
					notice( 'error', 'PixelPanda: ' + esc( res.data && res.data.message ? res.data.message : i18n.failed ) );
					return;
				}
				driveRun( res.data );
			} ).fail( function () {
				$back.remove();
				notice( 'error', 'PixelPanda: ' + esc( i18n.failed ) );
			} );
		} );
	}

	// Keep a run moving while this page is open, and show its progress. The server
	// carries on by itself if the page is closed.
	function driveRun( run ) {
		var i18n = PixelPanda.i18n,
			$bar = notice( 'info', '' ),
			$p = $bar.find( 'p' ),
			stopped = false;

		function paint( r ) {
			var running = 'running' === r.status,
				text = running
					? fmt( i18n.progress, r.done, r.total, r.failed, r.skipped )
					: fmt( i18n.finished, r.done, r.failed, r.skipped ) + ( r.message ? ' ' + r.message : '' );
			$p.html(
				esc( text ) + ' <a href="' + r.url + '">' + esc( i18n.review ) + '</a>' +
				( running ? ' <button type="button" class="button button-small pixelpanda-run-stop-inline">' + esc( i18n.stopJob ) + '</button>' : '' )
			);
			if ( ! running ) {
				$bar.removeClass( 'notice-info' ).addClass( r.failed || 'done' !== r.status ? 'notice-warning' : 'notice-success' );
			}
			syncRows( r );
		}

		function next() {
			if ( stopped ) {
				return;
			}
			ajax( 'pixelpanda_run_drive', { run: run.id } ).done( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				paint( res.data );
				if ( 'running' === res.data.status ) {
					// 'busy' = the scheduled worker has this image; check back shortly instead of hammering.
					window.setTimeout( next, 'busy' === res.data.state ? 4000 : 200 );
				}
			} ).fail( function () {
				window.setTimeout( next, 8000 ); // A timeout on one image must not strand the page; the server keeps going.
			} );
		}

		$bar.on( 'click', '.pixelpanda-run-stop-inline', function () {
			stopped = true;
			ajax( 'pixelpanda_run_cancel', { run: run.id } ).done( function ( res ) {
				if ( res.success ) {
					paint( res.data );
				}
			} );
		} );

		paint( run );
		next();
	}

	// Reflect progress in the jobs table / job page when they are on screen.
	function syncRows( r ) {
		var $row = $( '[data-run="' + r.id + '"]' );
		if ( ! $row.length ) {
			return;
		}
		$row.attr( 'data-status', r.status );
		$row.find( '.pixelpanda-run-progress' ).text( r.done + ' / ' + r.total + ' (' + r.failed + ' failed, ' + r.skipped + ' skipped)' );
		$row.find( '.pixelpanda-run-credits' ).text( r.credits );
	}

	$( function () {
		var params = new URLSearchParams( window.location.search ),
			op = params.get( 'pixelpanda_bulk_op' );
		if ( op ) {
			if ( params.get( 'pixelpanda_bulk_ids' ) ) {
				chooseAndStart( op, { attachment_ids: params.get( 'pixelpanda_bulk_ids' ) } );
			} else if ( params.get( 'pixelpanda_bulk_products' ) ) {
				chooseAndStart( op, { product_ids: params.get( 'pixelpanda_bulk_products' ) } );
			}
			// Clean the URL so a refresh doesn't re-trigger.
			params.delete( 'pixelpanda_bulk_op' );
			params.delete( 'pixelpanda_bulk_ids' );
			params.delete( 'pixelpanda_bulk_products' );
			window.history.replaceState( {}, '', window.location.pathname + ( params.toString() ? '?' + params.toString() : '' ) );
		}

		// A job still running when this page loads: pick it back up.
		$( '.pixelpanda-runs tr[data-status="running"], .pixelpanda-run-summary[data-status="running"]' ).first().each( function () {
			ajax( 'pixelpanda_run_status', { run: $( this ).data( 'run' ) } ).done( function ( res ) {
				if ( res.success && 'running' === res.data.status ) {
					driveRun( res.data );
				}
			} );
		} );
	} );

	// Whole catalogue launcher (settings page).
	$( document ).on( 'click', '#pixelpanda-catalogue-start', function () {
		chooseAndStart( $( '#pixelpanda-catalogue-op' ).val(), { all_products: 1 } );
	} );

	// Job page: stop / undo.
	$( document ).on( 'click', '.pixelpanda-run-stop', function () {
		ajax( 'pixelpanda_run_cancel', { run: $( this ).data( 'run' ) } ).done( function () {
			window.location.reload();
		} );
	} );

	$( document ).on( 'click', '.pixelpanda-run-undo', function () {
		var $btn = $( this ),
			id = $btn.data( 'run' ),
			i18n = PixelPanda.i18n;
		if ( ! window.confirm( i18n.undoConfirm ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		( function step() {
			ajax( 'pixelpanda_run_undo', { run: id } ).done( function ( res ) {
				if ( res.success && res.data.left > 0 ) {
					$btn.text( fmt( i18n.undoing, res.data.left ) );
					step();
				} else {
					window.location.reload();
				}
			} ).fail( function () {
				window.location.reload();
			} );
		}() );
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
