/**
 * The duplicate report screen: selecting surplus copies for the trash.
 *
 * Nothing here decides what may be removed — the server rebuilds the report and only
 * honours what it still marks as a surplus copy. This file is about not making the
 * choice tedious, and about not letting a click of "select all" turn into a surprise.
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-plp-dupes-form]' );

	if ( ! form ) {
		return;
	}

	function items() {
		return Array.prototype.slice.call( form.querySelectorAll( '[data-plp-dupes-item]' ) );
	}

	function picked() {
		return items().filter( function ( box ) {
			return box.checked;
		} );
	}

	function refresh() {
		var count = picked().length;

		Array.prototype.forEach.call(
			form.querySelectorAll( '[data-plp-dupes-count]' ),
			function ( node ) {
				node.textContent = count ? PLPDupes.selected.replace( '%d', count ) : '';
			}
		);

		// Each group's header box reflects its own rows rather than driving them.
		Array.prototype.forEach.call(
			form.querySelectorAll( '[data-plp-dupes-group]' ),
			function ( table ) {
				var all = table.querySelectorAll( '[data-plp-dupes-item]' );
				var on = table.querySelectorAll( '[data-plp-dupes-item]:checked' );
				var master = table.querySelector( '[data-plp-dupes-all]' );

				if ( ! master ) {
					return;
				}

				master.checked = all.length > 0 && on.length === all.length;
				master.indeterminate = on.length > 0 && on.length < all.length;
			}
		);
	}

	form.addEventListener( 'change', function ( event ) {
		var master = event.target.closest ? event.target.closest( '[data-plp-dupes-all]' ) : null;
		var table = master ? master.closest( '[data-plp-dupes-group]' ) : null;

		if ( table ) {
			Array.prototype.forEach.call(
				table.querySelectorAll( '[data-plp-dupes-item]' ),
				function ( box ) {
					box.checked = master.checked;
				}
			);
		}

		refresh();
	} );

	form.addEventListener( 'click', function ( event ) {
		var pick = event.target.closest ? event.target.closest( '[data-plp-dupes-pick]' ) : null;

		if ( ! pick ) {
			return;
		}

		event.preventDefault();

		var want = pick.getAttribute( 'data-plp-dupes-pick' );

		Array.prototype.forEach.call(
			form.querySelectorAll( '[data-plp-dupes-group]' ),
			function ( table ) {
				// Only the "same file" tier is a certainty; the other two are guesses,
				// so a one-click selection deliberately leaves them alone.
				var wanted = ( 'none' !== want ) && table.getAttribute( 'data-plp-dupes-group' ) === want;

				Array.prototype.forEach.call(
					table.querySelectorAll( '[data-plp-dupes-item]' ),
					function ( box ) {
						box.checked = wanted;
					}
				);
			}
		);

		refresh();
	} );

	form.addEventListener( 'submit', function ( event ) {
		var chosen = picked();

		if ( ! chosen.length ) {
			event.preventDefault();
			window.alert( PLPDupes.nothingPicked );

			return;
		}

		// Content that predates the player — a podcast episode with its own permalink
		// and RSS entry — deserves to be named in the question, not counted silently.
		var foreign = chosen.filter( function ( box ) {
			return '1' === box.getAttribute( 'data-plp-dupes-foreign' );
		} ).length;

		var question = foreign
			? PLPDupes.confirmMixed.replace( '%1$d', chosen.length ).replace( '%2$d', foreign )
			: PLPDupes.confirm.replace( '%d', chosen.length );

		if ( ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );

	refresh();
}() );
