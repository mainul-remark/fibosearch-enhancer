/**
 * Captures search-result impressions and clicks by observing FiboSearch's
 * own AJAX responses and rendered DOM, rather than patching FiboSearch's
 * internals — resilient to FiboSearch JS updates.
 */
( function ( $ ) {
	'use strict';

	if ( typeof fseTrack === 'undefined' ) return;

	// Most recent search's suggestions, indexed exactly as FiboSearch
	// indexes them in the DOM's data-index attribute (confirmed: includes
	// non-product entries like headlines/taxonomy terms).
	var lastSuggestions = [];
	var lastKeyword = '';

	function extractKeywordFromUrl( url ) {
		var match = /[?&]s=([^&]*)/.exec( url );
		return match ? decodeURIComponent( match[ 1 ].replace( /\+/g, ' ' ) ) : '';
	}

	function sendEvents( events ) {
		if ( ! events.length ) return;

		var body = new URLSearchParams();
		body.set( 'action', 'fse_track_event' );
		body.set( 'nonce', fseTrack.nonce );
		body.set( 'events', JSON.stringify( events ) );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				fseTrack.ajax_url,
				new Blob( [ body.toString() ], { type: 'application/x-www-form-urlencoded' } )
			);
		} else {
			$.post( fseTrack.ajax_url, Object.fromEntries( body ) );
		}
	}

	// Impressions: fires after every FiboSearch search AJAX call completes.
	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! settings.url || settings.url.indexOf( 'dgwt_wcas_ajax_search' ) === -1 ) return;

		var keyword = extractKeywordFromUrl( settings.url );
		if ( ! keyword ) return;

		var data;
		try {
			data = typeof xhr.responseJSON !== 'undefined' ? xhr.responseJSON : JSON.parse( xhr.responseText );
		} catch ( e ) {
			return;
		}
		if ( ! data || ! Array.isArray( data.suggestions ) ) return;

		lastKeyword = keyword.toLowerCase();
		lastSuggestions = data.suggestions;

		var impressions = [];
		data.suggestions.forEach( function ( suggestion, index ) {
			if ( suggestion.type !== 'product' || ! suggestion.post_id ) return;
			impressions.push( {
				type: 'impression',
				keyword: lastKeyword,
				product_id: suggestion.post_id,
				position: index,
			} );
		} );

		sendEvents( impressions );
	} );

	// Clicks: delegated listener on FiboSearch's rendered suggestion items.
	$( document ).on( 'click', '.dgwt-wcas-suggestion', function () {
		var index = parseInt( $( this ).attr( 'data-index' ), 10 );
		if ( isNaN( index ) || ! lastSuggestions[ index ] ) return;

		var suggestion = lastSuggestions[ index ];
		if ( suggestion.type !== 'product' || ! suggestion.post_id ) return;

		sendEvents( [ {
			type: 'click',
			keyword: lastKeyword,
			product_id: suggestion.post_id,
			position: index,
		} ] );
	} );

} )( jQuery );
