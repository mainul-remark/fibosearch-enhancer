<?php
/**
 * Search inside product custom meta fields.
 *
 * Automatically searches ALL public meta fields (keys not starting with '_').
 * WordPress/WooCommerce internal fields (_price, _stock, _sku, etc.) all use
 * the underscore prefix convention, so excluding them keeps results clean
 * without any manual configuration needed.
 *
 * Uses an EXISTS subquery rather than a JOIN. FiboSearch core already joins
 * wp_postmeta once (unrestricted, for its own SKU search); a second
 * unrestricted JOIN to the same table here would multiply every product row
 * by (postmeta rows per product)^2 before the WHERE clause even runs — with
 * ~100 meta rows per product that's ~10,000x row inflation, which is what
 * made searches take minutes and peg MySQL's CPU. EXISTS evaluates per
 * product row without multiplying the result set.
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

        // FiboSearch fires this filter only inside isAjaxSearch() — no extra guard needed.
        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
    }

    /**
     * Automatically search all public meta fields (those whose key does NOT
     * start with '_'). WordPress/WooCommerce internal fields (_price, _stock,
     * _sku, etc.) all use the underscore prefix convention, so this safely
     * targets only user-defined / plugin-defined custom fields.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern, e.g. '%keyword%'.
     * @param object $engine  FiboSearch Search engine instance (unused).
     */
    public function add_conditions( $search, $like, $engine ) {
        global $wpdb;

        /*
         * Manual meta_key IN() condition — commented out.
         * Replaced by the automatic NOT LIKE '\_%' filter below.
         *
         * $placeholders = implode( ',', array_fill( 0, count( $this->meta_keys ), '%s' ) );
         * $args         = array_merge( $this->meta_keys, [ $like ] );
         * $condition = $wpdb->prepare(
         *     "(fse_cf.meta_key IN ({$placeholders}) AND fse_cf.meta_value LIKE %s)",
         *     $args
         * );
         */

        // Search all public meta fields automatically (keys not starting with '_')
        // In $wpdb->prepare(): \\ = literal backslash, _ = underscore, %% = literal %
        $condition = $wpdb->prepare(
            "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} AS fse_cf
                 WHERE fse_cf.post_id = {$wpdb->posts}.ID
                   AND fse_cf.meta_key NOT LIKE '\\_%%'
                   AND fse_cf.meta_value LIKE %s
            )",
            $like
        );

        return $search . " OR {$condition}";
    }
}
