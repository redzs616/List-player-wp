/**
 * Track edit screen: media picker and ID3 autofill.
 *
 * @package PL_Player
 */

( function ( $ ) {
	'use strict';

	var frame = null;

	/**
	 * Shows the block that matches the selected source type.
	 */
	function toggleSource() {
		var type = $( 'input[name="plp_source_type"]:checked' ).val() || 'media';

		$( '.plp-source' ).prop( 'hidden', true );
		$( '.plp-source--' + type ).prop( 'hidden', false );
	}

	/**
	 * Writes a value into a field only when the field is still empty, so a manual
	 * correction is never overwritten by the tags.
	 */
	function fillIfEmpty( selector, value ) {
		var $field = $( selector );

		if ( ! $field.length || ! value ) {
			return;
		}

		if ( '' === $.trim( $field.val() ) ) {
			$field.val( value ).trigger( 'change' );
		}
	}

	/**
	 * Renders the "= 3:42" hint next to the duration field.
	 */
	function updateDurationHint() {
		var seconds = parseInt( $( '#plp_duration' ).val(), 10 );
		var hint = '';

		if ( seconds > 0 ) {
			var minutes = Math.floor( seconds / 60 );
			var rest = seconds % 60;
			hint = '= ' + minutes + ':' + ( rest < 10 ? '0' : '' ) + rest;
		}

		$( '#plp-duration-hint' ).text( hint );
	}

	/**
	 * Pulls the tags of the chosen attachment and fills the empty fields.
	 */
	function fetchAudioMeta( attachmentId ) {
		$.post( PLPAdmin.ajaxUrl, {
			action: 'plp_audio_meta',
			nonce: PLPAdmin.nonce,
			attachment_id: attachmentId
		} ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			var data = response.data;

			fillIfEmpty( '#title', data.title );
			fillIfEmpty( '#plp_artist', data.artist );
			fillIfEmpty( '#plp_album', data.album );
			fillIfEmpty( '#plp_year', data.year );
			fillIfEmpty( '#plp_duration', data.duration );

			updateDurationHint();
		} );
	}

	$( document ).on( 'change', 'input[name="plp_source_type"]', toggleSource );
	$( document ).on( 'input change', '#plp_duration', updateDurationHint );

	$( document ).on( 'click', '#plp-select-audio', function ( event ) {
		event.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: PLPAdmin.i18n.selectTitle,
				button: { text: PLPAdmin.i18n.selectButton },
				library: { type: 'audio' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first();

				if ( ! attachment ) {
					return;
				}

				attachment = attachment.toJSON();

				$( '#plp_attachment_id' ).val( attachment.id );
				$( '#plp-audio-name' ).text( attachment.filename || attachment.title || '' );
				$( '#plp-audio-player' ).attr( 'src', attachment.url );
				$( '#plp-audio-preview' ).prop( 'hidden', false );
				$( '#plp-remove-audio' ).prop( 'hidden', false );

				fetchAudioMeta( attachment.id );
			} );
		}

		frame.open();
	} );

	$( document ).on( 'click', '#plp-remove-audio', function ( event ) {
		event.preventDefault();

		$( '#plp_attachment_id' ).val( '' );
		$( '#plp-audio-name' ).text( '' );
		$( '#plp-audio-player' ).attr( 'src', '' );
		$( '#plp-audio-preview' ).prop( 'hidden', true );
		$( this ).prop( 'hidden', true );
	} );

	$( function () {
		toggleSource();
		updateDurationHint();
	} );
}( jQuery ) );
