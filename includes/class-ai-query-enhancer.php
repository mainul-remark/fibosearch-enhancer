<?php
/**
 * AI-assisted query understanding: typo correction, synonym expansion, and
 * multi-taxonomy intent boosting, layered on top of FiboSearch's existing
 * SQL search and score-boost hooks.
 *
 * Results are cached per normalized keyword in a WP transient. Any AI
 * failure (no vendor configured, timeout, malformed response) is treated as
 * "no AI data available" for that request — search falls back to
 * FiboSearch's normal behavior plus the plugin's other algorithmic modules.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AIQueryEnhancer {

    /** Minimum keyword length to attempt an AI call */
    const MIN_LENGTH = 3;

    /**
     * Maps FSE_AI_Client::INTENT_FIELDS (the AI's JSON field names) to the
     * real WordPress taxonomy slug each one represents.
     */
    const TAXONOMY_MAP = [
        'category'  => 'product_cat',
        'brand'     => 'brand',
        'age_range' => 'age-range',
        'skin_type' => 'skin-type',
    ];

    /** Score boost per matching taxonomy, keyed by real taxonomy slug. */
    const TAXONOMY_BOOSTS = [
        'product_cat' => 25,
        'brand'       => 15,
        'age-range'   => 15,
        'skin-type'   => 15,
    ];

    /** @var FSE_AI_Client */
    private $client;

    /**
     * Per-request cache of resolved AI data, keyed by normalized keyword.
     * Value is an array with 'corrected', 'synonyms', and 'term_ids'
     * (taxonomy slug => term_id|null), or null when no AI data is
     * available.
     *
     * @var array<string, array|null>
     */
    private $data_by_keyword = [];

    /**
     * Cached real term names per intent field, fetched once per request
     * (and cached across requests in a transient) — passed to the AI so
     * its guesses are grounded in actual store taxonomy.
     *
     * @var array<string, string[]>|null
     */
    private $taxonomy_terms = null;

    public function __construct() {
        if ( '1' !== fse_get_option( 'ai_enabled', '0' ) ) return;

        $this->client = new FSE_AI_Client();

        add_filter( 'dgwt/wcas/phrase',                        [ $this, 'build_ai_data' ], 4 );
        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
        add_filter( 'dgwt/wcas/search_results/product/score',  [ $this, 'boost' ],          10, 4 );
    }

    /**
     * Resolve (from cache or a live AI call) the corrected/synonym/intent
     * data for the current search phrase. Does not mutate the phrase.
     */
    public function build_ai_data( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $normalized = strtolower( trim( $keyword ) );
        if ( strlen( $normalized ) < self::MIN_LENGTH ) return $keyword;

        $cache_key = 'fse_ai_' . md5( $normalized );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->cache_data( $normalized, $cached );
            return $keyword;
        }

        $raw = $this->client->fetch( $normalized, $this->get_taxonomy_terms() );

        if ( null === $raw ) {
            $this->data_by_keyword[ $normalized ] = null;
            return $keyword;
        }

        $term_ids = [];
        foreach ( self::TAXONOMY_MAP as $field => $taxonomy ) {
            $term_ids[ $taxonomy ] = $this->resolve_term( $raw['intent'][ $field ] ?? null, $taxonomy );
        }

        $resolved = [
            'corrected' => $raw['corrected'],
            'synonyms'  => $raw['synonyms'],
            'term_ids'  => $term_ids,
        ];

        $ttl_hours = (int) fse_get_option( 'ai_cache_ttl_hours', '24' );
        if ( $ttl_hours <= 0 ) $ttl_hours = 24;

        set_transient( $cache_key, $resolved, $ttl_hours * HOUR_IN_SECONDS );
        $this->cache_data( $normalized, $resolved );

        return $keyword;
    }

    /**
     * Cache resolved AI data under the full phrase AND under each individual
     * word in it.
     *
     * FiboSearch's search_or/score filters fire per tokenized word (it splits
     * "s" into $q['search_terms'] and loops), not once for the whole phrase.
     * Caching only by the full phrase meant add_conditions()/boost() — which
     * look up by the single term FiboSearch hands them — never found a match
     * for any multi-word search; the AI widening silently no-op'd except for
     * single-word queries where term === phrase. Storing the same resolved
     * data under every word lets each term's OR-group apply the phrase-level
     * correction/synonyms, which is correct here since those groups are
     * AND'ed together — a product matching the correction/synonym anywhere
     * satisfies every group independently.
     */
    private function cache_data( string $normalized, array $resolved ): void {
        $this->data_by_keyword[ $normalized ] = $resolved;

        foreach ( preg_split( '/\s+/', $normalized ) as $word ) {
            if ( $word !== '' ) {
                $this->data_by_keyword[ $word ] = $resolved;
            }
        }
    }

    /**
     * Add OR conditions for the AI-corrected term and each synonym.
     *
     * Deliberately narrower than FSE_SynonymSearch::add_conditions: matches
     * only title + excerpt, not post_content. AI synonyms are generated
     * per-query and unreviewed (unlike hand-curated synonym groups), so
     * matching against full product descriptions is too loose — a generic
     * word appearing anywhere in a long description pulls in unrelated
     * products. Title/excerpt are short and targeted enough that a match
     * there is meaningful.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern for the current term, e.g. '%runing%'.
     * @param object $engine  FiboSearch Search engine instance.
     */
    public function add_conditions( $search, $like, $engine ) {
        $term = FSE_Helpers::term_from_like( $like );
        $data = $this->data_by_keyword[ $term ] ?? null;

        if ( empty( $data ) ) return $search;

        global $wpdb;

        $extra_terms = [];
        if ( ! empty( $data['corrected'] ) && strtolower( $data['corrected'] ) !== $term ) {
            $extra_terms[] = $data['corrected'];
        }
        foreach ( (array) $data['synonyms'] as $synonym ) {
            $extra_terms[] = $synonym;
        }

        foreach ( array_unique( $extra_terms ) as $extra_term ) {
            $like_pattern = '%' . $wpdb->esc_like( $extra_term ) . '%';

            $condition = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
                $like_pattern, $like_pattern
            );

            $search .= " OR {$condition}";
        }

        return $search;
    }

    /**
     * Boost products belonging to any AI-detected taxonomy term for this
     * query — category, brand, age range, and/or skin type. A product can
     * match more than one and stack boosts.
     *
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object.
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        $normalized = strtolower( trim( (string) $keyword ) );
        $data       = $this->data_by_keyword[ $normalized ] ?? null;

        if ( empty( $data ) || empty( $data['term_ids'] ) ) return $score;

        foreach ( $data['term_ids'] as $taxonomy => $term_id ) {
            if ( empty( $term_id ) ) continue;

            if ( has_term( (int) $term_id, $taxonomy, $post_id ) ) {
                $score += self::TAXONOMY_BOOSTS[ $taxonomy ] ?? 10;
            }
        }

        return $score;
    }

    /**
     * Fetch real term names per intent field to ground the AI's guesses.
     * Cached per-request in a property and across requests in a transient
     * (taxonomy term lists change rarely, so a 12h TTL avoids repeated
     * get_terms() calls on every search). Taxonomies not registered on this
     * install resolve to an empty list for that field.
     *
     * @return array<string, string[]>
     */
    private function get_taxonomy_terms(): array {
        if ( null !== $this->taxonomy_terms ) return $this->taxonomy_terms;

        $cached = get_transient( 'fse_ai_taxonomy_terms' );
        if ( false !== $cached ) {
            $this->taxonomy_terms = $cached;
            return $this->taxonomy_terms;
        }

        $terms = [];
        foreach ( self::TAXONOMY_MAP as $field => $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $terms[ $field ] = [];
                continue;
            }

            $found = get_terms( [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'fields'     => 'names',
            ] );

            $terms[ $field ] = ( ! is_wp_error( $found ) && is_array( $found ) ) ? array_values( $found ) : [];
        }

        set_transient( 'fse_ai_taxonomy_terms', $terms, 12 * HOUR_IN_SECONDS );
        $this->taxonomy_terms = $terms;

        return $terms;
    }

    /**
     * Resolve an AI-guessed value to a real term ID in the given taxonomy.
     * Returns null if there's no matching real term (or the taxonomy
     * doesn't exist on this install), so a hallucinated guess can never
     * affect ranking.
     */
    private function resolve_term( ?string $name, string $taxonomy ): ?int {
        if ( empty( $name ) || ! taxonomy_exists( $taxonomy ) ) return null;

        $term = get_term_by( 'name', $name, $taxonomy );
        if ( ! $term ) {
            $term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
        }

        return $term ? (int) $term->term_id : null;
    }
}
