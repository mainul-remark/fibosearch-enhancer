<?php
/**
 * Search inside product custom meta fields.
 *
 * Automatically searches ALL public meta fields (keys not starting with '_').
 * WordPress/WooCommerce internal fields (_price, _stock, _sku, etc.) all use
 * the underscore prefix convention, so excluding them keeps results clean
 * without any manual configuration needed.
 *
 * Uses FiboSearch's own join and search_or hooks so conditions live inside
 * each search term's OR group — preserving AND-per-word logic for multi-word searches.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_CustomFieldSearch {

    /*
     * Manual meta key list — commented out.
     * Now works automatically: searches all public (non-underscore-prefixed) meta fields.
     *
     * private $meta_keys = [];
     */

    public function __construct() {
        if ( fse_get_option( 'custom_fields_enabled', '1' ) !== '1' ) return;

        /*
         * Manual meta key loading — commented out.
         * No longer requires input; all public meta fields are searched automatically.
         *
         * $raw = fse_get_option( 'custom_field_keys', '' );
         * if ( empty( $raw ) ) return;
         *
         * $this->meta_keys = array_values( array_filter(
         *     array_map( 'trim', explode( "\n", $raw ) )
         * ) );
         *
         * if ( empty( $this->meta_keys ) ) return;
         */

        // FiboSearch fires these filters only inside isAjaxSearch() — no extra guard needed.
        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
    }

    /**
     * Automatically search all public meta fields (those whose key does NOT
     * start with '_'). WordPress/WooCommerce internal fields (_price, _stock,
     * _sku, etc.) all use the underscore prefix convention, so this safely
     * targets only user-defined / plugin-defined custom fields.
     *
     * Uses a correlated EXISTS subquery against postmeta rather than joining
     * it into the main query — an unconditional LEFT JOIN against the whole
     * postmeta table multiplies every candidate row by however many meta
     * rows that post has (tens of rows per product on this catalog) BEFORE
     * any of this module's own filtering runs, and that multiplied result
     * has to be carried through every other module's OR conditions in the
     * same WHERE clause and then DISTINCT-deduplicated. Measured on a real
     * search: that join was 3.5s of a 4.7s total request, the dominant cost
     * by far. EXISTS lets MySQL check postmeta per-candidate-row without
     * ever materializing the full cross product.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern, e.g. '%keyword%'.
     * @param object $engine  FiboSearch Search engine instance (unused).
     */
    public function add_conditions( $search, $like, $engine ) {
        global $wpdb;

        // In $wpdb->prepare(): \\ = literal backslash, _ = underscore, %% = literal %
        $condition = $wpdb->prepare(
            "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key NOT LIKE '\\_%%' AND meta_value LIKE %s )",
            $like
        );

        return $search . " OR {$condition}";
    }
}
