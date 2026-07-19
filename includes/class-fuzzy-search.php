<?php
/**
 * Fuzzy / typo-tolerant search.
 *
 * Two layers:
 *   1. SOUNDEX — matches words that sound the same (e.g. "nikey" → "nike").
 *      Works best for single-word product titles; MySQL SOUNDEX() operates on
 *      the full title string as one token for multi-word titles.
 *   2. Prefix match — if the keyword is ≥ 5 chars, also match titles that start
 *      with the first (N-1) characters, tolerating one trailing-char typo.
 *
 * Both conditions are OR'd inside each search term's existing group, so they
 * complement FiboSearch's LIKE search rather than replacing it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_FuzzySearch {

    /** Minimum keyword length to activate fuzzy matching */
    const MIN_LENGTH = 4;

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

        if ( strlen( $term ) < self::MIN_LENGTH ) return $search;
        if ( FSE_Helpers::is_stopword( $term ) ) return $search;

        global $wpdb;

        // 1. SOUNDEX — phonetic match on the full post_title
        $search .= $wpdb->prepare(
            " OR (SOUNDEX({$wpdb->posts}.post_title) = SOUNDEX(%s))",
            $term
        );

        // 2. Prefix match — handles single trailing-character typos
        if ( strlen( $term ) >= 5 ) {
            $prefix = substr( $term, 0, -1 ); // drop last char
            $search .= $wpdb->prepare(
                " OR ({$wpdb->posts}.post_title LIKE %s)",
                '%' . $wpdb->esc_like( $prefix ) . '%'
            );
        }

        return $search;
    }
}
