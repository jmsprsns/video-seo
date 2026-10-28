/**
 * Video SEO on the front end.
 *
 * - Click-to-play previews: swaps the thumbnail for the real player (kept in
 *   a <template>) when it's clicked, and starts it playing.
 * - Key moments: a link from search results like ?t=95 (or ?vseo_video=2&t=95
 *   for a page's second video) starts that video at 1:35.
 */
( function () {
	'use strict';

	function withParams( src, params ) {
		var url;

		try {
			url = new URL( src, window.location.href );
		} catch ( e ) {
			return src;
		}

		Object.keys( params ).forEach( function ( name ) {
			if ( 'vimeo-t' === name ) {
				url.hash = 't=' + params[ name ] + 's';
			} else {
				url.searchParams.set( name, params[ name ] );
			}
		} );

		return url.toString();
	}

	function startParams( src, seconds, autoplay ) {
		var params = {};
		var vimeo = /vimeo\.com/i.test( src );

		if ( autoplay ) {
			params.autoplay = '1';
		}

		if ( seconds > 0 ) {
			if ( vimeo ) {
				params[ 'vimeo-t' ] = seconds;
			} else {
				params.start = seconds;
			}
		}

		return params;
	}

	function play( facade ) {
		var template = facade.querySelector( 'template.vseo-facade-player' );
		var holder;
		var player;
		var seconds;

		if ( ! template ) {
			return;
		}

		holder = document.createElement( 'div' );
		holder.innerHTML = template.innerHTML;
		player = holder.querySelector( 'iframe' );

		if ( ! player ) {
			return;
		}

		seconds = parseInt( facade.getAttribute( 'data-vseo-start' ) || '0', 10 ) || 0;
		player.setAttribute( 'src', withParams( player.getAttribute( 'src' ), startParams( player.getAttribute( 'src' ), seconds, true ) ) );

		if ( ! /autoplay/.test( player.getAttribute( 'allow' ) || '' ) ) {
			player.setAttribute( 'allow', ( ( player.getAttribute( 'allow' ) || '' ) + '; autoplay' ).replace( /^; /, '' ) );
		}

		if ( facade.hasAttribute( 'data-vseo-index' ) ) {
			player.setAttribute( 'data-vseo-index', facade.getAttribute( 'data-vseo-index' ) );
		}

		facade.parentNode.replaceChild( player, facade );
		player.focus();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '.vseo-facade-play' ) : null;

		if ( button && button.parentNode ) {
			event.preventDefault();
			play( button.parentNode );
		}
	} );

	function seek() {
		var params = new URLSearchParams( window.location.search );
		var seconds = parseInt( params.get( 't' ) || '', 10 );
		var position = parseInt( params.get( 'vseo_video' ) || '1', 10 );
		var target;

		if ( ! ( seconds >= 0 ) || ! ( position >= 1 ) ) {
			return;
		}

		target = document.querySelector( '[data-vseo-index="' + ( position - 1 ) + '"]' );

		if ( ! target ) {
			return;
		}

		if ( target.classList.contains( 'vseo-facade' ) ) {
			target.setAttribute( 'data-vseo-start', String( seconds ) );
		} else if ( 'IFRAME' === target.tagName ) {
			target.setAttribute( 'src', withParams( target.getAttribute( 'src' ), startParams( target.getAttribute( 'src' ), seconds, false ) ) );
		} else if ( 'VIDEO' === target.tagName ) {
			if ( target.readyState >= 1 ) {
				target.currentTime = seconds;
			} else {
				target.addEventListener( 'loadedmetadata', function () {
					target.currentTime = seconds;
				}, { once: true } );
				target.preload = 'metadata';
			}
		}

		if ( target.scrollIntoView ) {
			target.scrollIntoView( { block: 'center' } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', seek );
	} else {
		seek();
	}
} )();
