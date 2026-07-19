<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_Helpers {

    /**
     * Common short English words that carry no product-identifying signal on
     * their own. Widening a search on one of these (as a synonym trigger,
     * fuzzy match, etc.) mostly pulls in noise rather than relevant products.
     */
    const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'for', 'with', 'of', 'to', 'in', 'on',
        'at', 'by', 'is', 'are', 'this', 'that', 'from', 'it', 'as', 'be',
    ];

    /**
     * Returns true only during a FiboSearch AJAX search or search-page query.
     * DGWT_WCAS_AJAX is defined inside getSearchResults() before get_posts() is called.
     */
    public static function is_fibosearch_query() {
        return defined( 'DGWT_WCAS_AJAX' ) && DGWT_WCAS_AJAX === true;
    }

    /**
     * Whether a (lowercased, trimmed) word is a stopword — not worth using as
     * a trigger for synonym/fuzzy widening.
     */
    public static function is_stopword( string $word ): bool {
        return in_array( $word, self::STOPWORDS, true );
    }

    /**
     * Meaningful words in a phrase: lowercased, stopwords and short words
     * dropped. Used to fall back to per-word term-name matching when a
     * descriptive multi-word query (e.g. "dark circle removing cream")
     * doesn't match any term name as a whole phrase.
     *
     * @return string[]
     */
    public static function significant_words( string $keyword, int $min_length = 4 ): array {
        $words = preg_split( '/\s+/', strtolower( trim( $keyword ) ) );

        return array_values( array_filter( $words, function ( $word ) use ( $min_length ) {
            return $word !== '' && mb_strlen( $word ) >= $min_length && ! self::is_stopword( $word );
        } ) );
    }

    /**
     * Safely extract the bare keyword from a FiboSearch $like value ('%keyword%').
     */
    public static function term_from_like( $like ) {
        return strtolower( trim( $like, '%' ) );
    }

    /**
     * Strip spaces/hyphens/underscores so word-boundary punctuation doesn't
     * block a match — e.g. "menscare" and "Mens Care" collapse to the same
     * string.
     */
    public static function collapse( string $str ): string {
        return str_replace( [ ' ', '-', '_' ], '', $str );
    }

    /**
     * Build a "does $column match $keyword" WHERE fragment for term/taxonomy
     * name lookups that also matches when spaces/hyphens/underscores are
     * ignored on the stored side — so a customer typing a squashed-together
     * word like "menscare" still finds a term named "Mens Care".
     *
     * @return array{0: string, 1: array} [$sql_fragment, $params] — the
     *         fragment contains two %s placeholders, to be prepared in order
     *         alongside any other placeholders in the caller's query.
     */
    public static function name_match_sql( string $column, string $keyword ): array {
        global $wpdb;

        $sql = "({$column} LIKE %s OR REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '_', '') LIKE %s)";
        $params = [
            '%' . $wpdb->esc_like( $keyword ) . '%',
            '%' . $wpdb->esc_like( self::collapse( $keyword ) ) . '%',
        ];

        return [ $sql, $params ];
    }

    /**
     * Build a "does $column phonetically match $word" WHERE fragment using
     * SOUNDEX — catches real letter-level misspellings of a proper noun
     * (brand/category names) that substring matching never will, e.g.
     * "soidil" / SOUNDEX S340 matching term name "Siodil" / SOUNDEX S340.
     * Only meaningful for single words, not multi-word phrases.
     *
     * @return array{0: string, 1: array} [$sql_fragment, $params]
     */
    public static function soundex_match_sql( string $column, string $word ): array {
        return [ "SOUNDEX({$column}) = SOUNDEX(%s)", [ $word ] ];
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
