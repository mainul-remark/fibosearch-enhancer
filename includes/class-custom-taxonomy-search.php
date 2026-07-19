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

        [ $name_sql, $name_params ] = FSE_Helpers::name_match_sql( 't.name', $keyword );

        $tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
        $params           = array_merge( $name_params, $taxonomies );

        $term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT t.term_id
               FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE {$name_sql}
                AND tt.taxonomy IN ({$tax_placeholders})",
            $params
        ) );

        // Whole-phrase match found nothing — fall back to matching any single
        // significant word against a 'keywords' term name. "keywords" is the
        // curated benefit-tag taxonomy (e.g. "Reduces Dark Spot", "Anti
        // Aging") — exactly the mechanism meant to catch a descriptive
        // sentence-style query like "dark circle removing cream", but only
        // if lookup can match on the one meaningful word inside it rather
        // than requiring the whole sentence to match a tag name verbatim.
        // Scoped to 'keywords' only (not brand/age-range/skin-type) since
        // those are exact-label taxonomies where partial-word matching would
        // mostly just add noise.
        if ( empty( $term_ids ) && in_array( 'keywords', $taxonomies, true ) ) {
            $term_ids = $this->lookup_by_significant_words( $keyword );
        }

        // Still nothing — try a phonetic (SOUNDEX) match against 'brand'
        // term names. Brand names are proper nouns, so real letter-level
        // misspellings ("soidil" for "Siodil", "niir" for "Nior") never
        // match via substring/collapsed matching no matter how the spacing
        // is normalized — SOUNDEX catches the ones that sound alike even
        // when several letters differ. Scoped to 'brand' only, since a
        // phonetic match on age-range/skin-type values would be meaningless
        // (they're not proper nouns) and on 'keywords' would risk noise
        // across a much larger, more generic term set.
        if ( empty( $term_ids ) && in_array( 'brand', $taxonomies, true ) ) {
            $term_ids = $this->lookup_brand_by_soundex( $keyword );
        }

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
     * Match any single significant word in the phrase against a 'keywords'
     * term name, OR'd together.
     *
     * @return int[] term_ids
     */
    private function lookup_by_significant_words( string $keyword ): array {
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
        $params[] = 'keywords';

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
     * Match any single significant word in the phrase phonetically against
     * a 'brand' term name (SOUNDEX), OR'd together. Skips words under 4
     * characters — SOUNDEX collisions on very short words are too common to
     * be meaningful.
     *
     * @return int[] term_ids
     */
    private function lookup_brand_by_soundex( string $keyword ): array {
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
        $params[] = 'brand';

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
     * Merge taxonomy-matched products into the raw results list.
     */
    public function inject_products( $products ) {
        return FSE_Helpers::merge_extra_products( $products, $this->product_ids );
    }

    /**
     * Product IDs matched via taxonomy lookup for the current search.
     * Consumed by FSE_FieldWeightScore.
     *
     * @return int[]
     */
    public function get_matched_ids(): array {
        return $this->product_ids;
    }
}
