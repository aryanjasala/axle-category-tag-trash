/**
 * Axle Category & Tag Trash: confirm steps shown in place, instead of browser dialogs.
 *
 * Without this script the links and buttons still work: they go straight
 * to the server, which checks the nonce and the capabilities as always.
 */
( function ( settings ) {
	'use strict';

	var speak = window.wp && window.wp.a11y ? window.wp.a11y.speak : function () {};
	var counter = 0;

	function closeGroup( group, opener ) {
		if ( group.classList.contains( 'actt-row-confirm' ) ) {
			group.parentNode.removeChild( group );
		} else {
			group.hidden = true;
		}
		if ( opener ) {
			opener.hidden = false;
			opener.focus();
		}
	}

	function openGroup( group, opener, message ) {
		group.hidden = false;
		if ( opener && opener.classList.contains( 'actt-confirm-open' ) ) {
			opener.hidden = true;
		}
		var first = group.querySelector( '.actt-confirm-yes, a.button, button.button' );
		if ( first ) {
			first.focus();
		}
		speak( message || ( group.querySelector( 'p' ) || {} ).textContent || '', 'assertive' );

		group.addEventListener( 'keydown', function onKey( event ) {
			if ( 'Escape' === event.key ) {
				group.removeEventListener( 'keydown', onKey );
				closeGroup( group, opener );
			}
		} );
		var no = group.querySelector( '.actt-confirm-no' );
		if ( no ) {
			no.onclick = function () {
				closeGroup( group, opener );
			};
		}
	}

	// Row action "Delete permanently".
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'a.actt-delete' ) : null;
		if ( ! link ) {
			return;
		}
		event.preventDefault();

		var cell = link.closest( 'td, th' );
		var old = cell.querySelector( '.actt-row-confirm' );
		if ( old ) {
			old.parentNode.removeChild( old );
		}

		counter += 1;
		var id = 'actt-row-confirm-' + counter;
		var group = document.createElement( 'div' );
		group.className = 'axle-confirm axle-confirm--danger actt-row-confirm';
		group.setAttribute( 'role', 'group' );
		group.setAttribute( 'aria-labelledby', id );

		var text = document.createElement( 'p' );
		text.id = id;
		var title = document.createElement( 'strong' );
		title.textContent = link.getAttribute( 'data-title' );
		text.appendChild( title );
		text.appendChild( document.createTextNode( ' ' + link.getAttribute( 'data-body' ) ) );

		var actions = document.createElement( 'div' );
		actions.className = 'axle-actions';
		var yes = document.createElement( 'a' );
		yes.className = 'button axle-button--danger actt-confirm-yes';
		yes.href = link.href;
		yes.textContent = link.getAttribute( 'data-yes' );
		var no = document.createElement( 'button' );
		no.type = 'button';
		no.className = 'button-link actt-confirm-no';
		no.textContent = settings.cancel;
		actions.appendChild( yes );
		actions.appendChild( no );

		group.appendChild( text );
		group.appendChild( actions );
		cell.appendChild( group );
		openGroup( group, link, text.textContent );
	} );

	// Empty trash.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.actt-confirm-open' ) : null;
		if ( ! button || ! button.form ) {
			return;
		}
		var group = document.getElementById( button.form.getAttribute( 'data-confirm-id' ) );
		if ( group ) {
			event.preventDefault();
			openGroup( group, button );
		}
	} );

	// Bulk "Delete permanently" on the list.
	var list = document.getElementById( 'actt-list-form' );
	if ( list ) {
		list.addEventListener( 'submit', function ( event ) {
			var top = list.elements.action;
			var bottom = list.elements.action2;
			var chosen = top && '-1' !== top.value ? top.value : ( bottom ? bottom.value : '' );
			var group = document.getElementById( list.getAttribute( 'data-confirm-id' ) );

			if ( 'delete' !== chosen || list.getAttribute( 'data-confirmed' ) || ! group || ! list.querySelector( 'input[name="items[]"]:checked' ) ) {
				return;
			}

			event.preventDefault();
			var opener = document.getElementById( top && '-1' !== top.value ? 'doaction' : 'doaction2' );
			openGroup( group, opener );
			group.querySelector( '.actt-confirm-yes' ).onclick = function () {
				list.setAttribute( 'data-confirmed', '1' );
				list.submit();
			};
		} );
	}
}( window.axleCategoryTagTrashConfirm || { cancel: 'Cancel' } ) );
