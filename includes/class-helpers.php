<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_Helpers {

    /**
     * Returns true only during a FiboSearch AJAX search or search-page query.
     * DGWT_WCAS_AJAX is defined inside getSearchResults() before get_posts() is called.
     */
    public static function is_fibosearch_query() {
        return defined( 'DGWT_WCAS_AJAX' ) && DGWT_WCAS_AJAX === true;
    }

    /**
     * Safely extract the bare keyword from a FiboSearch $like value ('%keyword%').
     */
    public static function term_from_like( $like ) {
        return strtolower( trim( $like, '%' ) );
    }

    /**
     * Fetch extra WP_Post objects by ID, bypassing FiboSearch's filters
     * but keeping WooCommerce publish-status checks.
     *
     * @param int[] $ids
     * @return WP_Post[]
     */
    public static function fetch_products_by_ids( array $ids ) {
        if ( empty( $ids ) ) return [];

        return get_posts( [
            'post__in'            => array_map( 'intval', $ids ),
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => count( $ids ),
            'suppress_filters'    => true,   // avoid re-triggering FiboSearch hooks
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ] );
    }

    /**
     * Merge extra WP_Post objects into the products_raw array, skipping duplicates.
     *
     * @param WP_Post[] $products  Existing products array from products_raw filter.
     * @param int[]     $extra_ids IDs to inject.
     * @return WP_Post[]
     */
    public static function merge_extra_products( array $products, array $extra_ids ) {
        if ( empty( $extra_ids ) ) return $products;

        $existing_ids = wp_list_pluck( $products, 'ID' );
        $new_ids      = array_values( array_diff( $extra_ids, $existing_ids ) );

        if ( empty( $new_ids ) ) return $products;

        $extra = self::fetch_products_by_ids( $new_ids );
        return array_merge( $products, $extra );
    }
}
