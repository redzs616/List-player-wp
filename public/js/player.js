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
	var hasPlayed = false;
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

		// Not `init`: that is also a function name in this file, and a hoisted var
		// shadowing it is exactly the kind of bug that cost the hero play button.
		var requestInit = {
			method: options.method || 'GET',
			headers: headers,
			credentials: 'same-origin'
		};

		if ( options.body ) {
			headers['Content-Type'] = 'application/json';
			requestInit.body = JSON.stringify( options.body );
		}

		return fetch( endpoint( path, params ), requestInit ).then( function ( response ) {
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
	 * Add to playlist — editors only
	 *
	 * The markup only exists for users who may edit, so everything here is dead code
	 * for a visitor. These calls do send the nonce: creating and modifying posts is
	 * privileged, and unlike the play and like routes these pages are never cached.
	 * --------------------------------------------------------------- */

	var pickerTrack = null;

	function pickerOf( list ) {
		return q( '[data-plp-picker]', list );
	}

	function pickerNote( picker, text ) {
		var note = q( '[data-plp-picker-note]', picker );

		if ( note ) {
			note.textContent = text || '';
		}
	}

	function renderPicker( picker, playlists ) {
		var host = q( '[data-plp-picker-list]', picker );

		host.textContent = '';

		if ( ! playlists.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'plp-picker__empty';
			empty.textContent = PLPlayer.i18n.noPlaylists;
			host.appendChild( empty );

			return;
		}

		playlists.forEach( function ( list ) {
			var li = document.createElement( 'li' );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'plp-picker__pick';
			button.dataset.playlist = list.id;
			button.disabled = !! list.has;

			var name = document.createElement( 'span' );
			name.className = 'plp-picker__name';
			name.textContent = list.title;
			button.appendChild( name );

			var state = document.createElement( 'span' );
			state.className = 'plp-picker__state';
			state.textContent = list.has
				? PLPlayer.i18n.alreadyIn
				: PLPlayer.i18n.trackCount.replace( '%d', list.count );
			button.appendChild( state );

			li.appendChild( button );
			host.appendChild( li );
		} );
	}

	function openPicker( item, list ) {
		var picker = pickerOf( list );

		if ( ! picker ) {
			return;
		}

		pickerTrack = item;
		picker.hidden = false;
		pickerNote( picker, '' );

		var label = q( '[data-plp-picker-track]', picker );

		if ( label ) {
			label.textContent = item.getAttribute( 'data-title' ) || '';
		}

		q( '[data-plp-picker-list]', picker ).textContent = '';
		pickerNote( picker, PLPlayer.i18n.loading );

		request( '/playlists', { track: item.getAttribute( 'data-id' ) } ).then( function ( data ) {
			pickerNote( picker, '' );
			renderPicker( picker, data.playlists || [] );
		} ).catch( function ( error ) {
			pickerNote( picker, ( error && error.message ) || PLPlayer.i18n.error );
		} );
	}

	function closePicker( list ) {
		var picker = pickerOf( list );

		if ( picker ) {
			picker.hidden = true;
		}

		pickerTrack = null;
	}

	function addToPlaylist( playlistId, list, playlistName ) {
		if ( ! pickerTrack ) {
			return;
		}

		var picker = pickerOf( list );
		// Captured now: closing the panel clears pickerTrack, and the message below
		// still has to name the track that was actually filed.
		var track = pickerTrack;

		pickerNote( picker, PLPlayer.i18n.saving );

		request( '/playlists/' + playlistId + '/add', null, {
			method: 'POST',
			body: { track: parseInt( track.getAttribute( 'data-id' ), 10 ) }
		} ).then( function () {
			// Close it. A panel left standing still belongs to the track just filed, so
			// the next click would move THAT one somewhere else — which is exactly the
			// mix-up this caused. The confirmation goes to the status line instead,
			// where it survives the panel closing.
			closePicker( list );
			announce( list, addedMessage( PLPlayer.i18n.addedTo, track, playlistName ) );
		} ).catch( function ( error ) {
			pickerNote( picker, ( error && error.message ) || PLPlayer.i18n.error );
		} );
	}

	/**
	 * "<track> added to <playlist>", with both names filled in.
	 */
	function addedMessage( template, track, playlistName ) {
		return template
			.replace( '%1$s', track ? ( track.getAttribute( 'data-title' ) || '' ) : '' )
			.replace( '%2$s', playlistName || '' );
	}

	function createPlaylist( list ) {
		var picker = pickerOf( list );
		var input = q( '[data-plp-picker-name]', picker );
		var title = input ? input.value.trim() : '';

		if ( '' === title ) {
			pickerNote( picker, PLPlayer.i18n.needName );

			return;
		}

		var track = pickerTrack;

		pickerNote( picker, PLPlayer.i18n.saving );

		request( '/playlists', null, {
			method: 'POST',
			body: {
				title: title,
				track: track ? parseInt( track.getAttribute( 'data-id' ), 10 ) : 0
			}
		} ).then( function () {
			input.value = '';

			// Same reasoning as addToPlaylist: the job is done, so the panel goes away
			// rather than lingering with a stale track behind it.
			closePicker( list );
			announce( list, addedMessage( PLPlayer.i18n.createdWith, track, title ) );
		} ).catch( function ( error ) {
			pickerNote( picker, ( error && error.message ) || PLPlayer.i18n.error );
		} );
	}

	/* ------------------------------------------------------------------
	 * Sharing
	 * --------------------------------------------------------------- */

	/**
	 * Hands the track's own page to whatever the device shares with.
	 *
	 * The native share sheet is the whole point: on a phone it already lists Messenger,
	 * WhatsApp and everything else the visitor has, with no third-party buttons and no
	 * tracking scripts of ours. Desktop browsers mostly lack it, so there the link goes
	 * to the clipboard instead.
	 */
	function shareTrack( item, list ) {
		var url = item.getAttribute( 'data-url' );

		if ( ! url ) {
			return;
		}

		var payload = {
			title: item.getAttribute( 'data-title' ) || document.title,
			url: url
		};

		if ( navigator.share ) {
			navigator.share( payload ).catch( function () {
				// A cancelled share sheet lands here too; nothing to report.
			} );

			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function () {
				announce( list, PLPlayer.i18n.linkCopied );
			} ).catch( function () {
				announce( list, url );
			} );

			return;
		}

		// Last resort: show the address so it can be copied by hand.
		announce( list, url );
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

		// Moving to another track abandons whatever the picker was opened for, so the
		// panel must not stay behind pointing at the previous one. Opening the picker
		// never reaches select() — that branch returns first — so this cannot close the
		// panel that was just opened.
		if ( pickerTrack && pickerTrack !== item ) {
			closePicker( list );
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
		loadDepth( item, list );
		setMediaSession( item );

		if ( autoplay ) {
			// Must happen before play(), see prepareEq().
			prepareEq();

			audio.play().catch( function ( error ) {
				paintPlaying( false );

				announce(
					list,
					( error && 'NotAllowedError' === error.name )
						? PLPlayer.i18n.tapToPlay
						: PLPlayer.i18n.playFailed
				);
			} );
		}
	}

	function toggle() {
		if ( ! currentItem ) {
			return;
		}

		if ( ! audio.paused ) {
			audio.pause();

			return;
		}

		// The bar belongs here too. Reaching play through this path — the panel already
		// preselected a track, and the visitor pressed that row — used to start the
		// sound with no bar and no sign anything had happened.
		showBar();
		prepareEq();

		audio.play().then( function () {
			announce( currentList, '' );
		} ).catch( function ( error ) {
			paintPlaying( false );

			// Silently swallowing this was the reason "nothing happens" looked like
			// nothing happening.
			announce(
				currentList,
				( error && 'NotAllowedError' === error.name )
					? PLPlayer.i18n.tapToPlay
					: PLPlayer.i18n.playFailed
			);
		} );
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
				plays: q( '[data-plp-hero-plays]', root ),
				marks: q( '[data-plp-marks-layer]', root ),
				labels: q( '[data-plp-labels]', root ),
				about: q( '[data-plp-about]', root ),
				depth: q( '[data-plp-depth]', root ),
				listened: q( '[data-plp-listened]', root ),
				curve: q( '[data-plp-curve]', root ),
				chapters: q( '[data-plp-hero-chapters]', root ),
				markEdit: q( '[data-plp-mark-edit]', root ),
				markAdd: q( '[data-plp-mark-add]', root ),
				markList: q( '[data-plp-mark-list]', root ),
				markNote: q( '[data-plp-mark-note]', root )
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
		var hue = item.getAttribute( 'data-hue' ) || '250';

		hero.title.textContent = item.getAttribute( 'data-title' ) || '';
		hero.artist.textContent = item.getAttribute( 'data-artist' ) || '';

		if ( hero.cover ) {
			hero.cover.src = cover;
			hero.cover.hidden = ! cover;
		}

		if ( hero.coverEmpty ) {
			hero.coverEmpty.hidden = !! cover;
			hero.coverEmpty.style.setProperty( '--plp-hue', hue );
			hero.coverEmpty.textContent = item.getAttribute( 'data-initial' ) || '';
		}

		if ( hero.backdrop ) {
			// The hue always applies; a cover image just layers over it.
			hero.backdrop.style.setProperty( '--plp-hue', hue );
			hero.backdrop.style.backgroundImage = cover
				? 'url("' + cover.replace( /"/g, '' ) + '")'
				: '';
		}

		if ( hero.total ) {
			hero.total.textContent = formatTime(
				parseFloat( item.getAttribute( 'data-duration' ) ) || 0
			);
		}

		if ( hero.labels ) {
			var tags = ( item.getAttribute( 'data-labels' ) || '' ).split( '|' ).filter( Boolean );

			hero.labels.textContent = '';

			tags.forEach( function ( text ) {
				var tag = document.createElement( 'span' );
				tag.className = 'plp-tag';
				tag.textContent = text;
				hero.labels.appendChild( tag );
			} );

			hero.labels.hidden = ! tags.length;
		}

		if ( hero.about ) {
			var about = item.getAttribute( 'data-about' ) || '';

			hero.about.textContent = about;
			hero.about.hidden = '' === about;
		}

		paintMarks( item, hero );
		paintHeroChapters( item, hero );
		syncHeroStats( item, hero );

		// The editor follows whichever track is in the panel, and stays hidden unless
		// this visitor may edit that one.
		paintMarkEditor( list );
	}

	/**
	 * Draws the retention curve: one bar per slice of the track.
	 */
	function renderCurve( host, curve ) {
		host.textContent = '';

		var max = 0;

		curve.forEach( function ( value ) {
			max = Math.max( max, value );
		} );

		curve.forEach( function ( value ) {
			var bar = document.createElement( 'span' );
			bar.className = 'plp-depth__bar';
			bar.style.height = ( max ? Math.max( 4, Math.round( ( value / max ) * 100 ) ) : 2 ) + '%';
			host.appendChild( bar );
		} );
	}

	/**
	 * Loads the listening figures for the panel's current track.
	 */
	function loadDepth( item, list ) {
		var hero = heroOf( list );

		if ( ! hero || ! hero.depth ) {
			return;
		}

		var id = item.getAttribute( 'data-id' );

		request( '/tracks/' + id + '/stats' ).then( function ( data ) {
			// The visitor may have moved on while this was in flight.
			if ( ! currentItem || currentItem.getAttribute( 'data-id' ) !== id ) {
				return;
			}

			if ( ! data || ! data.curve ) {
				hero.depth.hidden = true;

				return;
			}

			if ( hero.listened ) {
				hero.listened.textContent = data.seconds_human || '';
			}

			if ( hero.curve ) {
				renderCurve( hero.curve, data.curve );
			}

			hero.depth.hidden = false;
		} ).catch( function () {
			hero.depth.hidden = true;
		} );
	}

	/**
	 * Draws the track's chapter marks onto the hero seek bar.
	 *
	 * A range input cannot hold children, so the ticks live in a sibling layer stretched
	 * over the same width. They are decorative there — the clickable list is the one
	 * under the row, which works without a steady hand.
	 */
	function paintMarks( item, hero ) {
		if ( ! hero || ! hero.marks ) {
			return;
		}

		hero.marks.textContent = '';

		var markers = readMarkers( item );
		var duration = parseFloat( item.getAttribute( 'data-duration' ) ) || 0;

		if ( ! markers.length || duration <= 0 ) {
			return;
		}

		markers.forEach( function ( marker ) {
			if ( marker.t > duration ) {
				return;
			}

			var tick = document.createElement( 'span' );

			tick.className = 'plp-hero__mark';
			tick.style.left = ( ( marker.t / duration ) * 100 ) + '%';
			tick.title = marker.time + ( marker.label ? ' — ' + marker.label : '' );

			hero.marks.appendChild( tick );
		} );
	}

	/**
	 * The clickable chapter strip under the hero slider.
	 *
	 * Laid out horizontally, wrapping, because time runs horizontally on the slider
	 * right above it — a vertical list would break that correspondence and, with a
	 * long tracklist, push the rest of the player off the screen. The ticks on the
	 * slider stay: they show where the marks are, these say what they are.
	 */
	function paintHeroChapters( item, hero ) {
		if ( ! hero || ! hero.chapters ) {
			return;
		}

		var markers = item ? readMarkers( item ) : [];

		hero.chapters.textContent = '';
		hero.chapters.hidden = ! markers.length;

		markers.forEach( function ( marker ) {
			var chip = document.createElement( 'button' );

			chip.type = 'button';
			chip.className = 'plp-hero__chapter';
			chip.setAttribute( 'data-plp-hero-chapter', String( marker.t ) );

			var time = document.createElement( 'span' );
			time.className = 'plp-hero__chapter-time';
			time.textContent = marker.time || formatTime( marker.t );
			chip.appendChild( time );

			var label = document.createElement( 'span' );
			label.className = 'plp-hero__chapter-label';
			label.textContent = marker.label || '';
			chip.appendChild( label );

			hero.chapters.appendChild( chip );
		} );
	}

	function readMarkers( item ) {
		var raw = item.getAttribute( 'data-markers' );

		if ( ! raw ) {
			return [];
		}

		try {
			var parsed = JSON.parse( raw );

			return Array.isArray( parsed ) ? parsed : [];
		} catch ( error ) {
			return [];
		}
	}

	/* ------------------------------------------------------------------
	 * Marker editing, for whoever may edit the recording
	 *
	 * The controls exist in the markup for everyone but stay hidden until the /me
	 * route says this visitor may edit this very post. That check cannot happen in
	 * PHP: a page cache would freeze one visitor's answer and hand it to the next.
	 * --------------------------------------------------------------- */

	/** Post ID (as string) -> may edit. Absent means "not asked yet". */
	var editRights = {};

	function mayEdit( item ) {
		return item ? true === editRights[ item.getAttribute( 'data-id' ) ] : false;
	}

	function probeRights( list ) {
		// No nonce, no point asking. WordPress REST cookie authentication needs the
		// X-WP-Nonce header, so without one current_user_can() is false there even with
		// a valid session — the answer could only be "no", and a save could not succeed
		// either. This spares every anonymous visitor a request.
		if ( ! PLPlayer.nonce ) {
			return;
		}

		var unknown = qa( '.plp-track', list )
			.map( function ( item ) {
				return item.getAttribute( 'data-id' );
			} )
			.filter( function ( id ) {
				return id && ! ( id in editRights );
			} );

		if ( ! unknown.length ) {
			paintMarkEditor( list );

			return;
		}

		request( '/me', { ids: unknown.join( ',' ) } ).then( function ( data ) {
			// Record the noes too, so a second look does not re-ask.
			unknown.forEach( function ( id ) {
				editRights[ id ] = false;
			} );

			( ( data && data.editable ) || [] ).forEach( function ( id ) {
				editRights[ String( id ) ] = true;
			} );

			paintMarkEditor( list );
		} ).catch( function () {
			// A visitor with no rights is the normal case, not an error worth showing.
		} );
	}

	function paintMarkEditor( list ) {
		var hero = heroOf( list );

		if ( ! hero || ! hero.markEdit || ! hero.markList ) {
			return;
		}

		var item = ( currentList === list ) ? currentItem : null;
		var allowed = mayEdit( item );

		hero.markEdit.hidden = ! allowed;

		if ( ! allowed ) {
			return;
		}

		var markers = readMarkers( item );

		hero.markList.textContent = '';

		markers.forEach( function ( marker, index ) {
			var li = document.createElement( 'li' );
			li.className = 'plp-mark-edit__row';
			li.setAttribute( 'data-plp-mark-row', String( marker.t ) );

			var jump = document.createElement( 'button' );
			jump.type = 'button';
			jump.className = 'plp-mark-edit__time';
			jump.setAttribute( 'data-plp-mark-jump', String( marker.t ) );
			jump.textContent = marker.time || formatTime( marker.t );
			jump.title = PLPlayer.i18n.markJump;
			li.appendChild( jump );

			var name = document.createElement( 'input' );
			name.type = 'text';
			name.className = 'plp-mark-edit__name';
			// The stored name only. The generated "3. rész" belongs in the placeholder,
			// never in the field, or it would be saved as if someone had typed it.
			name.value = marker.l || '';
			name.placeholder = PLPlayer.i18n.markNamePlaceholder.replace( '%d', index + 1 );
			name.setAttribute( 'data-plp-mark-name', String( marker.t ) );
			name.setAttribute( 'aria-label', PLPlayer.i18n.markName );
			li.appendChild( name );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'plp-mark-edit__remove';
			remove.setAttribute( 'data-plp-mark-remove', String( marker.t ) );
			remove.setAttribute( 'aria-label', PLPlayer.i18n.markRemove );
			remove.innerHTML = '<span class="plp-icon plp-icon--close" aria-hidden="true"></span>';
			li.appendChild( remove );

			hero.markList.appendChild( li );
		} );
	}

	/**
	 * The marker list as the server wants it: bare seconds and labels.
	 */
	function markerPayload( item ) {
		return readMarkers( item ).map( function ( marker ) {
			return { t: marker.t, l: marker.l || '' };
		} );
	}

	function saveMarkers( item, list, markers, note ) {
		var hero = heroOf( list );
		var id = item.getAttribute( 'data-id' );

		if ( hero && hero.markNote ) {
			hero.markNote.textContent = PLPlayer.i18n.saving;
		}

		return request( '/tracks/' + id + '/markers', null, {
			method: 'POST',
			body: { markers: markers }
		} ).then( function ( data ) {
			// The server's cleaned list wins: it de-duplicates and sorts, so the screen
			// must show what was actually stored rather than what we sent.
			item.setAttribute( 'data-markers', JSON.stringify( data.markers || [] ) );

			paintMarks( item, hero );
			paintHeroChapters( item, hero );
			paintChapters( item );
			paintMarkEditor( list );

			if ( hero && hero.markNote ) {
				hero.markNote.textContent = note || PLPlayer.i18n.markSaved;
			}
		} ).catch( function () {
			if ( hero && hero.markNote ) {
				hero.markNote.textContent = PLPlayer.i18n.markFailed;
			}
		} );
	}

	function addMarkerHere( item, list ) {
		var at = Math.max( 0, Math.floor( audio.currentTime || 0 ) );
		var markers = markerPayload( item );

		var clash = markers.some( function ( marker ) {
			return marker.t === at;
		} );

		if ( clash ) {
			var hero = heroOf( list );

			if ( hero && hero.markNote ) {
				hero.markNote.textContent = PLPlayer.i18n.markExists;
			}

			return;
		}

		markers.push( { t: at, l: '' } );

		saveMarkers(
			item,
			list,
			markers,
			PLPlayer.i18n.markAdded.replace( '%s', formatTime( at ) )
		);
	}

	function removeMarker( item, list, at ) {
		saveMarkers(
			item,
			list,
			markerPayload( item ).filter( function ( marker ) {
				return marker.t !== at;
			} ),
			PLPlayer.i18n.markRemoved
		);
	}

	function renameMarker( item, list, at, label ) {
		var markers = markerPayload( item );
		var changed = false;

		markers.forEach( function ( marker ) {
			if ( marker.t === at && marker.l !== label ) {
				marker.l = label;
				changed = true;
			}
		} );

		if ( ! changed ) {
			return;
		}

		saveMarkers( item, list, markers, PLPlayer.i18n.markSaved );
	}

	/**
	 * Rebuilds the chapter list under a row, so it matches the markers after an edit.
	 *
	 * The row title doubles as the disclosure control, and it only exists in that form
	 * when there is something to disclose — so going from none to some, or back, means
	 * swapping that element too.
	 */
	function paintChapters( item ) {
		var markers = readMarkers( item );
		var existing = q( '[data-plp-chapters]', item );

		if ( existing ) {
			existing.remove();
		}

		var meta = q( '.plp-track__meta', item );
		var title = q( '.plp-track__title', item );

		if ( meta && title ) {
			var wantToggle = markers.length > 0;
			var isToggle = title.hasAttribute( 'data-plp-chapters-toggle' );

			if ( wantToggle !== isToggle ) {
				var replacement;

				if ( wantToggle ) {
					replacement = document.createElement( 'button' );
					replacement.type = 'button';
					replacement.className = 'plp-track__title plp-track__title--toggle';
					replacement.setAttribute( 'aria-expanded', 'false' );
					replacement.setAttribute( 'data-plp-chapters-toggle', '' );
					replacement.textContent = title.textContent;

					var caret = document.createElement( 'span' );
					caret.className = 'plp-track__caret';
					caret.setAttribute( 'aria-hidden', 'true' );
					replacement.appendChild( caret );
				} else {
					replacement = document.createElement( 'span' );
					replacement.className = 'plp-track__title';
					replacement.textContent = title.textContent;
				}

				title.replaceWith( replacement );
			}
		}

		if ( ! markers.length ) {
			return;
		}

		var chapters = document.createElement( 'ol' );
		chapters.className = 'plp-chapters';
		chapters.setAttribute( 'data-plp-chapters', '' );
		chapters.hidden = true;

		markers.forEach( function ( marker ) {
			var li = document.createElement( 'li' );
			var jump = document.createElement( 'button' );

			jump.type = 'button';
			jump.className = 'plp-chapters__jump';
			jump.setAttribute( 'data-plp-chapter', marker.t );

			var time = document.createElement( 'span' );
			time.className = 'plp-chapters__time';
			time.textContent = marker.time || formatTime( marker.t );
			jump.appendChild( time );

			var label = document.createElement( 'span' );
			label.className = 'plp-chapters__label';
			label.textContent = marker.label || '';
			jump.appendChild( label );

			li.appendChild( jump );
			chapters.appendChild( li );
		} );

		item.appendChild( chapters );
	}

	/**
	 * Plays a track from a given second.
	 */
	function seekTo( item, list, seconds ) {
		var wasCurrent = ( item === currentItem );

		if ( ! wasCurrent ) {
			select( item, list, true );
		} else if ( audio.paused ) {
			prepareEq();
			audio.play().catch( function () {} );
		}

		var apply = function () {
			if ( isFinite( audio.duration ) && seconds < audio.duration ) {
				audio.currentTime = seconds;
			}
		};

		if ( audio.readyState >= 1 ) {
			apply();
		} else {
			var once = function () {
				audio.removeEventListener( 'loadedmetadata', once );
				apply();
			};

			audio.addEventListener( 'loadedmetadata', once );
		}
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
	 * Listening depth
	 *
	 * Two things get measured: how many seconds actually elapsed, and which of the
	 * track's twenty slices were heard. Both are accumulated in the browser and sent in
	 * one beacon when playback stops, rather than pinged continuously.
	 * --------------------------------------------------------------- */

	var BUCKETS = 20;
	var progress = { id: null, seconds: 0, buckets: {}, lastTime: 0 };

	function resetProgress( id ) {
		progress = { id: id, seconds: 0, buckets: {}, lastTime: 0 };
	}

	function trackProgress() {
		if ( ! currentItem ) {
			return;
		}

		var id = currentItem.getAttribute( 'data-id' );

		if ( progress.id !== id ) {
			flushProgress();
			resetProgress( id );
		}

		var now = audio.currentTime;
		var delta = now - progress.lastTime;

		// Only forward movement of roughly one tick counts. A jump means the visitor
		// dragged the slider, and skipped audio was never heard.
		if ( delta > 0 && delta < 2 ) {
			progress.seconds += delta;
		}

		progress.lastTime = now;

		var duration = audio.duration;

		if ( isFinite( duration ) && duration > 0 ) {
			var bucket = Math.min( BUCKETS - 1, Math.floor( ( now / duration ) * BUCKETS ) );
			progress.buckets[ bucket ] = true;
		}
	}

	function flushProgress() {
		var id = progress.id;
		var seconds = Math.round( progress.seconds );
		var buckets = Object.keys( progress.buckets );

		if ( ! id || ( seconds < 3 && ! buckets.length ) ) {
			return;
		}

		var body = new URLSearchParams();
		body.set( 'seconds', seconds );
		body.set( 'buckets', buckets.join( ',' ) );

		var url = endpoint( '/tracks/' + id + '/progress', null );

		// sendBeacon survives the page being closed and never blocks it. It cannot set
		// headers, which is fine — this route needs none.
		var sent = false;

		if ( navigator.sendBeacon ) {
			try {
				sent = navigator.sendBeacon( url, body );
			} catch ( error ) {
				sent = false;
			}
		}

		if ( ! sent ) {
			fetch( url, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				keepalive: true
			} ).catch( function () {} );
		}

		progress.seconds = 0;
		progress.buckets = {};
	}

	/* ------------------------------------------------------------------
	 * Live equalizer
	 *
	 * Reads the real signal through the Web Audio API rather than faking motion.
	 * Two things this has to survive:
	 *
	 * - Routing the element through a graph means we must reconnect it to the speakers,
	 *   otherwise the sound disappears.
	 * - A file served from another origin without CORS headers reads as pure silence.
	 *   We do NOT set crossOrigin on the element, because that would break playback
	 *   outright on servers that send no CORS headers — a decoration must never cost
	 *   the audio. Instead, if the analyser stays flat while something is clearly
	 *   playing, the canvas simply stays hidden.
	 * --------------------------------------------------------------- */

	var viz = {
		ctx: null,
		analyser: null,
		data: null,
		frame: null,
		flatFrames: 0,
		failed: false
	};

	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function eqCanvas() {
		return currentList ? q( '[data-plp-eq]', currentList ) : null;
	}

	/**
	 * Whether motion is allowed here.
	 *
	 * The reduced-motion preference is honoured by default. It is worth knowing how
	 * often this fires: on Windows, turning off "animation effects" sets it, so a
	 * visitor can have it on without ever having thought about accessibility. That is
	 * why `equalizer="always"` exists — the owner can decide the equalizer is the
	 * point of the panel rather than decoration.
	 */
	function eqAllowed() {
		if ( ! reducedMotion ) {
			return true;
		}

		var canvas = eqCanvas();

		return !! ( canvas && '1' === canvas.getAttribute( 'data-plp-eq-force' ) );
	}

	function ensureAnalyser() {
		if ( viz.analyser || viz.failed ) {
			return ! viz.failed;
		}

		var Ctx = window.AudioContext || window.webkitAudioContext;

		if ( ! Ctx ) {
			viz.failed = true;

			return false;
		}

		try {
			if ( ! viz.ctx ) {
				viz.ctx = new Ctx();
			}

			var source = viz.ctx.createMediaElementSource( audio );

			viz.analyser = viz.ctx.createAnalyser();
			viz.analyser.fftSize = 128;
			viz.analyser.smoothingTimeConstant = 0.78;

			source.connect( viz.analyser );
			// Back to the speakers, or nothing would be audible from here on.
			viz.analyser.connect( viz.ctx.destination );

			viz.data = new Uint8Array( viz.analyser.frequencyBinCount );
		} catch ( error ) {
			viz.failed = true;
			viz.analyser = null;

			return false;
		}

		return true;
	}

	function drawEq() {
		var canvas = eqCanvas();

		if ( ! canvas || ! viz.analyser ) {
			return;
		}

		viz.analyser.getByteFrequencyData( viz.data );

		var total = 0;
		var i;

		for ( i = 0; i < viz.data.length; i++ ) {
			total += viz.data[ i ];
		}

		if ( 0 === total ) {
			// Only count silence against us while something is actually playing; a
			// paused moment is not evidence of anything.
			if ( ! audio.paused ) {
				viz.flatFrames++;
			}

			// Three seconds of complete silence during playback means the analyser
			// cannot see this file — give up rather than leave a dead row sitting there.
			if ( viz.flatFrames > 180 ) {
				canvas.hidden = true;
				stopEq();

				return;
			}
		} else {
			viz.flatFrames = 0;
			canvas.hidden = false;
		}

		var ratio = window.devicePixelRatio || 1;
		var width = canvas.clientWidth;
		var height = canvas.clientHeight;

		if ( canvas.width !== Math.round( width * ratio ) || canvas.height !== Math.round( height * ratio ) ) {
			canvas.width = Math.round( width * ratio );
			canvas.height = Math.round( height * ratio );
		}

		var g = canvas.getContext( '2d' );

		g.setTransform( ratio, 0, 0, ratio, 0, 0 );
		g.clearRect( 0, 0, width, height );

		var styles = getComputedStyle( currentList );
		var low = styles.getPropertyValue( '--plp-eq-low' ).trim() || '#35d07f';
		var mid = styles.getPropertyValue( '--plp-eq-mid' ).trim() || '#f5c542';
		var high = styles.getPropertyValue( '--plp-eq-high' ).trim() || '#e8453c';

		// One gradient spanning the full height, so a bar's colour is decided by how far
		// it reaches rather than by which bar it is: quiet stays green, louder passes
		// through amber, peaks touch red. That is how a mixer's LED ladder behaves, and
		// it reads at a glance without a legend.
		var ladder = g.createLinearGradient( 0, height, 0, 0 );

		ladder.addColorStop( 0, low );
		ladder.addColorStop( 0.55, low );
		ladder.addColorStop( 0.72, mid );
		ladder.addColorStop( 0.88, mid );
		ladder.addColorStop( 1, high );

		g.fillStyle = ladder;

		var bars = 48;
		var slot = width / bars;
		var barWidth = Math.max( 2, slot - 2 );

		for ( i = 0; i < bars; i++ ) {
			// Skip the very top of the spectrum: it is mostly empty on music and would
			// leave a dead flat tail on the right.
			var bin = Math.floor( ( i / bars ) * ( viz.data.length * 0.72 ) );

			// A single frequency bin rarely fills to 255 on real music, so without a
			// little headroom the red band would never be reached at all.
			var value = Math.min( 1, ( viz.data[ bin ] / 255 ) * 1.35 );
			var barHeight = Math.max( 1, value * height );

			g.globalAlpha = 0.5 + ( value * 0.5 );
			g.fillRect( i * slot + ( slot - barWidth ) / 2, height - barHeight, barWidth, barHeight );
		}

		g.globalAlpha = 1;

		viz.frame = window.requestAnimationFrame( drawEq );
	}

	/**
	 * Builds the audio graph, and must run BEFORE playback starts.
	 *
	 * Connecting an element that is already playing hands back silence in Chrome — the
	 * analyser reports zeros while the sound comes out fine, which looks exactly like a
	 * CORS problem and is not one. Called from the click handlers, before play().
	 */
	function prepareEq() {
		if ( viz.failed || viz.analyser || ! eqCanvas() || ! eqAllowed() ) {
			return;
		}

		var Ctx = window.AudioContext || window.webkitAudioContext;

		if ( ! Ctx ) {
			viz.failed = true;

			return;
		}

		if ( ! viz.ctx ) {
			try {
				viz.ctx = new Ctx();
			} catch ( error ) {
				viz.failed = true;

				return;
			}
		}

		// The decisive guard. Routing the element into a context that is not running
		// sends the sound into a dead graph, and createMediaElementSource cannot be
		// undone — the element would be silent for the rest of the page. So we only
		// wire it up once the context is genuinely running, ask for a resume otherwise,
		// and let the equalizer sit out this play. A decoration must never be able to
		// cost the audio.
		if ( 'running' !== viz.ctx.state ) {
			viz.ctx.resume().catch( function () {} );

			return;
		}

		ensureAnalyser();
	}

	function startEq() {
		if ( ! eqCanvas() || viz.failed || ! eqAllowed() ) {
			return;
		}

		// Never build the graph from here. This runs on the play event, when the
		// element is already going: tapping it that late yields silence in Chrome,
		// and a suspended context would take the sound with it. prepareEq() owns
		// the wiring, and it runs before play(). No analyser simply means no bars.
		if ( ! viz.analyser ) {
			return;
		}

		if ( 'suspended' === viz.ctx.state ) {
			viz.ctx.resume().catch( function () {} );
		}

		if ( null === viz.frame ) {
			viz.flatFrames = 0;
			viz.frame = window.requestAnimationFrame( drawEq );
		}
	}

	function stopEq() {
		if ( null !== viz.frame ) {
			window.cancelAnimationFrame( viz.frame );
			viz.frame = null;
		}
	}

	function fadeEq() {
		stopEq();

		var canvas = eqCanvas();

		if ( canvas ) {
			canvas.classList.remove( 'is-live' );
		}
	}

	/* ------------------------------------------------------------------
	 * Sticky bar
	 * --------------------------------------------------------------- */

	function showBar() {
		if ( ! bar ) {
			return;
		}

		bar.hidden = false;
		reserveBarSpace();
	}

	/**
	 * Keeps the page from ending underneath the bar.
	 *
	 * The bar is fixed to the bottom of the viewport, so without this the last track
	 * and the "load more" button sit behind it — worst on phones, where the bar wraps
	 * onto two rows and is twice as tall.
	 */
	function reserveBarSpace() {
		if ( ! bar || bar.hidden ) {
			return;
		}

		document.body.style.paddingBottom = bar.offsetHeight + 'px';
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

	/* ------------------------------------------------------------------
	 * Scrubbing
	 *
	 * The slider used to be protected from the playhead by a `:active` check.
	 * That is not a reliable signal: the moment the pointer leaves the slider's
	 * box mid-drag the pseudo-class drops, timeupdate resumes writing the real
	 * playback position back into the slider, and the thumb snaps away from the
	 * finger — which is felt as the slider sticking at a point. An explicit flag
	 * driven by pointer events cannot come undone that way.
	 * --------------------------------------------------------------- */

	var scrubbing = false;
	var scrubSlider = null;

	function scrubSeconds( slider ) {
		var duration = audio.duration;

		if ( ! isFinite( duration ) || duration <= 0 ) {
			return null;
		}

		return ( ( parseInt( slider.value, 10 ) || 0 ) / 1000 ) * duration;
	}

	/**
	 * Shows where the drag currently points, without seeking.
	 *
	 * Seeking on every input event asks a file coming over the network to re-seek
	 * on each pixel of travel, which stalls the audio and makes the drag feel like
	 * it is fighting back. The real seek happens once, on release.
	 */
	function previewScrub( slider ) {
		var seconds = scrubSeconds( slider );

		if ( null === seconds ) {
			return;
		}

		var label = formatTime( seconds );
		var hero = heroOf( currentList );

		if ( bar && els.current ) {
			els.current.textContent = label;
		}

		if ( hero && hero.current ) {
			hero.current.textContent = label;
		}

		// The panel and the sticky bar show the same track, so the slider that is
		// not being dragged has to follow along or it will jump on release.
		if ( hero && hero.seek && hero.seek !== slider ) {
			hero.seek.value = slider.value;
		}

		if ( bar && els.seek && els.seek !== slider ) {
			els.seek.value = slider.value;
		}
	}

	function commitScrub() {
		if ( ! scrubbing ) {
			return;
		}

		var slider = scrubSlider;

		scrubbing = false;
		scrubSlider = null;

		if ( ! slider ) {
			return;
		}

		var seconds = scrubSeconds( slider );

		if ( null !== seconds ) {
			audio.currentTime = seconds;
		}
	}

	function wireScrubber( slider ) {
		if ( ! slider ) {
			return;
		}

		slider.addEventListener( 'pointerdown', function () {
			scrubbing = true;
			scrubSlider = slider;
		} );

		slider.addEventListener( 'input', function () {
			// Keyboard use produces input with no pointer down, and it still has to
			// hold the playhead off until the value settles.
			scrubbing = true;
			scrubSlider = slider;
			previewScrub( slider );
		} );

		// Fires on release for a pointer, and on each arrow key press.
		slider.addEventListener( 'change', commitScrub );

		slider.addEventListener( 'pointercancel', function () {
			scrubbing = false;
			scrubSlider = null;
			paintProgress();
		} );
	}

	function paintProgress() {
		var duration = audio.duration;
		var ratio = ( isFinite( duration ) && duration > 0 )
			? Math.round( ( audio.currentTime / duration ) * 1000 )
			: null;

		if ( bar && els.current ) {
			els.current.textContent = formatTime( audio.currentTime );
			els.total.textContent = formatTime( duration );

			if ( null !== ratio && ! scrubbing ) {
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

			if ( hero.seek && null !== ratio && ! scrubbing ) {
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
		// Only worth remembering once the visitor actually started something. The hero
		// panel preselects a track so its controls have a target, and saving that would
		// make the sticky bar pop open unprompted on the next page they visit.
		if ( ! currentItem || ! hasPlayed ) {
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

		// A position of zero means nothing was really listened to — most likely a stale
		// entry from an older version. Restoring it would open the bar for no reason.
		if ( ! state || ! ( state.time > 1 ) ) {
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
		item.setAttribute( 'data-hue', track.hue || 250 );
		item.setAttribute( 'data-initial', track.initial || '' );
		item.setAttribute( 'data-duration', track.duration || 0 );
		item.setAttribute( 'data-labels', ( track.labels || [] ).join( '|' ) );
		item.setAttribute( 'data-about', track.description || '' );
		item.setAttribute( 'data-url', track.permalink || '' );
		item.setAttribute( 'data-markers', JSON.stringify( track.markers || [] ) );

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
			blank.setAttribute( 'aria-hidden', 'true' );
			blank.style.setProperty( '--plp-hue', track.hue || 250 );
			blank.textContent = track.initial || '';
			cover.appendChild( blank );
		}

		item.appendChild( cover );

		var meta = document.createElement( 'span' );
		meta.className = 'plp-track__meta';

		var markers = track.markers || [];
		var title;

		if ( markers.length ) {
			// A disclosure control, matching what the server renders for the same case.
			title = document.createElement( 'button' );
			title.type = 'button';
			title.className = 'plp-track__title plp-track__title--toggle';
			title.setAttribute( 'aria-expanded', 'false' );
			title.setAttribute( 'data-plp-chapters-toggle', '' );
			title.textContent = track.title;

			var caret = document.createElement( 'span' );
			caret.className = 'plp-track__caret';
			caret.setAttribute( 'aria-hidden', 'true' );
			title.appendChild( caret );
		} else {
			title = document.createElement( 'span' );
			title.className = 'plp-track__title';
			title.textContent = track.title;
		}

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

		var share = document.createElement( 'button' );
		share.type = 'button';
		share.className = 'plp-share';
		share.setAttribute( 'aria-label', PLPlayer.i18n.share );
		share.innerHTML = '<span class="plp-icon plp-icon--share" aria-hidden="true"></span>';
		stats.appendChild( share );

		// Mirrors the server: the control only exists where a picker panel does, which
		// is only rendered for users who may edit.
		if ( q( '[data-plp-picker]' ) ) {
			var addTo = document.createElement( 'button' );
			addTo.type = 'button';
			addTo.className = 'plp-addto';
			addTo.setAttribute( 'data-plp-addto', '' );
			addTo.setAttribute( 'aria-label', PLPlayer.i18n.addToList );
			addTo.title = PLPlayer.i18n.addToList;
			addTo.innerHTML = '<span class="plp-icon plp-icon--plus" aria-hidden="true"></span>';
			stats.appendChild( addTo );
		}

		if ( showStats ) {
			var plays = document.createElement( 'span' );
			plays.className = 'plp-plays';
			plays.innerHTML = '<span class="plp-icon plp-icon--headphones" aria-hidden="true"></span><span class="plp-plays__count"></span>';
			stats.appendChild( plays );
		}

		item.appendChild( stats );

		// One builder for the chapter list, shared with the marker editor, so a row
		// rebuilt after an edit cannot drift from a row that arrived from the server.
		paintChapters( item );

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

		// The playlist has to travel with every request. It defines the set; the
		// categories do not apply inside it.
		if ( cfg.playlist ) {
			params.playlist = cfg.playlist;
		} else if ( cfg.terms && cfg.terms.length ) {
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
			// Freshly arrived rows have not been asked about yet.
			probeRights( list );
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

			if ( event.target.closest( '[data-plp-hero-share]' ) ) {
				event.preventDefault();

				if ( currentItem ) {
					shareTrack( currentItem, list );
				}

				return;
			}

			if ( event.target.closest( '[data-plp-mark-add]' ) ) {
				event.preventDefault();

				if ( currentItem && mayEdit( currentItem ) ) {
					addMarkerHere( currentItem, list );
				}

				return;
			}

			var heroChapter = event.target.closest( '[data-plp-hero-chapter]' );

			if ( heroChapter ) {
				event.preventDefault();

				if ( currentItem ) {
					seekTo( currentItem, list, parseInt( heroChapter.getAttribute( 'data-plp-hero-chapter' ), 10 ) || 0 );
				}

				return;
			}

			var markJump = event.target.closest( '[data-plp-mark-jump]' );

			if ( markJump ) {
				event.preventDefault();

				if ( currentItem ) {
					seekTo( currentItem, list, parseInt( markJump.getAttribute( 'data-plp-mark-jump' ), 10 ) || 0 );
				}

				return;
			}

			var markRemove = event.target.closest( '[data-plp-mark-remove]' );

			if ( markRemove ) {
				event.preventDefault();

				if ( currentItem && mayEdit( currentItem ) ) {
					removeMarker( currentItem, list, parseInt( markRemove.getAttribute( 'data-plp-mark-remove' ), 10 ) || 0 );
				}

				return;
			}

			// Not named `toggle`: a var by that name is hoisted over the whole handler
			// and shadows the toggle() function used above, which killed the hero play
			// button outright.
			var chaptersToggle = event.target.closest( '[data-plp-chapters-toggle]' );

			if ( chaptersToggle ) {
				event.preventDefault();

				var panel = q( '[data-plp-chapters]', chaptersToggle.closest( '.plp-track' ) );

				if ( panel ) {
					var open = panel.hidden;
					panel.hidden = ! open;
					chaptersToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}

				return;
			}

			var chapter = event.target.closest( '[data-plp-chapter]' );

			if ( chapter ) {
				event.preventDefault();

				var owner = chapter.closest( '.plp-track' );

				if ( owner ) {
					seekTo( owner, list, parseFloat( chapter.getAttribute( 'data-plp-chapter' ) ) || 0 );
				}

				return;
			}

			var addTo = event.target.closest( '[data-plp-addto]' );

			if ( addTo ) {
				event.preventDefault();
				openPicker( addTo.closest( '.plp-track' ), list );

				return;
			}

			if ( event.target.closest( '[data-plp-picker-close]' ) ) {
				event.preventDefault();
				closePicker( list );

				return;
			}

			var pick = event.target.closest( '.plp-picker__pick' );

			if ( pick ) {
				event.preventDefault();

				var pickName = q( '.plp-picker__name', pick );

				addToPlaylist(
					parseInt( pick.dataset.playlist, 10 ),
					list,
					pickName ? pickName.textContent : ''
				);

				return;
			}

			if ( event.target.closest( '[data-plp-picker-create]' ) ) {
				event.preventDefault();
				createPlaylist( list );

				return;
			}

			var shareButton = event.target.closest( '.plp-share' );

			if ( shareButton ) {
				event.preventDefault();
				shareTrack( shareButton.closest( '.plp-track' ), list );

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

			var popout = event.target.closest( '.plp-popout' );

			if ( popout ) {
				event.preventDefault();
				openPopout( popout );

				return;
			}

			var moreNav = event.target.closest( '.plp-nav__more' );

			if ( moreNav ) {
				event.preventDefault();

				qa( '.plp-nav__item--extra', list ).forEach( function ( button ) {
					button.hidden = false;
				} );

				moreNav.hidden = true;

				return;
			}

			var moreButton = event.target.closest( '.plp-more__button' );

			if ( moreButton ) {
				event.preventDefault();
				cfg.page = ( cfg.page || 1 ) + 1;
				fetchList( list, true );
			}
		} );

		// Renaming a marker saves on commit — blur or Enter — rather than on each
		// keystroke, so a name is written once instead of letter by letter.
		list.addEventListener( 'change', function ( event ) {
			var field = event.target.closest ? event.target.closest( '[data-plp-mark-name]' ) : null;

			if ( ! field || ! currentItem || ! mayEdit( currentItem ) ) {
				return;
			}

			renameMarker(
				currentItem,
				list,
				parseInt( field.getAttribute( 'data-plp-mark-name' ), 10 ) || 0,
				field.value.trim()
			);
		} );

		list.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key || ! event.target.closest ) {
				return;
			}

			if ( event.target.closest( '[data-plp-mark-name]' ) ) {
				event.preventDefault();
				event.target.blur();
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
			wireScrubber( hero.seek );
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
				loadDepth( first, list );
			}
		}

		loadCounters( list );
		probeRights( list );
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

		wireScrubber( els.seek );

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
		audio.addEventListener( 'play', function () {
			hasPlayed = true;
			paintPlaying( true );

			var canvas = eqCanvas();

			if ( canvas ) {
				canvas.classList.add( 'is-live' );
			}

			startEq();
		} );

		audio.addEventListener( 'pause', function () {
			paintPlaying( false );
			saveState();
			flushProgress();
			fadeEq();
		} );

		// A pointer released anywhere ends the drag. Without this, letting go outside
		// the slider would leave the flag set and the playhead would never resume
		// driving it. Bound here rather than with the bar, because a player can be on
		// the page without one.
		window.addEventListener( 'pointerup', commitScrub );

		audio.addEventListener( 'timeupdate', function () {
			paintProgress();
			maybeCountPlay();
			trackProgress();
		} );

		audio.addEventListener( 'loadedmetadata', paintProgress );

		audio.addEventListener( 'ended', function () {
			flushProgress();
			fadeEq();

			if ( repeat ) {
				audio.currentTime = 0;
				audio.play().catch( function () {} );

				return;
			}

			step( 1 );
		} );

		window.addEventListener( 'pagehide', function () {
			saveState();
			flushProgress();
		} );

		// Rotating a phone changes how many rows the bar wraps onto.
		window.addEventListener( 'resize', reserveBarSpace );
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

	/**
	 * A small public surface, so the popup window can pick up where the page left off.
	 */
	window.PLPlayerAPI = {
		play: function ( id, at ) {
			var item = q( '.plp-track[data-id="' + String( id ).replace( /"/g, '' ) + '"]' );

			if ( ! item ) {
				return false;
			}

			select( item, item.closest( '.plp' ), true );

			if ( at > 0 ) {
				var seek = function () {
					audio.removeEventListener( 'loadedmetadata', seek );

					if ( at < audio.duration ) {
						audio.currentTime = at;
					}
				};

				if ( audio.readyState >= 1 ) {
					seek();
				} else {
					audio.addEventListener( 'loadedmetadata', seek );
				}
			}

			return true;
		},

		current: function () {
			if ( ! currentItem ) {
				return null;
			}

			return {
				id: currentItem.getAttribute( 'data-id' ),
				time: Math.floor( audio.currentTime )
			};
		},

		pause: function () {
			audio.pause();
		}
	};

	function openPopout( button ) {
		var url = button.getAttribute( 'data-plp-popup' );

		if ( ! url ) {
			return;
		}

		var state = window.PLPlayerAPI.current();

		if ( state ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'track=' + encodeURIComponent( state.id ) +
				'&t=' + encodeURIComponent( state.time );
		}

		var popup = window.open( url, 'plp_popup', 'width=420,height=620,menubar=no,toolbar=no,location=no' );

		if ( ! popup ) {
			// Blocked. Leaving the page player running is better than silently doing
			// nothing.
			announce( button.closest( '.plp' ), PLPlayer.i18n.popupBlocked );

			return;
		}

		// Two windows playing the same track over each other would be nonsense.
		window.PLPlayerAPI.pause();
		popup.focus();
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
