<?php
/**
 * Search products by custom store taxonomies not covered by any other
 * module: brand, age-range, skin-type, keywords.
 *
 * FSE_AttributeSearch only walks WooCommerce's pa_* attribute taxonomies,
 * and FSE_TagSearch only covers product_tag — these custom taxonomies fall
 * through both. Same term-name-match → inject-products approach as
 * FSE_TagSearch, generalized across a configurable taxonomy list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_CustomTaxonomySearch {

    /** Custom product taxonomies to search, beyond product_cat/product_tag/pa_*. */
    const TAXONOMIES = [ 'brand', 'age-range', 'skin-type', 'keywords' ];

    /** @var int[] Product IDs found via taxonomy term lookup */
    private $product_ids = [];

    public function __construct() {
        if ( fse_get_option( 'custom_taxonomy_search_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                       [ $this, 'lookup' ],         8 );
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'inject_products' ], 10 );
    }

    /**
     * Find product IDs whose terms in any configured taxonomy match the
     * keyword. Taxonomies that aren't registered on this install are
     * skipped safely.
     */
    public function lookup( $keyword ) {
        $this->product_ids = [];

        if ( empty( $keyword ) ) return $keyword;

        $taxonomies = array_values( array_filter( self::TAXONOMIES, 'taxonomy_exists' ) );
        if ( empty( $taxonomies ) ) return $keyword;

        global $wpdb;

        $tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $params           = array_merge(
            [ '%' . $wpdb->esc_like( $keyword ) . '%' ],
            $taxonomies
        );

        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE t.name LIKE %s
                AND tt.taxonomy IN ({$tax_placeholders})",
            $params
        ) );

        if ( empty( $term_ids ) ) return $keyword;

        $term_id_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
        $tt_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT term_taxonomy_id
               FROM {$wpdb->term_taxonomy}
              WHERE term_id IN ({$term_id_placeholders})",
            $term_ids
        ) );

        if ( empty( $tt_ids ) ) return $keyword;

        $tt_placeholders = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
        $this->product_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT object_id
               FROM {$wpdb->term_relationships}
              WHERE term_taxonomy_id IN ({$tt_placeholders})",
            $tt_ids
        ) ) );

        return $keyword;
    }

    /**
     * Merge taxonomy-matched products into the raw results list.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }
}
