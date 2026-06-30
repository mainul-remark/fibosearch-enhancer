<?php
/**
 * Search by WooCommerce product attribute values (terms).
 *
 * Searches the pa_* taxonomies for terms matching the keyword,
 * then finds all products linked to those terms.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AttributeSearch {

    /** @var int[] Product IDs found via attribute term lookup */
    private $product_ids = [];

    public function __construct() {
        if ( fse_get_option( 'attribute_search_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                       [ $this, 'lookup' ],         6 );
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'inject_products' ], 10 );
    }

    /**
     * Look up attribute terms matching the keyword and collect product IDs.
     */
    public function lookup( $keyword ) {
        $this->product_ids = [];

        if ( empty( $keyword ) || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return $keyword;
        }

        $taxonomies = wc_get_attribute_taxonomies();
        if ( empty( $taxonomies ) ) return $keyword;

        $tax_names = array_map( function ( $t ) {
            return 'pa_' . $t->attribute_name;
        }, $taxonomies );

        global $wpdb;

        $tax_placeholders = implode( ',', array_fill( 0, count( $tax_names ), '%s' ) );
        $params           = array_merge(
            [ '%' . $wpdb->esc_like( $keyword ) . '%' ],
            $tax_names
        );

        // Find term IDs in attribute taxonomies whose name matches the keyword
        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE t.name LIKE %s
                AND tt.taxonomy IN ({$tax_placeholders})",
            $params
        ) );

        if ( empty( $term_ids ) ) return $keyword;

        // Resolve term IDs → term_taxonomy IDs (for term_relationships lookup)
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
     * Merge attribute-matched products into the raw results list.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }
}
