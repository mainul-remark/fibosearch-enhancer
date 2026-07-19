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

    /* ── Priority Mapping ── */
    var fseCategories = fse_admin.categories || [];

    function fseCategoryOptions( selected ) {
        var html = '<option value="">— none —</option>';
        $.each( fseCategories, function ( _, cat ) {
            var sel = cat.slug === selected ? ' selected' : '';
            html += '<option value="' + cat.slug + '"' + sel + '>' + $( '<div>' ).text( cat.name ).html() + '</option>';
        } );
        return html;
    }

    function makePriorityRow( row ) {
        row = row || {};
        var relevant = row.relevant_subcategories || [];

        var $tr = $( '<tr class="fse-priority-row"></tr>' );

        $tr.append(
            '<td><input type="text" class="fse-pm-keyword regular-text" value="' +
                $( '<div>' ).text( row.keyword || '' ).html() + '" placeholder="e.g. sunsilk"></td>'
        );
        $tr.append(
            '<td><input type="text" class="fse-pm-product regular-text" value="' +
                $( '<div>' ).text( row.product || '' ).html() + '" placeholder="Exact product title or ID"></td>'
        );
        $tr.append( '<td><select class="fse-pm-main">' + fseCategoryOptions( row.main_subcategory || '' ) + '</select></td>' );
        $tr.append( '<td><select class="fse-pm-relevant">' + fseCategoryOptions( relevant[0] || '' ) + '</select></td>' );
        $tr.append( '<td><select class="fse-pm-relevant">' + fseCategoryOptions( relevant[1] || '' ) + '</select></td>' );
        $tr.append( '<td><select class="fse-pm-relevant">' + fseCategoryOptions( relevant[2] || '' ) + '</select></td>' );
        $tr.append( '<td><select class="fse-pm-relevant">' + fseCategoryOptions( relevant[3] || '' ) + '</select></td>' );
        $tr.append( '<td><select class="fse-pm-parent">' + fseCategoryOptions( row.parent_category || '' ) + '</select></td>' );
        $tr.append( '<td><button type="button" class="button fse-remove-priority-row" title="Remove row">✕</button></td>' );

        return $tr;
    }

    var initialMapping = fse_admin.priority_mapping || [];
    $.each( initialMapping, function ( _, row ) {
        $( '#fse-priority-mapping-rows' ).append( makePriorityRow( row ) );
    } );
    if ( initialMapping.length === 0 ) {
        $( '#fse-priority-mapping-rows' ).append( makePriorityRow() );
    }

    $( '#fse-add-priority-row' ).on( 'click', function () {
        $( '#fse-priority-mapping-rows' ).append( makePriorityRow() );
    } );

    $( document ).on( 'click', '.fse-remove-priority-row', function () {
        $( this ).closest( 'tr' ).remove();
    } );

    $( '#fse-save-priority-mapping' ).on( 'click', function () {
        var mapping = [];

        $( '.fse-priority-row' ).each( function () {
            var $row     = $( this );
            var keyword  = $row.find( '.fse-pm-keyword' ).val().trim();
            var product  = $row.find( '.fse-pm-product' ).val().trim();
            if ( ! keyword || ! product ) return;

            var $relevantSelects = $row.find( '.fse-pm-relevant' );
            mapping.push( {
                keyword:           keyword,
                product:           product,
                main_subcategory:  $row.find( '.fse-pm-main' ).val(),
                relevant1:         $( $relevantSelects.get( 0 ) ).val(),
                relevant2:         $( $relevantSelects.get( 1 ) ).val(),
                relevant3:         $( $relevantSelects.get( 2 ) ).val(),
                relevant4:         $( $relevantSelects.get( 3 ) ).val(),
                parent_category:   $row.find( '.fse-pm-parent' ).val(),
            } );
        } );

        var $btn        = $( this );
        var $status     = $( '#fse-priority-mapping-status' );
        var $errorsBox   = $( '#fse-priority-mapping-errors' );

        $btn.prop( 'disabled', true ).text( 'Saving…' );
        $status.text( '' ).removeClass( 'fse-ok fse-err' );
        $errorsBox.hide().empty();

        $.post( fse_admin.ajax_url, {
            action:  'fse_save_priority_mapping',
            nonce:   fse_admin.nonce,
            mapping: JSON.stringify( mapping ),
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $status.text( fse_admin.saved + ' (' + res.data.count + ' row(s) saved)' ).addClass( 'fse-ok' );
                if ( res.data.errors && res.data.errors.length ) {
                    var html = '<strong>' + res.data.errors.length + ' row(s) were not saved:</strong><ul style="margin:6px 0 0 18px;">';
                    $.each( res.data.errors, function ( _, msg ) {
                        html += '<li>' + $( '<div>' ).text( msg ).html() + '</li>';
                    } );
                    html += '</ul>';
                    $errorsBox.html( html ).show();
                }
            } else {
                $status.text( fse_admin.error ).addClass( 'fse-err' );
            }
        } )
        .fail( function () {
            $status.text( fse_admin.error ).addClass( 'fse-err' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Save All Rows' );
        } );
    } );

} )( jQuery );
