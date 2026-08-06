/**
 * Front end player.
 *
 * One audio element for the whole page, one sticky bar, any number of embedded
 * lists. No framework and no jQuery.
 *
 * @package PL_Player
 */

( function () {
	'use strict';

	var audio = new Audio();
	audio.preload = 'none';

	var currentItem = null;
	var currentList = null;
	var shuffle = false;
	var repeat = false;
	var counted = {};
	var bar = null;
	var els = {};

	/* ------------------------------------------------------------------
	 * REST access
	 * --------------------------------------------------------------- */

	/**
	 * Some servers block the pretty /wp-json/ path at the web server level, before
	 * WordPress ever runs. When that happens the request comes back as a 403 with an
	 * HTML body, so we switch to the ?rest_route= form — which WordPress always
	 * accepts — and stay on it for the rest of the page.
	 */
	var useFallbackRoute = false;

	try {
		// Remembered for the session so later page loads skip the doomed first attempt.
		useFallbackRoute = '1' === sessionStorage.getItem( 'plp_route_fallback' );
	} catch ( error ) {}

	function toQuery( params ) {
		var search = new URLSearchParams();

		Object.keys( params || {} ).forEach( function ( key ) {
			var value = params[ key ];

			if ( value !== '' && value !== null && value !== undefined ) {
				search.set( key, value );
			}
		} );

		return search.toString();
	}

	function endpoint( path, params ) {
		var query = toQuery( params );

		if ( useFallbackRoute ) {
			return PLPlayer.restFallback + path + ( query ? '&' + query : '' );
		}

		return PLPlayer.rest + path + ( query ? '?' + query : '' );
	}

	function send( path, params, options ) {
		options = options || {};

		var headers = {};

		if ( PLPlayer.nonce ) {
			headers['X-WP-Nonce'] = PLPlayer.nonce;
		}

		return fetch( endpoint( path, params ), {
			method: options.method || 'GET',
			headers: headers,
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.text().then( function ( text ) {
				var data = null;

				try {
					data = JSON.parse( text );
				} catch ( error ) {
					// An HTML body here means something in front of WordPress answered.
					throw { blocked: true, status: response.status };
				}

				if ( ! response.ok ) {
					throw data;
				}

				return data;
			} );
		} );
	}

	/**
	 * Runs a request, retrying once on the alternative route form.
	 */
	function request( path, params, options ) {
		return send( path, params, options ).catch( function ( error ) {
			var worthRetrying = ! useFallbackRoute && ( error.blocked || 403 === error.status );

			if ( ! worthRetrying || ! PLPlayer.restFallback ) {
				throw error;
			}

			useFallbackRoute = true;

			try {
				sessionStorage.setItem( 'plp_route_fallback', '1' );
			} catch ( storageError ) {}

			return send( path, params, options );
		} );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	function q( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qa( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	function formatTime( seconds ) {
		if ( ! isFinite( seconds ) || seconds < 0 ) {
			return '0:00';
		}

		var total = Math.floor( seconds );
		var hours = Math.floor( total / 3600 );
		var minutes = Math.floor( ( total % 3600 ) / 60 );
		var rest = total % 60;
		var pad = rest < 10 ? '0' : '';

		if ( hours ) {
			return hours + ':' + ( minutes < 10 ? '0' : '' ) + minutes + ':' + pad + rest;
		}

		return minutes + ':' + pad + rest;
	}

	function config( list ) {
		if ( ! list.plpConfig ) {
			try {
				list.plpConfig = JSON.parse( list.getAttribute( 'data-plp' ) ) || {};
			} catch ( error ) {
				list.plpConfig = {};
			}
		}

		return list.plpConfig;
	}

	function announce( list, message ) {
		var status = q( '.plp-status', list );

		if ( status ) {
			status.textContent = message || '';
		}
	}

	function setIcon( element, name ) {
		var icon = q( '.plp-icon', element );

		if ( icon ) {
			icon.className = 'plp-icon plp-icon--' + name;
		}
	}

	/* ------------------------------------------------------------------
	 * Counters
	 * --------------------------------------------------------------- */

	function paintLike( item, liked, count ) {
		var button = q( '.plp-like', item );
		var value = q( '.plp-like__count', item );

		if ( button ) {
			button.setAttribute( 'aria-pressed', liked ? 'true' : 'false' );
			button.setAttribute( 'aria-label', liked ? PLPlayer.i18n.unlike : PLPlayer.i18n.like );
		}

		if ( value && typeof count === 'number' ) {
			value.textContent = count > 0 ? count : '';
		}

		if ( item === currentItem ) {
			syncHeroStats( item, heroOf( currentList ) );
		}
	}

	function paintPlays( item, count ) {
		var value = q( '.plp-plays__count', item );

		if ( value && typeof count === 'number' ) {
			value.textContent = count > 0 ? count : '';
		}

		if ( item === currentItem ) {
			syncHeroStats( item, heroOf( currentList ) );
		}
	}

	function loadCounters( list ) {
		var items = qa( '.plp-track', list );

		if ( ! items.length ) {
			return;
		}

		var ids = items.map( function ( item ) {
			return item.getAttribute( 'data-id' );
		} );

		request( '/counters', { ids: ids.join( ',' ) } ).then( function ( data ) {
			var counters = data.counters || {};

			items.forEach( function ( item ) {
				var entry = counters[ item.getAttribute( 'data-id' ) ];

				if ( ! entry ) {
					return;
				}

				paintLike( item, entry.liked, entry.likes );
				paintPlays( item, entry.plays );
			} );
		} ).catch( function () {
			// Counters are decoration: a failure here must not break playback.
		} );
	}

	/* ------------------------------------------------------------------
	 * Likes
	 * --------------------------------------------------------------- */

	function toggleLike( item, list ) {
		var button = q( '.plp-like', item );

		if ( ! button || button.disabled ) {
			return;
		}

		button.disabled = true;

		request( '/tracks/' + item.getAttribute( 'data-id' ) + '/like', null, { method: 'POST' } )
			.then( function ( data ) {
				paintLike( item, data.liked, data.likes );
				announce( list, '' );
			} )
			.catch( function ( error ) {
				announce( list, ( error && error.message ) || PLPlayer.i18n.error );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	/* ------------------------------------------------------------------
	 * Playback
	 * --------------------------------------------------------------- */

	function queueOf( list ) {
		return qa( '.plp-track', list ).filter( function ( item ) {
			return item.getAttribute( 'data-audio' );
		} );
	}

	function select( item, list, autoplay ) {
		if ( ! item || ! item.getAttribute( 'data-audio' ) ) {
			return;
		}

		if ( currentItem && currentItem !== item ) {
			currentItem.classList.remove( 'is-current' );
			setIcon( q( '.plp-track__play', currentItem ), 'play' );
		}

		currentItem = item;
		currentList = list;
		item.classList.add( 'is-current' );

		if ( audio.src !== item.getAttribute( 'data-audio' ) ) {
			audio.src = item.getAttribute( 'data-audio' );
			audio.load();
		}

		showBar();
		paintBar( item );
		paintHero( item, list );
		setMediaSession( item );

		if ( autoplay ) {
			audio.play().catch( function () {
				// Autoplay refused — the visible controls are the way back in.
				paintPlaying( false );
			} );
		}
	}

	function toggle() {
		if ( ! currentItem ) {
			return;
		}

		if ( audio.paused ) {
			audio.play().catch( function () {} );
		} else {
			audio.pause();
		}
	}

	function step( direction ) {
		if ( ! currentList ) {
			return;
		}

		var queue = queueOf( currentList );

		if ( ! queue.length ) {
			return;
		}

		var position = queue.indexOf( currentItem );
		var next;

		if ( shuffle && queue.length > 1 ) {
			do {
				next = Math.floor( Math.random() * queue.length );
			} while ( next === position );
		} else {
			next = position + direction;

			if ( next < 0 ) {
				next = queue.length - 1;
			}

			if ( next >= queue.length ) {
				next = 0;
			}
		}

		select( queue[ next ], currentList, true );
	}

	/**
	 * Reports a play once the visitor has actually listened for a while.
	 *
	 * The threshold has to be judged in the browser — the server cannot see how much
	 * audio reached the speakers. What the server does enforce is the cooldown, so a
	 * forged report still cannot inflate the number.
	 */
	function maybeCountPlay() {
		if ( ! currentItem ) {
			return;
		}

		var id = currentItem.getAttribute( 'data-id' );

		if ( counted[ id ] ) {
			return;
		}

		var duration = audio.duration || parseFloat( currentItem.getAttribute( 'data-duration' ) ) || 0;
		var bySeconds = audio.currentTime >= PLPlayer.thresholdSeconds;
		var byPercent = duration > 0 && ( audio.currentTime / duration ) * 100 >= PLPlayer.thresholdPercent;

		if ( ! bySeconds && ! byPercent ) {
			return;
		}

		counted[ id ] = true;

		try {
			sessionStorage.setItem( 'plp_counted_' + id, '1' );
		} catch ( error ) {}

		var item = currentItem;

		request( '/tracks/' + id + '/play', null, { method: 'POST' } ).then( function ( data ) {
			if ( data && typeof data.plays === 'number' ) {
				paintPlays( item, data.plays );
			}
		} ).catch( function () {} );
	}

	/* ------------------------------------------------------------------
	 * Hero panel
	 * --------------------------------------------------------------- */

	function heroOf( list ) {
		if ( ! list ) {
			return null;
		}

		if ( undefined === list.plpHero ) {
			var root = q( '[data-plp-hero]', list );

			list.plpHero = root ? {
				root: root,
				backdrop: q( '.plp-hero__backdrop', root ),
				cover: q( '.plp-hero__cover img', root ),
				coverEmpty: q( '.plp-hero__cover-empty', root ),
				title: q( '.plp-hero__title', root ),
				artist: q( '.plp-hero__artist', root ),
				current: q( '[data-plp-current]', root ),
				total: q( '[data-plp-total]', root ),
				seek: q( '[data-plp-seek]', root ),
				toggle: q( '[data-plp-toggle]', root ),
				like: q( '[data-plp-hero-like]', root ),
				likes: q( '[data-plp-hero-likes]', root ),
				plays: q( '[data-plp-hero-plays]', root )
			} : null;
		}

		return list.plpHero;
	}

	function paintHero( item, list ) {
		var hero = heroOf( list );

		if ( ! hero ) {
			return;
		}

		var cover = item.getAttribute( 'data-cover' ) || '';

		hero.title.textContent = item.getAttribute( 'data-title' ) || '';
		hero.artist.textContent = item.getAttribute( 'data-artist' ) || '';

		if ( hero.cover ) {
			hero.cover.src = cover;
			hero.cover.hidden = ! cover;
		}

		if ( hero.coverEmpty ) {
			hero.coverEmpty.hidden = !! cover;
		}

		if ( hero.backdrop ) {
			hero.backdrop.style.backgroundImage = cover
				? 'url("' + cover.replace( /"/g, '' ) + '")'
				: '';
		}

		if ( hero.total ) {
			hero.total.textContent = formatTime(
				parseFloat( item.getAttribute( 'data-duration' ) ) || 0
			);
		}

		syncHeroStats( item, hero );
	}

	/**
	 * Mirrors a row's like and play numbers into the panel.
	 *
	 * The row stays the single source of truth for counters, so there is only ever one
	 * place that has to be kept correct.
	 */
	function syncHeroStats( item, hero ) {
		if ( ! hero || ! item ) {
			return;
		}

		var rowLike = q( '.plp-like', item );
		var rowLikes = q( '.plp-like__count', item );
		var rowPlays = q( '.plp-plays__count', item );

		if ( hero.like && rowLike ) {
			var pressed = 'true' === rowLike.getAttribute( 'aria-pressed' );

			hero.like.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
			hero.like.setAttribute( 'aria-label', pressed ? PLPlayer.i18n.unlike : PLPlayer.i18n.like );
		}

		if ( hero.likes && rowLikes ) {
			hero.likes.textContent = rowLikes.textContent;
		}

		if ( hero.plays && rowPlays ) {
			hero.plays.textContent = rowPlays.textContent;
		}
	}

	/* ------------------------------------------------------------------
	 * Sticky bar
	 * --------------------------------------------------------------- */

	function showBar() {
		if ( bar ) {
			bar.hidden = false;
		}
	}

	function paintBar( item ) {
		if ( ! bar ) {
			return;
		}

		var cover = item.getAttribute( 'data-cover' );

		els.title.textContent = item.getAttribute( 'data-title' ) || '';
		els.artist.textContent = item.getAttribute( 'data-artist' ) || '';
		els.cover.src = cover || '';
		els.cover.alt = '';
	}

	function paintPlaying( playing ) {
		if ( els.toggle ) {
			setIcon( els.toggle, playing ? 'pause' : 'play' );
			els.toggle.setAttribute( 'aria-label', playing ? PLPlayer.i18n.pause : PLPlayer.i18n.play );
		}

		if ( currentItem ) {
			setIcon( q( '.plp-track__play', currentItem ), playing ? 'pause' : 'play' );
		}

		var hero = heroOf( currentList );

		if ( hero && hero.toggle ) {
			setIcon( hero.toggle, playing ? 'pause' : 'play' );
			hero.toggle.setAttribute( 'aria-label', playing ? PLPlayer.i18n.pause : PLPlayer.i18n.play );
		}
	}

	function paintProgress() {
		var duration = audio.duration;
		var ratio = ( isFinite( duration ) && duration > 0 )
			? Math.round( ( audio.currentTime / duration ) * 1000 )
			: null;

		if ( bar && els.current ) {
			els.current.textContent = formatTime( audio.currentTime );
			els.total.textContent = formatTime( duration );

			if ( null !== ratio && ! els.seek.matches( ':active' ) ) {
				els.seek.value = String( ratio );
			}
		}

		var hero = heroOf( currentList );

		if ( hero ) {
			if ( hero.current ) {
				hero.current.textContent = formatTime( audio.currentTime );
			}

			if ( hero.total && isFinite( duration ) && duration > 0 ) {
				hero.total.textContent = formatTime( duration );
			}

			if ( hero.seek && null !== ratio && ! hero.seek.matches( ':active' ) ) {
				hero.seek.value = String( ratio );
			}
		}
	}

	function setMediaSession( item ) {
		if ( ! ( 'mediaSession' in navigator ) || 'undefined' === typeof MediaMetadata ) {
			return;
		}

		var cover = item.getAttribute( 'data-cover' );

		navigator.mediaSession.metadata = new MediaMetadata( {
			title: item.getAttribute( 'data-title' ) || '',
			artist: item.getAttribute( 'data-artist' ) || '',
			album: item.getAttribute( 'data-album' ) || '',
			artwork: cover ? [ { src: cover, sizes: '512x512' } ] : []
		} );

		try {
			navigator.mediaSession.setActionHandler( 'play', function () { audio.play().catch( function () {} ); } );
			navigator.mediaSession.setActionHandler( 'pause', function () { audio.pause(); } );
			navigator.mediaSession.setActionHandler( 'previoustrack', function () { step( -1 ); } );
			navigator.mediaSession.setActionHandler( 'nexttrack', function () { step( 1 ); } );
		} catch ( error ) {}
	}

	/* ------------------------------------------------------------------
	 * Resuming across page loads
	 * --------------------------------------------------------------- */

	function saveState() {
		if ( ! currentItem ) {
			return;
		}

		try {
			sessionStorage.setItem( 'plp_state', JSON.stringify( {
				id: currentItem.getAttribute( 'data-id' ),
				time: audio.currentTime
			} ) );
		} catch ( error ) {}
	}

	function restoreState() {
		var raw;

		try {
			raw = sessionStorage.getItem( 'plp_state' );
		} catch ( error ) {
			return;
		}

		if ( ! raw ) {
			return;
		}

		var state;

		try {
			state = JSON.parse( raw );
		} catch ( error ) {
			return;
		}

		var item = q( '.plp-track[data-id="' + String( state.id ).replace( /"/g, '' ) + '"]' );

		if ( ! item ) {
			return;
		}

		var list = item.closest( '.plp' );

		// Loaded and paused, not playing: browsers refuse autoplay, and starting music
		// on its own after a page change would be rude anyway.
		select( item, list, false );

		audio.addEventListener( 'loadedmetadata', function once() {
			audio.removeEventListener( 'loadedmetadata', once );

			if ( state.time > 0 && state.time < audio.duration ) {
				audio.currentTime = state.time;
				paintProgress();
			}
		} );

		audio.load();
	}

	/* ------------------------------------------------------------------
	 * Listing: filter, sort, search, paging
	 * --------------------------------------------------------------- */

	function buildItem( track, showStats ) {
		var item = document.createElement( 'li' );
		item.className = 'plp-track';
		item.setAttribute( 'data-id', track.id );
		item.setAttribute( 'data-audio', track.audio );
		item.setAttribute( 'data-title', track.title );
		item.setAttribute( 'data-artist', track.artist || '' );
		item.setAttribute( 'data-album', track.album || '' );
		item.setAttribute( 'data-cover', track.cover_large || track.cover || '' );
		item.setAttribute( 'data-duration', track.duration || 0 );

		var play = document.createElement( 'button' );
		play.type = 'button';
		play.className = 'plp-track__play';
		play.setAttribute( 'aria-label', PLPlayer.i18n.play );
		play.innerHTML = '<span class="plp-icon plp-icon--play" aria-hidden="true"></span>';
		item.appendChild( play );

		var cover = document.createElement( 'span' );
		cover.className = 'plp-track__cover';

		if ( track.cover ) {
			var image = document.createElement( 'img' );
			image.src = track.cover;
			image.alt = '';
			image.loading = 'lazy';
			cover.appendChild( image );
		} else {
			var blank = document.createElement( 'span' );
			blank.className = 'plp-track__cover-empty';
			cover.appendChild( blank );
		}

		item.appendChild( cover );

		var meta = document.createElement( 'span' );
		meta.className = 'plp-track__meta';

		var title = document.createElement( 'span' );
		title.className = 'plp-track__title';
		title.textContent = track.title;
		meta.appendChild( title );

		if ( track.artist ) {
			var artist = document.createElement( 'span' );
			artist.className = 'plp-track__artist';
			artist.textContent = track.artist;
			meta.appendChild( artist );
		}

		item.appendChild( meta );

		var duration = document.createElement( 'span' );
		duration.className = 'plp-track__duration';
		duration.textContent = track.duration_human || '';
		item.appendChild( duration );

		var stats = document.createElement( 'span' );
		stats.className = 'plp-track__stats';

		var like = document.createElement( 'button' );
		like.type = 'button';
		like.className = 'plp-like';
		like.setAttribute( 'aria-pressed', 'false' );
		like.setAttribute( 'aria-label', PLPlayer.i18n.like );
		like.innerHTML = '<span class="plp-icon plp-icon--heart" aria-hidden="true"></span><span class="plp-like__count"></span>';
		stats.appendChild( like );

		if ( showStats ) {
			var plays = document.createElement( 'span' );
			plays.className = 'plp-plays';
			plays.innerHTML = '<span class="plp-icon plp-icon--headphones" aria-hidden="true"></span><span class="plp-plays__count"></span>';
			stats.appendChild( plays );
		}

		item.appendChild( stats );

		return item;
	}

	function fetchList( list, append ) {
		var cfg = config( list );
		var listing = q( '.plp-list', list );
		var more = q( '.plp-more__button', list );

		announce( list, PLPlayer.i18n.loading );

		if ( more ) {
			more.disabled = true;
		}

		var params = {
			per_page: cfg.perPage,
			page: cfg.page,
			orderby: cfg.orderby,
			order: cfg.order
		};

		if ( cfg.terms && cfg.terms.length ) {
			params.terms = cfg.terms.join( ',' );
		}

		if ( cfg.postTypes && cfg.postTypes.length ) {
			params.post_type = cfg.postTypes[0];
		}

		if ( cfg.search ) {
			params.search = cfg.search;
		}

		request( '/tracks', params ).then( function ( data ) {
			var tracks = data.tracks || [];

			if ( ! append ) {
				listing.textContent = '';
			}

			tracks.forEach( function ( track ) {
				listing.appendChild( buildItem( track, cfg.showStats ) );
			} );

			cfg.totalPages = data.pages || 1;

			var empty = q( '.plp-empty', list );

			if ( empty ) {
				empty.hidden = listing.children.length > 0;
			}

			announce( list, listing.children.length ? '' : PLPlayer.i18n.empty );

			if ( more ) {
				more.disabled = false;
				more.parentNode.hidden = cfg.page >= cfg.totalPages;
			}

			// The current track may have just been re-rendered as a new element.
			if ( currentItem && ! document.body.contains( currentItem ) ) {
				var again = q( '.plp-track[data-id="' + currentItem.getAttribute( 'data-id' ) + '"]', list );

				if ( again ) {
					currentItem = again;
					again.classList.add( 'is-current' );
					paintPlaying( ! audio.paused );
				} else if ( audio.paused && heroOf( list ) ) {
					// The track fell out of the filter and nothing is playing, so the
					// panel adopts the first of the new results.
					var replacement = queueOf( list )[0];

					if ( replacement ) {
						currentItem = replacement;
						currentList = list;
						replacement.classList.add( 'is-current' );
						audio.src = replacement.getAttribute( 'data-audio' );
						paintHero( replacement, list );
					}
				}
			}

			loadCounters( list );
		} ).catch( function ( error ) {
			announce( list, ( error && error.message ) || PLPlayer.i18n.error );

			if ( more ) {
				more.disabled = false;
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * --------------------------------------------------------------- */

	function wireList( list ) {
		var cfg = config( list );

		list.addEventListener( 'click', function ( event ) {
			// Hero panel controls act on the current track, wherever it came from.
			if ( event.target.closest( '[data-plp-toggle]' ) ) {
				event.preventDefault();
				toggle();

				return;
			}

			if ( event.target.closest( '[data-plp-prev]' ) ) {
				event.preventDefault();
				step( -1 );

				return;
			}

			if ( event.target.closest( '[data-plp-next]' ) ) {
				event.preventDefault();
				step( 1 );

				return;
			}

			if ( event.target.closest( '[data-plp-hero-like]' ) ) {
				event.preventDefault();

				if ( currentItem ) {
					toggleLike( currentItem, list );
				}

				return;
			}

			var playButton = event.target.closest( '.plp-track__play' );

			if ( playButton ) {
				event.preventDefault();
				var item = playButton.closest( '.plp-track' );

				if ( item === currentItem ) {
					toggle();
				} else {
					select( item, list, true );
				}

				return;
			}

			var likeButton = event.target.closest( '.plp-like' );

			if ( likeButton ) {
				event.preventDefault();
				toggleLike( likeButton.closest( '.plp-track' ), list );

				return;
			}

			var navButton = event.target.closest( '.plp-nav__item' );

			if ( navButton ) {
				event.preventDefault();
				qa( '.plp-nav__item', list ).forEach( function ( button ) {
					button.classList.toggle( 'is-active', button === navButton );
				} );

				var term = parseInt( navButton.getAttribute( 'data-term' ), 10 );
				cfg.terms = term ? [ term ] : [];
				cfg.page = 1;
				fetchList( list, false );

				return;
			}

			var moreButton = event.target.closest( '.plp-more__button' );

			if ( moreButton ) {
				event.preventDefault();
				cfg.page = ( cfg.page || 1 ) + 1;
				fetchList( list, true );
			}
		} );

		var sort = q( '.plp-sort__select', list );

		if ( sort ) {
			sort.value = cfg.orderby || 'date';
			sort.addEventListener( 'change', function () {
				cfg.orderby = sort.value;
				cfg.page = 1;
				fetchList( list, false );
			} );
		}

		var search = q( '.plp-search__input', list );

		if ( search ) {
			var timer = null;

			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					cfg.search = search.value.trim();
					cfg.page = 1;
					fetchList( list, false );
				}, 350 );
			} );
		}

		var hero = heroOf( list );

		if ( hero && hero.seek ) {
			hero.seek.addEventListener( 'input', function () {
				if ( isFinite( audio.duration ) && audio.duration > 0 ) {
					audio.currentTime = ( parseInt( hero.seek.value, 10 ) / 1000 ) * audio.duration;
				}
			} );
		}

		// With a hero panel there has to be something for its controls to act on before
		// the visitor picks a track, so the first one is selected without playing.
		if ( hero && ! currentItem ) {
			var first = queueOf( list )[0];

			if ( first ) {
				currentItem = first;
				currentList = list;
				first.classList.add( 'is-current' );
				audio.src = first.getAttribute( 'data-audio' );
				paintHero( first, list );
			}
		}

		loadCounters( list );
	}

	function wireBar() {
		bar = q( '#plp-bar' );

		if ( ! bar ) {
			return;
		}

		els = {
			title: q( '#plp-bar-title' ),
			artist: q( '#plp-bar-artist' ),
			cover: q( '#plp-bar-cover' ),
			toggle: q( '#plp-toggle' ),
			prev: q( '#plp-prev' ),
			next: q( '#plp-next' ),
			seek: q( '#plp-seek' ),
			current: q( '#plp-current' ),
			total: q( '#plp-total' ),
			shuffle: q( '#plp-shuffle' ),
			repeat: q( '#plp-repeat' ),
			volume: q( '#plp-volume' )
		};

		els.toggle.addEventListener( 'click', toggle );
		els.prev.addEventListener( 'click', function () { step( -1 ); } );
		els.next.addEventListener( 'click', function () { step( 1 ); } );

		els.seek.addEventListener( 'input', function () {
			if ( isFinite( audio.duration ) && audio.duration > 0 ) {
				audio.currentTime = ( parseInt( els.seek.value, 10 ) / 1000 ) * audio.duration;
			}
		} );

		els.volume.addEventListener( 'input', function () {
			audio.volume = parseInt( els.volume.value, 10 ) / 100;
		} );

		els.shuffle.addEventListener( 'click', function () {
			shuffle = ! shuffle;
			els.shuffle.setAttribute( 'aria-pressed', shuffle ? 'true' : 'false' );
		} );

		els.repeat.addEventListener( 'click', function () {
			repeat = ! repeat;
			els.repeat.setAttribute( 'aria-pressed', repeat ? 'true' : 'false' );
		} );
	}

	function wireAudio() {
		audio.addEventListener( 'play', function () { paintPlaying( true ); } );
		audio.addEventListener( 'pause', function () {
			paintPlaying( false );
			saveState();
		} );

		audio.addEventListener( 'timeupdate', function () {
			paintProgress();
			maybeCountPlay();
		} );

		audio.addEventListener( 'loadedmetadata', paintProgress );

		audio.addEventListener( 'ended', function () {
			if ( repeat ) {
				audio.currentTime = 0;
				audio.play().catch( function () {} );

				return;
			}

			step( 1 );
		} );

		window.addEventListener( 'pagehide', saveState );
	}

	function wireKeyboard() {
		document.addEventListener( 'keydown', function ( event ) {
			if ( ! currentItem || event.metaKey || event.ctrlKey || event.altKey ) {
				return;
			}

			var target = event.target;
			var tag = target && target.tagName ? target.tagName.toLowerCase() : '';

			if ( 'input' === tag || 'textarea' === tag || 'select' === tag || target.isContentEditable ) {
				return;
			}

			if ( ' ' === event.key ) {
				event.preventDefault();
				toggle();
			} else if ( 'ArrowRight' === event.key ) {
				audio.currentTime = Math.min( audio.duration || 0, audio.currentTime + 5 );
			} else if ( 'ArrowLeft' === event.key ) {
				audio.currentTime = Math.max( 0, audio.currentTime - 5 );
			} else if ( 'm' === event.key || 'M' === event.key ) {
				audio.muted = ! audio.muted;
			}
		} );
	}

	function init() {
		var lists = qa( '.plp' );

		if ( ! lists.length ) {
			return;
		}

		// Plays already reported in this browsing session must not be reported again.
		try {
			for ( var i = 0; i < sessionStorage.length; i++ ) {
				var key = sessionStorage.key( i );

				if ( key && 0 === key.indexOf( 'plp_counted_' ) ) {
					counted[ key.slice( 12 ) ] = true;
				}
			}
		} catch ( error ) {}

		wireBar();
		wireAudio();
		wireKeyboard();

		lists.forEach( wireList );

		restoreState();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
