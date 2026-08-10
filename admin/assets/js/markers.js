/**
 * Marker editor: place, drag, name and delete timestamps on a recording.
 *
 * @package PL_Player
 */

( function () {
	'use strict';

	var root, field, audio, bar, played, rows, none, table;
	var markers = [];
	var duration = 0;
	var dragging = null;

	function q( selector ) {
		return root.querySelector( selector );
	}

	function format( seconds ) {
		var total = Math.max( 0, Math.floor( seconds ) );
		var mins = Math.floor( total / 60 );
		var secs = total % 60;
		var hours = Math.floor( mins / 60 );

		if ( hours ) {
			mins = mins % 60;

			return hours + ':' + ( mins < 10 ? '0' : '' ) + mins + ':' + ( secs < 10 ? '0' : '' ) + secs;
		}

		return mins + ':' + ( secs < 10 ? '0' : '' ) + secs;
	}

	/**
	 * Reads "1:23:45", "12:30" or a bare number of seconds.
	 */
	function parseTime( text ) {
		var parts = String( text ).trim().split( ':' ).map( function ( p ) {
			return parseInt( p, 10 ) || 0;
		} );

		if ( 1 === parts.length ) {
			return parts[ 0 ];
		}

		if ( 2 === parts.length ) {
			return ( parts[ 0 ] * 60 ) + parts[ 1 ];
		}

		return ( parts[ 0 ] * 3600 ) + ( parts[ 1 ] * 60 ) + parts[ 2 ];
	}

	function span() {
		// Metadata beats the stored duration once it is available; the stored value is
		// only a starting point so the bar is usable before the file loads.
		if ( audio && isFinite( audio.duration ) && audio.duration > 0 ) {
			return audio.duration;
		}

		return duration > 0 ? duration : 0;
	}

	function sort() {
		markers.sort( function ( a, b ) {
			return a.t - b.t;
		} );
	}

	function commit() {
		sort();
		field.value = JSON.stringify( markers );
		paint();
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * --------------------------------------------------------------- */

	function paint() {
		var total = span();

		// Ticks on the bar.
		Array.prototype.slice.call( bar.querySelectorAll( '.plp-marks__tick' ) ).forEach( function ( el ) {
			el.remove();
		} );

		markers.forEach( function ( marker, index ) {
			var tick = document.createElement( 'button' );

			tick.type = 'button';
			tick.className = 'plp-marks__tick';
			tick.style.left = ( total ? ( marker.t / total ) * 100 : 0 ) + '%';
			tick.dataset.index = index;
			tick.title = format( marker.t ) + ( marker.l ? ' — ' + marker.l : '' );
			tick.setAttribute( 'aria-label', tick.title );

			bar.appendChild( tick );
		} );

		// Table.
		rows.textContent = '';

		markers.forEach( function ( marker, index ) {
			var tr = document.createElement( 'tr' );

			var tdTime = document.createElement( 'td' );
			var time = document.createElement( 'input' );
			time.type = 'text';
			time.className = 'plp-marks__time small-text';
			time.value = format( marker.t );
			time.dataset.index = index;
			time.dataset.role = 'time';
			tdTime.appendChild( time );
			tr.appendChild( tdTime );

			var tdLabel = document.createElement( 'td' );
			var label = document.createElement( 'input' );
			label.type = 'text';
			label.className = 'plp-marks__label';
			label.value = marker.l || '';
			label.placeholder = PLPMarkers.i18n.labelPlaceholder;
			label.dataset.index = index;
			label.dataset.role = 'label';
			tdLabel.appendChild( label );
			tr.appendChild( tdLabel );

			var tdActions = document.createElement( 'td' );

			var jump = document.createElement( 'button' );
			jump.type = 'button';
			jump.className = 'button-link plp-marks__jump';
			jump.textContent = PLPMarkers.i18n.jump;
			jump.dataset.index = index;
			jump.dataset.role = 'jump';
			tdActions.appendChild( jump );

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link plp-marks__remove';
			remove.textContent = PLPMarkers.i18n.remove;
			remove.dataset.index = index;
			remove.dataset.role = 'remove';
			tdActions.appendChild( remove );

			tr.appendChild( tdActions );
			rows.appendChild( tr );
		} );

		table.hidden = 0 === markers.length;
		none.hidden = markers.length > 0;
	}

	function paintProgress() {
		var total = span();

		if ( played ) {
			played.style.width = ( total ? ( audio.currentTime / total ) * 100 : 0 ) + '%';
		}

		var current = q( '[data-plp-marks-current]' );

		if ( current ) {
			current.textContent = format( audio.currentTime );
		}
	}

	/* ------------------------------------------------------------------
	 * Interaction
	 * --------------------------------------------------------------- */

	function positionFrom( event ) {
		var rect = bar.getBoundingClientRect();
		var ratio = ( event.clientX - rect.left ) / rect.width;

		ratio = Math.min( 1, Math.max( 0, ratio ) );

		return Math.round( ratio * span() );
	}

	function add( seconds ) {
		if ( markers.length >= PLPMarkers.max ) {
			window.alert( PLPMarkers.i18n.tooMany );

			return;
		}

		seconds = Math.max( 0, Math.round( seconds ) );

		// A marker already on that second would be dropped on save, so refuse here
		// where it can still be explained.
		var taken = markers.some( function ( marker ) {
			return marker.t === seconds;
		} );

		if ( taken ) {
			return;
		}

		markers.push( { t: seconds, l: '' } );
		commit();
	}

	function wire() {
		bar.addEventListener( 'pointerdown', function ( event ) {
			var tick = event.target.closest( '.plp-marks__tick' );

			if ( tick ) {
				// Grab, do not add: the click is meant for the existing marker.
				dragging = parseInt( tick.dataset.index, 10 );
				bar.setPointerCapture( event.pointerId );
				event.preventDefault();

				return;
			}

			add( positionFrom( event ) );
		} );

		bar.addEventListener( 'pointermove', function ( event ) {
			if ( null === dragging ) {
				return;
			}

			markers[ dragging ].t = positionFrom( event );
			field.value = JSON.stringify( markers );

			var tick = bar.querySelector( '.plp-marks__tick[data-index="' + dragging + '"]' );

			if ( tick ) {
				var total = span();
				tick.style.left = ( total ? ( markers[ dragging ].t / total ) * 100 : 0 ) + '%';
			}
		} );

		bar.addEventListener( 'pointerup', function ( event ) {
			if ( null === dragging ) {
				return;
			}

			// Sorting happens on release, so the dragged tick does not renumber itself
			// mid-gesture and jump out from under the pointer.
			dragging = null;
			bar.releasePointerCapture( event.pointerId );
			commit();
		} );

		bar.addEventListener( 'click', function ( event ) {
			var tick = event.target.closest( '.plp-marks__tick' );

			if ( tick && audio ) {
				audio.currentTime = markers[ parseInt( tick.dataset.index, 10 ) ].t;
			}
		} );

		rows.addEventListener( 'input', function ( event ) {
			var index = parseInt( event.target.dataset.index, 10 );

			if ( isNaN( index ) || ! markers[ index ] ) {
				return;
			}

			if ( 'label' === event.target.dataset.role ) {
				markers[ index ].l = event.target.value;
				field.value = JSON.stringify( markers );
			}
		} );

		rows.addEventListener( 'change', function ( event ) {
			var index = parseInt( event.target.dataset.index, 10 );

			if ( isNaN( index ) || ! markers[ index ] || 'time' !== event.target.dataset.role ) {
				return;
			}

			markers[ index ].t = Math.max( 0, parseTime( event.target.value ) );
			commit();
		} );

		rows.addEventListener( 'click', function ( event ) {
			var index = parseInt( event.target.dataset.index, 10 );

			if ( isNaN( index ) || ! markers[ index ] ) {
				return;
			}

			if ( 'remove' === event.target.dataset.role ) {
				markers.splice( index, 1 );
				commit();

				return;
			}

			if ( 'jump' === event.target.dataset.role && audio ) {
				audio.currentTime = markers[ index ].t;
				audio.play().catch( function () {} );
			}
		} );

		var addButton = q( '[data-plp-marks-add]' );

		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				add( audio ? audio.currentTime : 0 );
			} );
		}

		var clearButton = q( '[data-plp-marks-clear]' );

		if ( clearButton ) {
			clearButton.addEventListener( 'click', function () {
				if ( ! markers.length || ! window.confirm( PLPMarkers.i18n.confirmClear ) ) {
					return;
				}

				markers = [];
				commit();
			} );
		}

		if ( audio ) {
			audio.addEventListener( 'timeupdate', paintProgress );
			audio.addEventListener( 'loadedmetadata', function () {
				var total = q( '[data-plp-marks-total]' );

				if ( total ) {
					total.textContent = format( audio.duration );
				}

				paint();
			} );
		}
	}

	function start() {
		root = document.querySelector( '[data-plp-marks]' );

		if ( ! root ) {
			return;
		}

		field = q( '#plp_markers' );
		bar = q( '[data-plp-marks-bar]' );

		if ( ! field || ! bar ) {
			return;
		}

		audio = q( '.plp-marks__audio' );
		played = q( '[data-plp-marks-played]' );
		rows = q( '[data-plp-marks-rows]' );
		none = q( '[data-plp-marks-none]' );
		table = q( '[data-plp-marks-table]' );
		duration = parseInt( root.dataset.duration, 10 ) || 0;

		try {
			markers = JSON.parse( field.value ) || [];
		} catch ( error ) {
			markers = [];
		}

		wire();
		paint();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
