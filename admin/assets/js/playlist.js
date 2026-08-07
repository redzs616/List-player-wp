/**
 * Playlist track picker.
 *
 * @package PL_Player
 */

( function ( $ ) {
	'use strict';

	var $field, $chosen, $results, $search, $empty, $count;

	function ids() {
		return $chosen.children( '.plp-chosen__row' ).map( function () {
			return $( this ).data( 'id' );
		} ).get();
	}

	function sync() {
		var list = ids();

		$field.val( list.join( ',' ) );
		$empty.prop( 'hidden', list.length > 0 );
		$count.text( PLPList.i18n.count.replace( '%d', list.length ) );

		// Results already on the list are marked rather than removed, so the search
		// results do not shuffle around while you are clicking through them.
		$results.children( 'li' ).each( function () {
			var $row = $( this );
			$row.toggleClass( 'is-added', list.indexOf( $row.data( 'id' ) ) !== -1 );
		} );
	}

	function buildRow( track ) {
		var $row = $( '<li class="plp-chosen__row" />' ).attr( 'data-id', track.id );

		$row.append( '<span class="plp-chosen__handle" aria-hidden="true"></span>' );

		var $cover = $( '<span class="plp-chosen__cover" />' ).css( '--plp-hue', track.hue );

		if ( track.cover ) {
			$cover.append( $( '<img alt="" />' ).attr( 'src', track.cover ) );
		} else {
			$cover.append( $( '<span aria-hidden="true" />' ).text( track.initial || '' ) );
		}

		$row.append( $cover );

		var $meta = $( '<span class="plp-chosen__meta" />' );
		$meta.append( $( '<span class="plp-chosen__title" />' ).text( track.title ) );

		if ( track.artist ) {
			$meta.append( $( '<span class="plp-chosen__artist" />' ).text( track.artist ) );
		}

		$row.append( $meta );
		$row.append( $( '<span class="plp-chosen__duration" />' ).text( track.duration || '' ) );
		$row.append( $( '<button type="button" class="plp-chosen__remove button-link" />' ).text( PLPList.i18n.remove ) );

		return $row;
	}

	function buildResult( track ) {
		var $row = $( '<li />' ).attr( 'data-id', track.id );

		var $cover = $( '<span class="plp-results__cover" />' ).css( '--plp-hue', track.hue );

		if ( track.cover ) {
			$cover.append( $( '<img alt="" />' ).attr( 'src', track.cover ) );
		} else {
			$cover.append( $( '<span aria-hidden="true" />' ).text( track.initial || '' ) );
		}

		$row.append( $cover );

		var $meta = $( '<span class="plp-results__meta" />' );
		$meta.append( $( '<span class="plp-results__title" />' ).text( track.title ) );

		if ( track.artist ) {
			$meta.append( $( '<span class="plp-results__artist" />' ).text( track.artist ) );
		}

		$row.append( $meta );
		$row.append( $( '<span class="plp-results__duration" />' ).text( track.duration || '' ) );
		$row.data( 'track', track );

		return $row;
	}

	function search( term ) {
		$results.html( '<li class="plp-results__note">' + PLPList.i18n.searching + '</li>' );

		$.post( PLPList.ajaxUrl, {
			action: 'plp_search_tracks',
			nonce: PLPList.nonce,
			term: term
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$results.html( '<li class="plp-results__note">' + PLPList.i18n.noResults + '</li>' );

				return;
			}

			var tracks = response.data.tracks || [];

			if ( ! tracks.length ) {
				$results.html( '<li class="plp-results__note">' + PLPList.i18n.noResults + '</li>' );

				return;
			}

			$results.empty();

			tracks.forEach( function ( track ) {
				$results.append( buildResult( track ) );
			} );

			sync();
		} ).fail( function () {
			$results.html( '<li class="plp-results__note">' + PLPList.i18n.noResults + '</li>' );
		} );
	}

	$( function () {
		$field = $( '#plp_tracks' );

		if ( ! $field.length ) {
			return;
		}

		$chosen = $( '#plp-chosen' );
		$results = $( '#plp-results' );
		$search = $( '#plp-list-search' );
		$empty = $( '#plp-list-empty' );
		$count = $( '#plp-list-count' );

		$chosen.sortable( {
			handle: '.plp-chosen__handle',
			axis: 'y',
			placeholder: 'plp-chosen__placeholder',
			forcePlaceholderSize: true,
			update: sync
		} );

		$results.on( 'click', 'li[data-id]', function () {
			var $row = $( this );

			if ( $row.hasClass( 'is-added' ) ) {
				return;
			}

			$chosen.append( buildRow( $row.data( 'track' ) ) );
			sync();
		} );

		$chosen.on( 'click', '.plp-chosen__remove', function () {
			$( this ).closest( '.plp-chosen__row' ).remove();
			sync();
		} );

		var timer = null;

		$search.on( 'input', function () {
			var term = $search.val().trim();

			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				search( term );
			}, 300 );
		} );

		sync();

		// The newest tracks are the likeliest thing to add, so show them straight away
		// rather than an empty panel waiting for a search term.
		search( '' );
	} );
}( jQuery ) );
