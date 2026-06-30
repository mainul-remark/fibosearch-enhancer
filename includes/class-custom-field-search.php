<?php
/**
 * Search inside configured custom meta fields.
 *
 * Uses FiboSearch's own join and search_or hooks so the custom-field
 * conditions live inside each search term's OR group — preserving
 * the correct AND-per-word logic for multi-word searches.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_CustomFieldSearch {

    /** @var string[] Meta keys to search in */
    private $meta_keys = [];

    public function __construct() {
        if ( fse_get_option( 'custom_fields_enabled', '1' ) !== '1' ) return;

        $raw = fse_get_option( 'custom_field_keys', '' );
        if ( empty( $raw ) ) return;

        $this->meta_keys = array_values( array_filter(
            array_map( 'trim', explode( "\n", $raw ) )
        ) );

        if ( empty( $this->meta_keys ) ) return;

        // FiboSearch fires these filters only inside isAjaxSearch() — no extra guard needed.
        add_filter( 'dgwt/wcas/native/search_query/join',       [ $this, 'add_join' ] );
        add_filter( 'dgwt/wcas/native/search_query/search_or',  [ $this, 'add_conditions' ], 10, 3 );
        add_filter( 'dgwt/wcas/native/search_query/search_or',  [ $this, 'ensure_distinct' ], 10, 3 );
    }

    /**
     * Add a LEFT JOIN on postmeta with a unique alias (fse_cf) so our
     * conditions don't collide with FiboSearch's SKU join (dgwt_wcasmsku).
     */
    public function add_join( $join ) {
        global $wpdb;
        $join .= " LEFT JOIN {$wpdb->postmeta} AS fse_cf ON ({$wpdb->posts}.ID = fse_cf.post_id)";
        return $join;
    }

    /**
     * Inject an OR condition for each search term that matches the configured
     * custom fields. FiboSearch closes the current term's group with ')' after
     * this filter returns, so we're safely inside that group.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern, e.g. '%keyword%'.
     * @param object $engine  FiboSearch Search engine instance (unused).
     */
    public function add_conditions( $search, $like, $engine ) {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $this->meta_keys ), '%s' ) );
        $args         = array_merge( $this->meta_keys, [ $like ] );

        $condition = $wpdb->prepare(
            "(fse_cf.meta_key IN ({$placeholders}) AND fse_cf.meta_value LIKE %s)",
            $args
        );

        return $search . " OR {$condition}";
    }

    /**
     * The LEFT JOIN can produce duplicate rows — ensure DISTINCT is active.
     * FiboSearch already sets DISTINCT via posts_distinct at priority 501,
     * but this is a safety guard in case it's ever skipped.
     */
    public function ensure_distinct( $search, $like, $engine ) {
        // No-op here: handled by FiboSearch's searchDistinct at priority 501.
        // If you remove FiboSearch's distinct, add a posts_distinct hook here.
        return $search;
    }
}
