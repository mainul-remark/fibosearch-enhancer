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

        [ $name_sql, $name_params ] = FSE_Helpers::name_match_sql( 't.name', $keyword );

        $tax_placeholders = implode( ',', array_fill( 0, count( $tax_names ), '%s' ) );
        $params           = array_merge( $name_params, $tax_names );

        // Find term IDs in attribute taxonomies whose name matches the keyword
        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE {$name_sql}
                AND tt.taxonomy IN ({$tax_placeholders})",
            $params
        ) );

        // Whole-phrase/collapsed match found nothing — fall back to matching
        // any single significant word against a pa_colors (shade) term name.
        // Several shade names on this store already carry real undertone
        // words in the title itself (e.g. "Toast 4W Warm", "Latte 1C Cool",
        // "Amaretto 3N Neutral") — this lets "warm concealer" find those
        // without requiring the exact full shade name. Scoped to pa_colors
        // only: we don't have verified undertone data for shades that don't
        // already say it in the name, so we only surface what's already
        // literally there rather than guess.
        if ( empty( $term_ids ) && in_array( 'pa_colors', $tax_names, true ) ) {
            $term_ids = $this->lookup_shade_by_significant_words( $keyword );
        }

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
     * Match any single significant word in the phrase against a pa_colors
     * term name, OR'd together — e.g. "warm" against "Toast 4W Warm".
     *
     * @return int[] term_ids
     */
    private function lookup_shade_by_significant_words( string $keyword ): array {
        $words = FSE_Helpers::significant_words( $keyword );
        if ( empty( $words ) ) return [];

        global $wpdb;

        $or_fragments = [];
        $params       = [];

        foreach ( $words as $word ) {
            [ $word_sql, $word_params ] = FSE_Helpers::name_match_sql( 't.name', $word );
            $or_fragments[] = $word_sql;
            array_push( $params, ...$word_params );
        }

        $where = '(' . implode( ' OR ', $or_fragments ) . ')';
        $params[] = 'pa_colors';

        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE {$where}
                AND tt.taxonomy = %s",
            $params
        ) ) );
    }

    /**
     * Merge attribute-matched products into the raw results list.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }

    /**
     * Product IDs matched via attribute lookup for the current search.
     * Consumed by FSE_FieldWeightScore.
     *
     * @return int[]
     */
    public function get_matched_ids(): array {
        return $this->product_ids;
    }
}
