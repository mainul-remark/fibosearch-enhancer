<?php
/**
 * Search by WooCommerce product variation SKU.
 *
 * Finds parent products whose variations have a SKU matching the full keyword.
 * Results are injected into the products_raw list after the primary query.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_VariationSkuSearch {

    /** @var int[] Parent product IDs found via variation SKU lookup */
    private $parent_ids = [];

    public function __construct() {
        if ( fse_get_option( 'variation_sku_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                          [ $this, 'lookup' ],         5 );
        add_filter( 'dgwt/wcas/search_results/products_raw',    [ $this, 'inject_products' ], 10 );
    }

    /**
     * When the search phrase is set, query for variation IDs whose _sku matches,
     * then collect their parent product IDs.
     */
    public function lookup( $keyword ) {
        $this->parent_ids = [];

        if ( empty( $keyword ) ) return $keyword;

        global $wpdb;

        $variation_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
              WHERE p.post_type   = 'product_variation'
                AND p.post_status = 'publish'
                AND pm.meta_key   = '_sku'
                AND pm.meta_value LIKE %s",
            '%' . $wpdb->esc_like( $keyword ) . '%'
        ) );

        if ( empty( $variation_ids ) ) return $keyword;

        foreach ( $variation_ids as $vid ) {
            $parent = wp_get_post_parent_id( (int) $vid );
            if ( $parent ) {
                $this->parent_ids[] = (int) $parent;
            }
        }

        $this->parent_ids = array_unique( $this->parent_ids );

        return $keyword;
    }

    /**
     * Merge variation-SKU-matched parent products into the raw results list.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->parent_ids );
    }
}
