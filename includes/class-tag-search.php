<?php
/**
 * Search products by their WooCommerce product tags.
 *
 * FiboSearch only shows tags as autocomplete suggestions (links to tag archive
 * pages) — it does not use tags to find individual products. This class fills
 * that gap: if the keyword matches any product_tag term, all products carrying
 * that tag are injected into the search results.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_TagSearch {

    /** @var int[] Product IDs found via tag lookup */
    private $product_ids = [];

    public function __construct() {
        if ( fse_get_option( 'tag_search_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                       [ $this, 'lookup' ],         7 );
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'inject_products' ], 10 );
    }

    /**
     * Find product IDs whose product_tag terms match the keyword.
     */
    public function lookup( $keyword ) {
        $this->product_ids = [];

        if ( empty( $keyword ) ) return $keyword;

        global $wpdb;

        // Find matching product_tag term IDs (name LIKE '%keyword%')
        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE tt.taxonomy = 'product_tag'
                AND t.name LIKE %s",
            '%' . $wpdb->esc_like( $keyword ) . '%'
        ) );

        if ( empty( $term_ids ) ) return $keyword;

        // term_id → term_taxonomy_id
        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
        $tt_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT term_taxonomy_id
               FROM {$wpdb->term_taxonomy}
              WHERE term_id IN ({$placeholders})
                AND taxonomy = 'product_tag'",
            $term_ids
        ) );

        if ( empty( $tt_ids ) ) return $keyword;

        // term_taxonomy_id → product post IDs
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
     * Merge tag-matched products into the raw results list, skipping duplicates.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }
}
