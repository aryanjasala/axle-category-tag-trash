/**
 * Axle Category & Tag Trash: shows an Undo notice after a term is deleted from a term list.
 *
 * Core deletes terms over admin-ajax (action "delete-tag") and answers "1" on
 * success. This listens for that response instead of replacing core's handler.
 */
( function ( $, settings ) {
	'use strict';

	var lastName = '';

	function format( template, value ) {
		return template.replace( '%s', value );
	}

	// The "View Axle Category & Tag Trash (N)" link under the table is rendered on page load, so keep it in step.
	function bumpTrashCount() {
		$( '.axle-category-tag-trash-note a' ).text( function ( index, text ) {
			return text.replace( /\((\d+)\)/, function ( match, count ) {
				return '(' + ( Number( count ) + 1 ) + ')';
			} );
		} );
	}

	function showNotice( termId, taxonomy, name ) {
		var $notice = $( '<div class="notice notice-success is-dismissible axle-category-tag-trash-undo"><p></p></div>' );
		var $text = $( '<span></span>' ).text( format( settings.i18n.moved, name ) + ' ' );
		var $button = $( '<button type="button" class="button button-small"></button>' ).text( settings.i18n.undo );

		$notice.find( 'p' ).append( $text, $button );
		$( '.axle-category-tag-trash-undo' ).remove();
		$( '.wp-header-end' ).first().after( $notice );
		$( document ).trigger( 'wp-updates-notice-added' );
		wp.a11y.speak( $text.text() );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( settings.i18n.restoring );
			$notice.attr( 'aria-busy', 'true' );
			$button.after( $( '<span class="spinner is-active actt-spinner"></span>' ) );

			$.post( window.ajaxurl, {
				action: settings.action,
				_ajax_nonce: settings.nonce,
				term_id: termId,
				taxonomy: taxonomy,
			} )
				.done( function ( response ) {
					$text.text( format( settings.i18n.restored, response.data.name ) );
					$button.remove();
					wp.a11y.speak( $text.text() );
					// Reload so the term, its count and its children show in the table again.
					window.location.reload();
				} )
				.fail( function ( xhr ) {
					var message = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : settings.i18n.failed;
					$notice.removeClass( 'notice-success' ).addClass( 'notice-error' ).attr( 'aria-busy', 'false' ).find( '.spinner' ).remove();
					$text.text( message + ' ' + settings.i18n.retry );
					$button.remove();
					wp.a11y.speak( $text.text(), 'assertive' );
				} );
		} );
	}

	$( '#the-list' ).on( 'click', '.delete-tag', function () {
		lastName = $( this ).closest( 'tr' ).find( '.row-title' ).text();
	} );

	$( document ).on( 'ajaxSuccess', function ( event, xhr, ajaxSettings ) {
		if ( typeof ajaxSettings.data !== 'string' || ajaxSettings.data.indexOf( 'action=delete-tag' ) === -1 ) {
			return;
		}
		if ( String( xhr.responseText ).trim() !== '1' ) {
			return;
		}

		var params = new URLSearchParams( ajaxSettings.data );
		showNotice( params.get( 'tag_ID' ), params.get( 'taxonomy' ), lastName );
		bumpTrashCount();
	} );
} )( jQuery, window.axleCategoryTagTrashScreen );
