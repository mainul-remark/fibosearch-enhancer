<?php
/**
 * Synonym and related-word search.
 *
 * When a search term matches a configured synonym group, all other terms in
 * that group are OR'd into the same SQL group for that word — so "tv" also
 * matches products containing "television" or "screen".
 *
 * Synonyms are stored in the fse_synonyms WP option as:
 *   [ ['tv','television','screen'], ['mobile','phone','smartphone'], ... ]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_SynonymSearch {

    /**
     * Map of search-term → synonym terms to inject.
     * Built during dgwt/wcas/phrase; consumed during search_or.
     *
     * @var array<string, string[]>
     */
    private $synonym_map = [];

    public function __construct() {
        if ( fse_get_option( 'synonyms_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase',                         [ $this, 'build_map' ],      5 );
        add_filter( 'dgwt/wcas/native/search_query/search_or',  [ $this, 'add_conditions' ], 10, 3 );
    }

    /** @var array|null Request-level cache for the synonym groups option. */
    private static $groups_cache = null;

    /**
     * Pre-compute synonym expansions for every word in the keyword.
     */
    public function build_map( $keyword ) {
        $this->synonym_map = [];

        if ( empty( $keyword ) ) return $keyword;

        if ( null === self::$groups_cache ) {
            self::$groups_cache = get_option( 'fse_synonyms', [] );
        }
        $groups = self::$groups_cache;
        if ( empty( $groups ) ) return $keyword;

        $words = preg_split( '/\s+/', strtolower( trim( $keyword ) ) );

        foreach ( $words as $word ) {
            if ( FSE_Helpers::is_stopword( $word ) ) continue;

            foreach ( $groups as $group ) {
                $terms = array_map( 'strtolower', array_map( 'trim', (array) $group ) );
                if ( in_array( $word, $terms, true ) ) {
                    // Store every term in the group except the searched word itself
                    $this->synonym_map[ $word ] = array_values(
                        array_filter( $terms, fn( $t ) => $t !== $word )
                    );
                    break;
                }
            }
        }

        return $keyword;
    }

    /**
     * For each search term that has synonyms, add OR conditions covering those
     * synonyms across title, content, and excerpt.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern for the current term, e.g. '%tv%'.
     * @param object $engine  FiboSearch Search engine instance.
     */
    public function add_conditions( $search, $like, $engine ) {
        if ( empty( $this->synonym_map ) ) return $search;

        $term = FSE_Helpers::term_from_like( $like );

        if ( empty( $this->synonym_map[ $term ] ) ) return $search;

        global $wpdb;

        foreach ( $this->synonym_map[ $term ] as $synonym ) {
            $syn_like = '%' . $wpdb->esc_like( $synonym ) . '%';

            $condition = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
                $syn_like, $syn_like, $syn_like
            );

            $search .= " OR {$condition}";
        }

        return $search;
    }
}
