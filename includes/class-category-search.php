<?php
/**
 * Search products by their WooCommerce product category (product_cat).
 *
 * FiboSearch's native category matching only surfaces product_cat as an
 * autocomplete link to the category archive — it never injects the
 * category's own products into the actual product results. This class fills
 * that gap: if the keyword matches a product_cat term name, every product
 * assigned to that category is injected into the results, the same way
 * FSE_TagSearch does for product_tag.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_CategorySearch {

    /** @var int[] Product IDs found via category lookup */
    private $product_ids = [];

    public function __construct() {
        if ( fse_get_option( 'category_search_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                       [ $this, 'lookup' ],         7 );
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'inject_products' ], 10 );
    }

    /**
     * Find product IDs whose product_cat terms match the keyword.
     */
    public function lookup( $keyword ) {
        $this->product_ids = [];

        if ( empty( $keyword ) ) return $keyword;

        global $wpdb;

        [ $name_sql, $name_params ] = FSE_Helpers::name_match_sql( 't.name', $keyword );

        // Find matching product_cat term IDs
        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE tt.taxonomy = 'product_cat'
                AND {$name_sql}",
            $name_params
        ) );

        // Whole-phrase/collapsed match found nothing — fall back to a
        // phonetic (SOUNDEX) match on any single significant word. Category
        // names are short, distinctive labels, so a real letter-level
        // misspelling ("skeen care" for "Skin Care") never matches via
        // substring matching alone no matter how spacing is normalized.
        if ( empty( $term_ids ) ) {
            $term_ids = $this->lookup_by_soundex( $keyword );
        }

        if ( empty( $term_ids ) ) return $keyword;

        // term_id → term_taxonomy_id
        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
        $tt_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT term_taxonomy_id
               FROM {$wpdb->term_taxonomy}
              WHERE term_id IN ({$placeholders})
                AND taxonomy = 'product_cat'",
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
     * Match any single significant word in the phrase phonetically against
     * a product_cat term name (SOUNDEX), OR'd together.
     *
     * @return int[] term_ids
     */
    private function lookup_by_soundex( string $keyword ): array {
        $words = FSE_Helpers::significant_words( $keyword );
        if ( empty( $words ) ) return [];

        global $wpdb;

        $or_fragments = [];
        $params       = [];

        foreach ( $words as $word ) {
            [ $word_sql, $word_params ] = FSE_Helpers::soundex_match_sql( 't.name', $word );
            $or_fragments[] = $word_sql;
            array_push( $params, ...$word_params );
        }

        $where = '(' . implode( ' OR ', $or_fragments ) . ')';

        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE tt.taxonomy = 'product_cat'
                AND {$where}",
            $params
        ) ) );
    }

    /**
     * Merge category-matched products into the raw results list, skipping duplicates.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }

    /**
     * Product IDs matched via category lookup for the current search.
     * Consumed by FSE_FieldWeightScore.
     *
     * @return int[]
     */
    public function get_matched_ids(): array {
        return $this->product_ids;
    }
}
