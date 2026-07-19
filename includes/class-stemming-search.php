<?php
/**
 * Singular / plural matching.
 *
 * Plain LIKE '%term%' matching means "serum" never matches "serums" and
 * "moisturizers" never matches "moisturizer" — a large, cheap-to-fix chunk of
 * missed matches compared to fuzzy/SOUNDEX tuning. Applies a small set of
 * English pluralization rules to each search term and OR's the variant into
 * that term's existing group, same pattern as FSE_FuzzySearch.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_StemmingSearch {

    /** Minimum term length to attempt stemming (avoids noisy short-word variants) */
    const MIN_LENGTH = 3;

    public function __construct() {
        if ( fse_get_option( 'stemming_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
    }

    /**
     * Add OR conditions for the singular/plural variant(s) of the current
     * search term.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern, e.g. '%serums%'.
     * @param object $engine  FiboSearch Search engine instance (unused).
     */
    public function add_conditions( $search, $like, $engine ) {
        $term = FSE_Helpers::term_from_like( $like );

        if ( strlen( $term ) < self::MIN_LENGTH ) return $search;
        if ( FSE_Helpers::is_stopword( $term ) ) return $search;

        global $wpdb;

        foreach ( self::variants( $term ) as $variant ) {
            $variant_like = '%' . $wpdb->esc_like( $variant ) . '%';
            $search .= $wpdb->prepare(
                " OR ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
                $variant_like, $variant_like
            );
        }

        return $search;
    }

    /**
     * Return plausible singular/plural variants of $term, excluding $term
     * itself. Deliberately conservative — a handful of common English rules,
     * not a full stemmer — to keep false positives low.
     *
     * @return string[]
     */
    public static function variants( string $term ): array {
        $variants = [];
        $len = strlen( $term );

        // Singularize
        if ( $len > 4 && substr( $term, -3 ) === 'ies' ) {
            $variants[] = substr( $term, 0, -3 ) . 'y';
        } elseif ( $len > 4 && substr( $term, -2 ) === 'es' && in_array( substr( $term, -3, 1 ), [ 's', 'x', 'z', 'h' ], true ) ) {
            $variants[] = substr( $term, 0, -2 );
        } elseif ( $len > 3 && substr( $term, -1 ) === 's' && substr( $term, -2 ) !== 'ss' ) {
            $variants[] = substr( $term, 0, -1 );
        }

        // Pluralize
        if ( substr( $term, -1 ) === 'y' && $len > 1 && ! in_array( substr( $term, -2, 1 ), [ 'a', 'e', 'i', 'o', 'u' ], true ) ) {
            $variants[] = substr( $term, 0, -1 ) . 'ies';
        } elseif ( preg_match( '/(s|x|z|ch|sh)$/', $term ) ) {
            $variants[] = $term . 'es';
        } elseif ( substr( $term, -1 ) !== 's' ) {
            $variants[] = $term . 's';
        }

        return array_values( array_unique( array_diff( $variants, [ $term ] ) ) );
    }
}
