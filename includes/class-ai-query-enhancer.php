<?php
/**
 * AI-assisted query understanding: typo correction, synonym expansion, and
 * category-intent boosting, layered on top of FiboSearch's existing SQL
 * search and score-boost hooks.
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

    /** Score boost applied when a product belongs to the AI-detected category */
    const AI_CATEGORY_BOOST = 25;

    /** @var FSE_AI_Client */
    private $client;

    /**
     * Per-request cache of resolved AI data, keyed by normalized keyword.
     * Value is an array (see FSE_AI_Client::normalize_result shape, plus
     * 'category_term_id') or null when no AI data is available.
     *
     * @var array<string, array|null>
     */
    private $data_by_keyword = [];

    /**
     * Cached list of real product_cat names, fetched once per request (and
     * cached across requests in a transient) — passed to the AI so its
     * category guess is grounded in actual store taxonomy.
     *
     * @var string[]|null
     */
    private $category_names = null;

    public function __construct() {
        if ( '1' !== fse_get_option( 'ai_enabled', '0' ) ) return;

        $this->client = new FSE_AI_Client();

        add_filter( 'dgwt/wcas/phrase',                        [ $this, 'build_ai_data' ], 4 );
        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
        add_filter( 'dgwt/wcas/search_results/product/score',  [ $this, 'boost' ],          10, 4 );
    }

    /**
     * Resolve (from cache or a live AI call) the corrected/synonym/category
     * data for the current search phrase. Does not mutate the phrase.
     */
    public function build_ai_data( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $normalized = strtolower( trim( $keyword ) );
        if ( strlen( $normalized ) < self::MIN_LENGTH ) return $keyword;

        $cache_key = 'fse_ai_' . md5( $normalized );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->data_by_keyword[ $normalized ] = $cached;
            return $keyword;
        }

        $raw = $this->client->fetch( $normalized, $this->get_category_names() );

        if ( null === $raw ) {
            $this->data_by_keyword[ $normalized ] = null;
            return $keyword;
        }

        $resolved = [
            'corrected'        => $raw['corrected'],
            'synonyms'         => $raw['synonyms'],
            'category_term_id' => $this->resolve_category( $raw['category'] ),
        ];

        $ttl_hours = (int) fse_get_option( 'ai_cache_ttl_hours', '24' );
        if ( $ttl_hours <= 0 ) $ttl_hours = 24;

        set_transient( $cache_key, $resolved, $ttl_hours * HOUR_IN_SECONDS );
        $this->data_by_keyword[ $normalized ] = $resolved;

        return $keyword;
    }

    /**
     * Add OR conditions for the AI-corrected term and each synonym, same
     * SQL-building style as FSE_SynonymSearch::add_conditions.
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
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
                $like_pattern, $like_pattern, $like_pattern
            );

            $search .= " OR {$condition}";
        }

        return $search;
    }

    /**
     * Boost products belonging to the AI-detected category for this query.
     *
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object.
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        $normalized = strtolower( trim( (string) $keyword ) );
        $data       = $this->data_by_keyword[ $normalized ] ?? null;

        if ( empty( $data ) || empty( $data['category_term_id'] ) ) return $score;

        if ( has_term( (int) $data['category_term_id'], 'product_cat', $post_id ) ) {
            $score += self::AI_CATEGORY_BOOST;
        }

        return $score;
    }

    /**
     * Fetch real product_cat names to ground the AI's category guess.
     * Cached per-request in a property and across requests in a transient
     * (category lists change rarely, so a 12h TTL avoids a get_terms() call
     * on every search).
     *
     * @return string[]
     */
    private function get_category_names(): array {
        if ( null !== $this->category_names ) return $this->category_names;

        $cached = get_transient( 'fse_ai_category_names' );
        if ( false !== $cached ) {
            $this->category_names = $cached;
            return $this->category_names;
        }

        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'fields'     => 'names',
        ] );

        $names = ( ! is_wp_error( $terms ) && is_array( $terms ) ) ? array_values( $terms ) : [];

        set_transient( 'fse_ai_category_names', $names, 12 * HOUR_IN_SECONDS );
        $this->category_names = $names;

        return $names;
    }

    /**
     * Resolve an AI-guessed category name to a real product_cat term ID.
     * Returns null if there's no matching real category, so a hallucinated
     * guess can never affect ranking.
     */
    private function resolve_category( ?string $name ): ?int {
        if ( empty( $name ) ) return null;

        $term = get_term_by( 'name', $name, 'product_cat' );
        if ( ! $term ) {
            $term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
        }

        return $term ? (int) $term->term_id : null;
    }
}
