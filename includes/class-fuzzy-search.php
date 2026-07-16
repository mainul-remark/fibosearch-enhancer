<?php
/**
 * Fuzzy / typo-tolerant search.
 *
 * Two layers:
 *   1. SOUNDEX — matches words that sound the same (e.g. "nikey" → "nike",
 *      "siodol" → "SIODIL"). Compared per-word against the title (up to
 *      MAX_WORDS words) rather than the whole title string, since MySQL's
 *      SOUNDEX() of a multi-word title never equals the SOUNDEX() of a
 *      single search term.
 *   2. Prefix match — also match titles that start with the first (N-1)
 *      characters, tolerating one trailing-char typo.
 *
 * Both conditions are OR'd inside each search term's existing group, so they
 * complement FiboSearch's LIKE search rather than replacing it.
 *
 * The minimum keyword length that activates both layers is configurable via
 * the admin panel (fuzzy_min_length), falling back to DEFAULT_MIN_LENGTH.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_FuzzySearch {

    /** Fallback minimum keyword length if no admin value is set */
    const DEFAULT_MIN_LENGTH = 4;

    /**
     * Max words per title checked by the per-word SOUNDEX comparison.
     * Catalog title lengths average ~6.5 words; brand/product names that
     * fuzzy search targets sit early in the title, so 6 covers the bulk of
     * titles without paying for the long tail (some run to 17 words) on
     * every row of every search.
     */
    const MAX_WORDS = 6;

    public function __construct() {
        if ( fse_get_option( 'fuzzy_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
    }

    /**
     * Add SOUNDEX and prefix-match OR conditions for the current search term.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern, e.g. '%keyword%'.
     * @param object $engine  FiboSearch Search engine instance.
     */
    public function add_conditions( $search, $like, $engine ) {
        $term = FSE_Helpers::term_from_like( $like );
        $min_length = $this->get_min_length();

        if ( strlen( $term ) < $min_length ) return $search;

        global $wpdb;

        // 1. SOUNDEX — phonetic match against each word of the title
        for ( $i = 1; $i <= self::MAX_WORDS; $i++ ) {
            $search .= $wpdb->prepare(
                " OR (SOUNDEX(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(CONCAT({$wpdb->posts}.post_title, ' '), ' ', %d), ' ', -1))) = SOUNDEX(%s))",
                $i,
                $term
            );
        }

        // 2. Prefix match — handles single trailing-character typos
        $prefix = substr( $term, 0, -1 ); // drop last char
        $search .= $wpdb->prepare(
            " OR ({$wpdb->posts}.post_title LIKE %s)",
            '%' . $wpdb->esc_like( $prefix ) . '%'
        );

        return $search;
    }

    /**
     * Minimum keyword length to activate fuzzy matching, from admin settings.
     */
    private function get_min_length() {
        $value = (int) fse_get_option( 'fuzzy_min_length', self::DEFAULT_MIN_LENGTH );
        return $value > 0 ? $value : self::DEFAULT_MIN_LENGTH;
    }
}
