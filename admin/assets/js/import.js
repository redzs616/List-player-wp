/**
 * Bulk import screen: file picking, preview table and sequential import.
 *
 * @package PL_Player
 */

( function ( $ ) {
	'use strict';

	var frame = null;

	/* ------------------------------------------------------------------
	 * Small DOM helpers
	 * --------------------------------------------------------------- */

	function el( tag, className ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		return node;
	}

	function textCell( text, className ) {
		var td = el( 'td', className );
		td.textContent = ( text === null || text === undefined || '' === text ) ? '—' : String( text );

		return td;
	}

	function inputCell( field, value, type ) {
		var td = el( 'td' );
		var input = el( 'input', 'plp-field plp-field--' + field );

		input.type = type || 'text';
		input.value = ( value === null || value === undefined ) ? '' : String( value );

		td.appendChild( input );

		return td;
	}

	function showError( message ) {
		$( '#plp-error' ).prop( 'hidden', false ).find( 'p' ).text( message );
	}

	function clearError() {
		$( '#plp-error' ).prop( 'hidden', true ).find( 'p' ).text( '' );
	}

	/* ------------------------------------------------------------------
	 * Preview table
	 * --------------------------------------------------------------- */

	/**
	 * Writes the status cell of a row.
	 */
	function setStatus( tr, text, className, href ) {
		var cell = tr.querySelector( '.plp-col-status' );

		if ( ! cell ) {
			return;
		}

		cell.textContent = '';
		cell.className = 'plp-col-status' + ( className ? ' ' + className : '' );

		if ( href ) {
			var link = el( 'a' );
			link.href = href;
			link.textContent = text;
			cell.appendChild( link );
		} else {
			cell.textContent = text;
		}
	}

	function buildRow( row ) {
		var tr = el( 'tr' );

		tr.dataset.attachmentId = row.attachment_id;
		tr.dataset.duration = row.duration || 0;

		var checkCell = el( 'td', 'check-column' );
		var checkbox = el( 'input', 'plp-include' );

		checkbox.type = 'checkbox';
		checkbox.checked = ! row.existing_id;
		checkbox.disabled = !! row.existing_id;
		checkCell.appendChild( checkbox );
		tr.appendChild( checkCell );

		tr.appendChild( textCell( row.filename, 'plp-col-file' ) );
		tr.appendChild( inputCell( 'title', row.title ) );
		tr.appendChild( inputCell( 'artist', row.artist ) );
		tr.appendChild( inputCell( 'album', row.album ) );
		tr.appendChild( inputCell( 'year', row.year, 'number' ) );
		tr.appendChild( textCell( row.duration_human, 'plp-col-duration' ) );

		var coverCell = el( 'td', 'plp-col-flag' );
		coverCell.textContent = row.has_cover ? '✓' : '—';
		tr.appendChild( coverCell );

		var statusCell = el( 'td', 'plp-col-status' );
		tr.appendChild( statusCell );

		if ( row.existing_id ) {
			tr.className = 'plp-row--skipped';
			setStatus( tr, PLPImport.i18n.alreadyImported, 'plp-status--skipped', row.existing_link );
		} else {
			setStatus( tr, PLPImport.i18n.pending, 'plp-status--pending' );
		}

		return tr;
	}

	function renderRows( rows ) {
		var tbody = document.getElementById( 'plp-import-rows' );

		tbody.textContent = '';

		rows.forEach( function ( row ) {
			tbody.appendChild( buildRow( row ) );
		} );

		$( '#plp-import-form' ).prop( 'hidden', false );
		$( '#plp-progress' ).prop( 'hidden', true );
		$( '#plp-start-import' ).prop( 'disabled', 0 === rows.length );
	}

	/* ------------------------------------------------------------------
	 * File picker
	 * --------------------------------------------------------------- */

	/**
	 * Opens the media modal.
	 *
	 * A fresh frame each time on purpose: a cached one keeps the previous selection,
	 * so a second round would re-scan files that were already imported.
	 */
	function openPicker() {
		frame = wp.media( {
			title: PLPImport.i18n.selectTitle,
			button: { text: PLPImport.i18n.selectButton },
			library: { type: 'audio' },
			multiple: 'add'
		} );

		frame.on( 'select', function () {
			var ids = frame.state().get( 'selection' ).map( function ( item ) {
				return item.get( 'id' );
			} );

			scan( ids );
		} );

		frame.open();
	}

	function scan( ids ) {
		if ( ! ids.length ) {
			return;
		}

		clearError();
		$( '#plp-scan-spinner' ).addClass( 'is-active' );

		$.post( PLPImport.ajaxUrl, {
			action: 'plp_import_scan',
			nonce: PLPImport.nonce,
			attachment_ids: ids
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showError( ( response && response.data && response.data.message ) || PLPImport.i18n.networkError );
				return;
			}

			if ( ! response.data.rows.length ) {
				showError( PLPImport.i18n.noAudio );
				return;
			}

			if ( response.data.truncated ) {
				showError( PLPImport.i18n.tooMany.replace( '%d', PLPImport.maxScan ) );
			}

			renderRows( response.data.rows );
		} ).fail( function () {
			showError( PLPImport.i18n.networkError );
		} ).always( function () {
			$( '#plp-scan-spinner' ).removeClass( 'is-active' );
		} );
	}

	/* ------------------------------------------------------------------
	 * Import run
	 * --------------------------------------------------------------- */

	function selectedCategories() {
		return $( '.plp-category:checked' ).map( function () {
			return this.value;
		} ).get();
	}

	function fieldValue( tr, field ) {
		var input = tr.querySelector( '.plp-field--' + field );

		return input ? input.value : '';
	}

	function updateProgress( done, total ) {
		var percent = total ? Math.round( ( done / total ) * 100 ) : 0;

		$( '#plp-progress-fill' ).css( 'width', percent + '%' );
		$( '#plp-progress-label' ).text( done + ' / ' + total );
	}

	function startImport() {
		var queue = $( '#plp-import-rows tr' ).filter( function () {
			var checkbox = this.querySelector( '.plp-include' );

			return checkbox && checkbox.checked && ! checkbox.disabled;
		} ).get();

		if ( ! queue.length ) {
			showError( PLPImport.i18n.noSelection );
			return;
		}

		clearError();

		var settings = {
			status: $( '#plp-import-status' ).val(),
			tags: $( '#plp-import-tags' ).val(),
			categories: selectedCategories()
		};

		var counters = { created: 0, skipped: 0, failed: 0 };

		$( '#plp-start-import, #plp-pick-files' ).prop( 'disabled', true );
		$( '#plp-progress' ).prop( 'hidden', false );
		updateProgress( 0, queue.length );

		importNext( queue, 0, settings, counters );
	}

	function importNext( queue, index, settings, counters ) {
		if ( index >= queue.length ) {
			finish( queue.length, counters );
			return;
		}

		var tr = queue[ index ];

		setStatus( tr, PLPImport.i18n.working, 'plp-status--working' );

		$.post( PLPImport.ajaxUrl, {
			action: 'plp_import_track',
			nonce: PLPImport.nonce,
			attachment_id: tr.dataset.attachmentId,
			duration: tr.dataset.duration,
			title: fieldValue( tr, 'title' ),
			artist: fieldValue( tr, 'artist' ),
			album: fieldValue( tr, 'album' ),
			year: fieldValue( tr, 'year' ),
			status: settings.status,
			tags: settings.tags,
			categories: settings.categories
		} ).done( function ( response ) {
			if ( response && response.success ) {
				counters.created++;
				setStatus( tr, PLPImport.i18n.created, 'plp-status--created', response.data.edit_link );
				disableRow( tr );
				return;
			}

			var data = ( response && response.data ) || {};

			if ( 'plp_duplicate' === data.code ) {
				counters.skipped++;
				setStatus(
					tr,
					PLPImport.i18n.alreadyImported,
					'plp-status--skipped',
					data.data && data.data.edit_link
				);
			} else {
				counters.failed++;
				setStatus( tr, data.message || PLPImport.i18n.failed, 'plp-status--failed' );
			}

			disableRow( tr );
		} ).fail( function () {
			counters.failed++;
			setStatus( tr, PLPImport.i18n.networkError, 'plp-status--failed' );
		} ).always( function () {
			updateProgress( index + 1, queue.length );
			importNext( queue, index + 1, settings, counters );
		} );
	}

	function disableRow( tr ) {
		var checkbox = tr.querySelector( '.plp-include' );

		if ( checkbox ) {
			checkbox.checked = false;
			checkbox.disabled = true;
		}
	}

	function finish( total, counters ) {
		$( '#plp-pick-files' ).prop( 'disabled', false );
		$( '#plp-start-import' ).prop( 'disabled', true );

		var summary = PLPImport.i18n.summary
			.replace( '%1$d', counters.created )
			.replace( '%2$d', counters.skipped )
			.replace( '%3$d', counters.failed );

		$( '#plp-progress-label' ).text( total + ' / ' + total + ' — ' + summary );
	}

	/* ------------------------------------------------------------------
	 * Events
	 * --------------------------------------------------------------- */

	$( document ).on( 'click', '#plp-pick-files', function ( event ) {
		event.preventDefault();
		openPicker();
	} );

	$( document ).on( 'click', '#plp-start-import', function ( event ) {
		event.preventDefault();
		startImport();
	} );

	$( document ).on( 'change', '#plp-toggle-all', function () {
		var checked = this.checked;

		$( '#plp-import-rows .plp-include' ).each( function () {
			if ( ! this.disabled ) {
				this.checked = checked;
			}
		} );
	} );
}( jQuery ) );
