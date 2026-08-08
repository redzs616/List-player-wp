/**
 * Browser-side audio analysis and cover generation.
 *
 * Runs in the admin's browser because that is the only place with a working audio
 * decoder on shared hosting. One track at a time: load, seek into the middle, play
 * silently at double speed, and sample the spectrum.
 *
 * @package PL_Player
 */

( function ( $ ) {
	'use strict';

	/** How long to sample, in wall-clock seconds. */
	var SAMPLE_SECONDS = 8;

	/** Playing faster covers more of the recording in the same wait. */
	var RATE = 2;

	/** Spectrum snapshots kept for the cover art. */
	var SNAPSHOTS = 64;

	/** Frequency bands per snapshot. */
	var BANDS = 32;

	var queue = [];
	var index = 0;
	var running = false;
	var ctx = null;

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	function fail( message ) {
		$( '#plp-analyze-error' ).prop( 'hidden', false ).find( 'p' ).text( message );
	}

	function clearFail() {
		$( '#plp-analyze-error' ).prop( 'hidden', true ).find( 'p' ).text( '' );
	}

	function progress( done, total ) {
		var percent = total ? Math.round( ( done / total ) * 100 ) : 0;

		$( '#plp-analyze-fill' ).css( 'width', percent + '%' );
		$( '#plp-analyze-label' ).text(
			PLPAnalyze.i18n.progress.replace( '%1$d', done ).replace( '%2$d', total )
		);
	}

	function addRow( track ) {
		var $row = $( '<tr />' ).attr( 'id', 'plp-row-' + track.id );

		$row.append( $( '<td />' ).text( track.title ) );
		$row.append( '<td class="plp-num" data-bpm>—</td>' );
		$row.append( '<td class="plp-num" data-energy>—</td>' );
		$row.append( '<td class="plp-num" data-bright>—</td>' );
		$row.append( '<td data-cover>' + ( track.hasCover ? '✓' : '—' ) + '</td>' );
		$row.append( '<td data-state>' + PLPAnalyze.i18n.working + '</td>' );

		$( '#plp-analyze-rows' ).prepend( $row );

		return $row;
	}

	/* ------------------------------------------------------------------
	 * Tempo
	 * --------------------------------------------------------------- */

	/**
	 * Estimates tempo by autocorrelating the low-band energy envelope.
	 *
	 * Autocorrelation rather than peak picking: it tolerates a missed or extra kick,
	 * which peak picking does not. Because the file is played faster than real time, the
	 * detected tempo is that much too high and gets divided back down.
	 */
	function detectBpm( envelope, sampleRate ) {
		if ( envelope.length < 100 || sampleRate < 40 ) {
			// Too few samples, or the timer was throttled — most likely a background
			// tab. A wrong number would be worse than none.
			return 0;
		}

		var mean = 0;
		var i;

		for ( i = 0; i < envelope.length; i++ ) {
			mean += envelope[ i ];
		}

		mean /= envelope.length;

		var signal = new Float32Array( envelope.length );

		for ( i = 0; i < envelope.length; i++ ) {
			signal[ i ] = Math.max( 0, envelope[ i ] - mean );
		}

		// Search the range of real tempos 60–200, expressed in what we actually hear.
		var minLag = Math.floor( ( 60 / ( 200 * RATE ) ) * sampleRate );
		var maxLag = Math.ceil( ( 60 / ( 60 * RATE ) ) * sampleRate );

		minLag = Math.max( 2, minLag );

		var best = 0;
		var bestLag = 0;

		for ( var lag = minLag; lag <= maxLag; lag++ ) {
			var sum = 0;
			var n = 0;

			for ( i = 0; i + lag < signal.length; i++ ) {
				sum += signal[ i ] * signal[ i + lag ];
				n++;
			}

			if ( ! n ) {
				continue;
			}

			var score = sum / n;

			if ( score > best ) {
				best = score;
				bestLag = lag;
			}
		}

		if ( ! bestLag ) {
			return 0;
		}

		var bpm = ( 60 * sampleRate / bestLag ) / RATE;

		// Autocorrelation happily locks onto half or double the real tempo, so fold the
		// answer into the range music actually lives in.
		while ( bpm < 70 ) {
			bpm *= 2;
		}

		while ( bpm > 180 ) {
			bpm /= 2;
		}

		return Math.round( bpm );
	}

	/* ------------------------------------------------------------------
	 * Measurement
	 * --------------------------------------------------------------- */

	function measure( track ) {
		return new Promise( function ( resolve, reject ) {
			var Ctx = window.AudioContext || window.webkitAudioContext;

			if ( ! Ctx ) {
				reject( new Error( 'no-webaudio' ) );

				return;
			}

			if ( ! ctx ) {
				ctx = new Ctx();
			}

			if ( 'suspended' === ctx.state ) {
				ctx.resume().catch( function () {} );
			}

			var el = new Audio();
			el.src = track.audio;
			el.preload = 'auto';

			var source;

			try {
				source = ctx.createMediaElementSource( el );
			} catch ( error ) {
				reject( new Error( 'no-source' ) );

				return;
			}

			var analyser = ctx.createAnalyser();
			analyser.fftSize = 1024;
			analyser.smoothingTimeConstant = 0;

			// Silent to the speakers, full signal to the analyser: the gain sits after
			// the tap, so nothing is heard while everything is measured.
			var gain = ctx.createGain();
			gain.gain.value = 0;

			source.connect( analyser );
			analyser.connect( gain );
			gain.connect( ctx.destination );

			var bins = new Uint8Array( analyser.frequencyBinCount );
			var envelope = [];
			var snapshots = [];
			var energySum = 0;
			var centroidSum = 0;
			var frames = 0;
			var timer = null;
			var started = 0;

			var cleanup = function () {
				if ( timer ) {
					window.clearInterval( timer );
					timer = null;
				}

				try {
					el.pause();
					source.disconnect();
					analyser.disconnect();
					gain.disconnect();
				} catch ( error ) {}

				el.src = '';
			};

			var finish = function () {
				var elapsed = ( Date.now() - started ) / 1000;
				// Derived from what actually happened rather than from the interval we
				// asked for, so timer jitter does not skew the tempo.
				var sampleRate = elapsed > 0 ? envelope.length / elapsed : 0;

				cleanup();

				if ( ! frames || energySum <= 0 ) {
					reject( new Error( 'silent' ) );

					return;
				}

				var energy = ( energySum / frames ) / 255;
				var centroid = ( centroidSum / frames ) / bins.length;

				resolve( {
					bpm: detectBpm( envelope, sampleRate ),
					// A perceptual nudge: raw averages of a spectrum cluster low, and a
					// scale where nothing ever passes 40 tells the reader nothing.
					energy: Math.min( 100, Math.round( Math.pow( energy, 0.7 ) * 135 ) ),
					bright: Math.min( 100, Math.round( Math.pow( centroid, 0.55 ) * 190 ) ),
					snapshots: snapshots
				} );
			};

			el.addEventListener( 'error', function () {
				cleanup();
				reject( new Error( 'load' ) );
			} );

			el.addEventListener( 'loadedmetadata', function () {
				// Two minutes in, or 40% of the way — the opening of a mix is usually an
				// intro and says little about the body of it.
				var target = isFinite( el.duration ) && el.duration > 0
					? Math.min( el.duration * 0.4, 120 )
					: 0;

				if ( target > 0 ) {
					try {
						el.currentTime = target;
					} catch ( error ) {}
				}

				el.playbackRate = RATE;

				el.play().then( function () {
					started = Date.now();

					timer = window.setInterval( function () {
						analyser.getByteFrequencyData( bins );

						var i;
						var total = 0;
						var weighted = 0;
						var low = 0;

						for ( i = 0; i < bins.length; i++ ) {
							total += bins[ i ];
							weighted += bins[ i ] * i;
						}

						// The kick lives in the bottom handful of bins; that is what
						// carries the beat for this kind of music.
						for ( i = 0; i < 7; i++ ) {
							low += bins[ i ];
						}

						envelope.push( low / 7 );
						energySum += total / bins.length;
						centroidSum += total > 0 ? weighted / total : 0;
						frames++;

						if ( snapshots.length < SNAPSHOTS && 0 === frames % 10 ) {
							var shot = new Array( BANDS );
							var perBand = Math.floor( bins.length * 0.7 / BANDS );

							for ( i = 0; i < BANDS; i++ ) {
								var acc = 0;

								for ( var j = 0; j < perBand; j++ ) {
									acc += bins[ i * perBand + j ];
								}

								shot[ i ] = Math.round( acc / perBand );
							}

							snapshots.push( shot );
						}

						if ( Date.now() - started >= SAMPLE_SECONDS * 1000 ) {
							finish();
						}
					}, 10 );
				} ).catch( function () {
					cleanup();
					reject( new Error( 'play' ) );
				} );
			} );

			el.load();
		} );
	}

	/* ------------------------------------------------------------------
	 * Cover art
	 * --------------------------------------------------------------- */

	/**
	 * Draws a cover from the recording's own spectrum.
	 *
	 * Time runs left to right, frequency bottom to top — a small spectrogram of the
	 * passage that was measured. Every mix therefore gets a different image, while the
	 * palette and layout keep them recognisably one series.
	 */
	function drawCover( track, snapshots ) {
		var size = 1000;
		var canvas = document.createElement( 'canvas' );
		canvas.width = size;
		canvas.height = size;

		var g = canvas.getContext( '2d' );
		var hue = track.hue || 250;

		var bg = g.createLinearGradient( 0, 0, size, size );
		bg.addColorStop( 0, 'hsl(' + hue + ', 38%, 16%)' );
		bg.addColorStop( 1, 'hsl(' + ( ( hue + 40 ) % 360 ) + ', 42%, 7%)' );
		g.fillStyle = bg;
		g.fillRect( 0, 0, size, size );

		var bandTop = 150;
		var bandHeight = 560;
		var cols = snapshots.length || 1;
		var colWidth = size / cols;
		var rowHeight = bandHeight / BANDS;

		for ( var x = 0; x < cols; x++ ) {
			var shot = snapshots[ x ] || [];

			for ( var y = 0; y < BANDS; y++ ) {
				var value = ( shot[ y ] || 0 ) / 255;

				if ( value < 0.04 ) {
					continue;
				}

				var lightness = 30 + ( value * 55 );

				g.fillStyle = 'hsla(' + ( ( hue + ( y * 3 ) ) % 360 ) + ', 75%, ' + lightness + '%, ' + ( 0.25 + value * 0.75 ) + ')';
				g.fillRect(
					Math.round( x * colWidth ) + 1,
					Math.round( bandTop + bandHeight - ( ( y + 1 ) * rowHeight ) ) + 1,
					Math.ceil( colWidth ) - 2,
					Math.ceil( rowHeight ) - 2
				);
			}
		}

		// Title, wrapped, sitting under the spectrogram.
		g.fillStyle = 'rgba(255,255,255,0.95)';
		g.font = '700 56px system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
		g.textBaseline = 'top';

		var words = String( track.title || '' ).split( /\s+/ );
		var lines = [];
		var line = '';

		for ( var w = 0; w < words.length; w++ ) {
			var attempt = line ? line + ' ' + words[ w ] : words[ w ];

			if ( g.measureText( attempt ).width > size - 160 && line ) {
				lines.push( line );
				line = words[ w ];
			} else {
				line = attempt;
			}
		}

		if ( line ) {
			lines.push( line );
		}

		lines = lines.slice( 0, 3 );

		for ( var l = 0; l < lines.length; l++ ) {
			g.fillText( lines[ l ], 80, 770 + ( l * 66 ) );
		}

		g.fillStyle = 'hsl(' + hue + ', 80%, 62%)';
		g.fillRect( 80, 730, 120, 6 );

		return canvas.toDataURL( 'image/jpeg', 0.86 );
	}

	/* ------------------------------------------------------------------
	 * Run
	 * --------------------------------------------------------------- */

	function step() {
		if ( ! running ) {
			$( '#plp-analyze-label' ).text( PLPAnalyze.i18n.stopped );
			stop();

			return;
		}

		if ( index >= queue.length ) {
			$( '#plp-analyze-label' ).text( PLPAnalyze.i18n.finished );
			stop();

			return;
		}

		var track = queue[ index ];
		var $row = addRow( track );

		measure( track ).then( function ( result ) {
			var wantCover = $( '#plp-make-covers' ).is( ':checked' ) && ! track.hasCover;
			var cover = '';

			if ( wantCover && result.snapshots.length ) {
				$row.find( '[data-state]' ).text( PLPAnalyze.i18n.cover );
				cover = drawCover( track, result.snapshots );
			}

			$row.find( '[data-state]' ).text( PLPAnalyze.i18n.saving );

			return $.post( PLPAnalyze.ajaxUrl, {
				action: 'plp_analyze_save',
				nonce: PLPAnalyze.nonce,
				id: track.id,
				bpm: result.bpm,
				energy: result.energy,
				bright: result.bright,
				cover: cover
			} ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					throw new Error( 'save' );
				}

				var v = response.data.values;

				$row.find( '[data-bpm]' ).text( v.bpm ? v.bpm : '—' );
				$row.find( '[data-energy]' ).text( v.energy );
				$row.find( '[data-bright]' ).text( v.bright );
				$row.find( '[data-state]' ).text( PLPAnalyze.i18n.done ).addClass( 'plp-ok' );

				if ( response.data.cover && response.data.cover.url ) {
					$row.find( '[data-cover]' ).html(
						$( '<img alt="" width="32" height="32" />' ).attr( 'src', response.data.cover.url )
					);
				}
			} );
		} ).catch( function ( error ) {
			var reason = ( error && 'silent' === error.message )
				? PLPAnalyze.i18n.noAudio
				: PLPAnalyze.i18n.failed;

			$row.find( '[data-state]' ).text( reason ).addClass( 'plp-bad' );
		} ).then( function () {
			index++;
			progress( index, queue.length );
			step();
		} );
	}

	function stop() {
		running = false;
		$( '#plp-analyze-start' ).prop( 'disabled', false );
		$( '#plp-analyze-stop' ).prop( 'disabled', true );
		$( '#plp-analyze-spinner' ).removeClass( 'is-active' );
	}

	function start() {
		clearFail();
		$( '#plp-analyze-start' ).prop( 'disabled', true );
		$( '#plp-analyze-spinner' ).addClass( 'is-active' );
		$( '#plp-analyze-label' ).text( PLPAnalyze.i18n.loading );
		$( '#plp-analyze-progress' ).prop( 'hidden', false );

		$.post( PLPAnalyze.ajaxUrl, {
			action: 'plp_analyze_queue',
			nonce: PLPAnalyze.nonce,
			redo: $( '#plp-redo' ).is( ':checked' ) ? '1' : '0'
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				fail( ( response && response.data && response.data.message ) || PLPAnalyze.i18n.failed );
				stop();

				return;
			}

			queue = response.data.queue || [];
			index = 0;

			if ( ! queue.length ) {
				$( '#plp-analyze-label' ).text( PLPAnalyze.i18n.empty );
				stop();

				return;
			}

			$( '#plp-analyze-table' ).prop( 'hidden', false );
			$( '#plp-analyze-stop' ).prop( 'disabled', false );
			running = true;
			progress( 0, queue.length );
			step();
		} ).fail( function () {
			fail( PLPAnalyze.i18n.failed );
			stop();
		} );
	}

	$( function () {
		$( '#plp-analyze-start' ).on( 'click', start );
		$( '#plp-analyze-stop' ).on( 'click', function () {
			running = false;
		} );
	} );
}( jQuery ) );
