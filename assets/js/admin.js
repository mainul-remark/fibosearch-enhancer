/* global fse_admin, jQuery */
( function ( $ ) {
    'use strict';

    /* ── Tab switching ── */
    $( '.fse-tab' ).on( 'click', function () {
        $( '.fse-tab' ).removeClass( 'active' );
        $( '.fse-tab-content' ).removeClass( 'active' );
        $( this ).addClass( 'active' );
        $( '#tab-' + $( this ).data( 'tab' ) ).addClass( 'active' );
    } );

    /* ── Synonyms ── */
    function makeSynonymRow( value ) {
        return $( '<div class="fse-synonym-group">' +
            '<input type="text" class="fse-synonym-input regular-text" ' +
                'value="' + $( '<div>' ).text( value ).html() + '" ' +
                'placeholder="e.g. tv, television, screen, monitor">' +
            '<button type="button" class="button fse-remove-synonym" title="Remove group">✕</button>' +
            '</div>' );
    }

    // Populate from PHP-localised data
    var initialSynonyms = fse_admin.synonyms || [];
    $.each( initialSynonyms, function ( _, group ) {
        $( '#fse-synonyms-list' ).append( makeSynonymRow( group.join( ', ' ) ) );
    } );

    if ( initialSynonyms.length === 0 ) {
        $( '#fse-synonyms-list' ).append( makeSynonymRow( '' ) );
    }

    $( '#fse-add-synonym' ).on( 'click', function () {
        var $row = makeSynonymRow( '' );
        $( '#fse-synonyms-list' ).append( $row );
        $row.find( 'input' ).trigger( 'focus' );
    } );

    $( document ).on( 'click', '.fse-remove-synonym', function () {
        $( this ).closest( '.fse-synonym-group' ).remove();
    } );

    $( '#fse-save-synonyms' ).on( 'click', function () {
        var groups = [];

        $( '.fse-synonym-group' ).each( function () {
            var raw = $( this ).find( 'input' ).val().trim();
            if ( ! raw ) return;
            var terms = raw.split( ',' ).map( function ( t ) { return t.trim(); } ).filter( Boolean );
            if ( terms.length >= 2 ) {
                groups.push( terms );
            }
        } );

        var $btn    = $( this );
        var $status = $( '#fse-synonym-status' );

        $btn.prop( 'disabled', true ).text( 'Saving…' );
        $status.text( '' ).removeClass( 'fse-ok fse-err' );

        $.post( fse_admin.ajax_url, {
            action:   'fse_save_synonyms',
            nonce:    fse_admin.nonce,
            synonyms: JSON.stringify( groups ),
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $status.text( fse_admin.saved + ' (' + res.data.count + ' group(s))' ).addClass( 'fse-ok' );
            } else {
                $status.text( fse_admin.error ).addClass( 'fse-err' );
            }
        } )
        .fail( function () {
            $status.text( fse_admin.error ).addClass( 'fse-err' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Save All Groups' );
            setTimeout( function () {
                $status.text( '' ).removeClass( 'fse-ok fse-err' );
            }, 4000 );
        } );
    } );

} )( jQuery );
