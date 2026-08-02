/**
 * Popular searches pre-suggestions panel.
 *
 * Prepends pill chips into FiboSearch's own suggestions container when the
 * input is focused and empty. Two entry points:
 *   - fibosearch/show-pre-suggestions (returning visitor with history)
 *   - focus on empty input (first-visit, no history in localStorage)
 */
( function ( $ ) {
	'use strict';

	if ( typeof fsePopular === 'undefined' || ! Array.isArray( fsePopular.terms ) || fsePopular.terms.length < 3 ) {
		return;
	}

	var terms         = fsePopular.terms;
	var BLOCK_CLASS   = 'fse-popular-searches';
	var INJECTED_SEL  = '.' + BLOCK_CLASS;
	var CONTAINER_SEL = '.dgwt-wcas-suggestions-wrapp';

	function escapeHtml( str ) {
		return $( '<div>' ).text( str ).html();
	}

	function buildHtml() {
		var html = '<div class="' + BLOCK_CLASS + '">';
		html += '<div class="fse-popular-searches__label">&#128293; Popular searches</div>';
		html += '<div class="fse-popular-searches__chips">';
		terms.forEach( function ( term ) {
			var safe = escapeHtml( term );
			html += '<a href="#" class="fse-popular-chips__item" data-term="' + safe + '">' + safe + '</a>';
		} );
		html += '</div></div>';
		return html;
	}

	function inject( $container ) {
		if ( $container.find( INJECTED_SEL ).length ) return; // idempotent
		$container.prepend( buildHtml() );
		$container.show();
		$( 'body' ).addClass( 'dgwt-wcas-open' );
	}

	function remove() {
		$( INJECTED_SEL ).remove();
	}

	// Normal path: visitor has search history → FiboSearch fires this event.
	document.addEventListener( 'fibosearch/show-pre-suggestions', function () {
		var $container = $( CONTAINER_SEL );
		if ( $container.length ) {
			inject( $container );
		}
	} );

	// First-visit path: no history → FiboSearch never fires show-pre-suggestions.
	$( document ).on( 'focus', '.dgwt-wcas-search-input', function () {
		if ( $( this ).val().length > 0 ) return;
		if ( $( 'body' ).hasClass( 'dgwt-wcas-open' ) ) return;

		var $container = $( CONTAINER_SEL );
		if ( $container.length ) {
			inject( $container );
		}
	} );

	// Cleanup: FiboSearch dropdown closed.
	document.addEventListener( 'fibosearch/close', function () {
		remove();
	} );

	// Cleanup: real AJAX results are loading — remove chips before they appear.
	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( settings.url && settings.url.indexOf( 'dgwt_wcas_ajax_search' ) !== -1 ) {
			remove();
		}
	} );

	// Chip click: fill input and trigger FiboSearch's own search.
	// Use mousedown + preventDefault so the input never loses focus — prevents
	// blur → fibosearch/close → chips-removed before the click fires.
	$( document ).on( 'mousedown', '.fse-popular-chips__item', function ( e ) {
		e.preventDefault();
		var term   = $( this ).data( 'term' );
		var $input = $( '.dgwt-wcas-search-input' ).first();
		$input.val( term ).trigger( 'input' );
	} );

} )( jQuery );
