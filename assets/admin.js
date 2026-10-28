/**
 * Content Powered > Video SEO page behaviour. Only loaded on that page.
 *
 * video_seo_admin.ajaxUrl and video_seo_admin.nonce are passed in from PHP
 * (see Video_SEO_Admin::enqueue_assets) for wp_ajax_ requests.
 */

/* global video_seo_admin */
( function () {
	'use strict';

	function post( action, data ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', video_seo_admin.nonce );

		Object.keys( data || {} ).forEach( function ( name ) {
			body.append( name, data[ name ] );
		} );

		return fetch( video_seo_admin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );

		return text.replace( /%(\d)\$s|%s/g, function ( match, n ) {
			return String( n ? args[ n - 1 ] : args.shift() );
		} );
	}

	// While the first scan runs or details are being fetched, push it along.
	function tick() {
		var text = document.getElementById( 'vseo-progress-text' );
		var bar = document.querySelector( '#vseo-progress .vseo-progress > span' );

		post( 'video_seo_tick' ).then( function ( result ) {
			var data = result && result.data;

			if ( ! result || ! result.success || ! data ) {
				return;
			}

			if ( data.scanning ) {
				if ( text ) {
					text.textContent = format( video_seo_admin.text.scanning, data.done.toLocaleString(), data.total.toLocaleString() );
				}

				if ( bar ) {
					bar.classList.remove( 'is-indeterminate' );
					bar.style.width = ( data.total ? Math.min( 100, Math.round( data.done / data.total * 100 ) ) : 0 ) + '%';
				}

				window.setTimeout( tick, 400 );
			} else if ( data.due > 0 ) {
				if ( text ) {
					text.textContent = format( video_seo_admin.text.fetching, data.due.toLocaleString() );
				}

				if ( bar ) {
					bar.classList.add( 'is-indeterminate' );
				}

				window.setTimeout( tick, 400 );
			} else {
				if ( text ) {
					text.textContent = video_seo_admin.text.done;
				}

				window.setTimeout( function () {
					window.location.reload();
				}, 600 );
			}
		} ).catch( function () {
			window.setTimeout( tick, 5000 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof video_seo_admin === 'undefined' ) {
			return;
		}

		if ( video_seo_admin.working && document.getElementById( 'vseo-progress' ) ) {
			tick();
		}

		// Copy the sitemap address.
		document.querySelectorAll( '[data-vseo-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = document.querySelector( button.getAttribute( 'data-vseo-copy' ) );
				var label = button.querySelector( 'span' );
				var before = label ? label.textContent : '';

				if ( ! input ) {
					return;
				}

				input.select();

				( navigator.clipboard ? navigator.clipboard.writeText( input.value ) : Promise.resolve( document.execCommand( 'copy' ) ) ).then( function () {
					if ( label ) {
						label.textContent = video_seo_admin.text.copied;
						window.setTimeout( function () {
							label.textContent = before;
						}, 1500 );
					}
				} );
			} );
		} );

		// Test the YouTube API key.
		var test = document.getElementById( 'vseo-test-key' );
		var result = document.getElementById( 'vseo-test-result' );

		if ( test && result ) {
			test.addEventListener( 'click', function () {
				var field = document.getElementById( 'vseo-key-new' );

				test.disabled = true;
				result.className = 'vseo-test-result';
				result.textContent = video_seo_admin.text.testing;

				post( 'video_seo_test_key', { key: field ? field.value.trim() : '' } ).then( function ( response ) {
					var message = response && response.data && response.data.message ? response.data.message : video_seo_admin.text.failed;

					result.className = 'vseo-test-result ' + ( response && response.success ? 'is-good' : 'is-bad' );
					result.textContent = message;
				} ).catch( function () {
					result.className = 'vseo-test-result is-bad';
					result.textContent = video_seo_admin.text.failed;
				} ).then( function () {
					test.disabled = false;
				} );
			} );
		}
	} );
} )();
