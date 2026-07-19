<?php
/**
 * Unstocked brand / unstocked product-type fallback.
 *
 * FiboSearch's zero-result analytics show heavy search volume for two kinds
 * of "genuinely nothing to find" queries: brands this store doesn't carry
 * (Cosrx, Dove, Garnier, Maybelline, Nivea, L'Oreal, Revlon, Milani, W7,
 * Mamaearth, Neutrogena, ...), and whole product types not in the catalog at
 * all (e.g. "liquid highlighter" — confirmed zero highlighter products of
 * any kind exist here). No amount of spelling/synonym correction fixes
 * either case — there's nothing matching to find. Instead of a dead end,
 * when a search matches a known keyword here AND every other module still
 * found nothing, this surfaces best-selling products from the closest
 * equivalent category, so the customer sees a relevant alternative rather
 * than "no results".
 *
 * The keyword → category mapping is store-specific business knowledge
 * (which category actually substitutes for a given unstocked brand or
 * product type), so it's stored as an editable option with a sane seeded
 * default rather than hardcoded permanently.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_CompetitorFallback {

    const OPTION_KEY = 'fse_competitor_brand_map';

    /**
     * Seeded default: unstocked brand/product-type keyword => this store's
     * product_cat slug to show instead. Verified against this store's real
     * category taxonomy (top-level: makeup, skin-care, bath, hair, ... and
     * skin-care's own sub-categories face-care, body-care; makeup's own
     * sub-category face).
     */
    const DEFAULT_MAP = [
        // Skincare
        'cosrx'             => 'skin-care',
        'garnier'           => 'skin-care',
        'mamaearth'         => 'skin-care',
        'neutrogena'        => 'face-care',
        'himalaya'          => 'face-care',
        'ponds'             => 'face-care',
        "pond's"            => 'face-care',
        'olay'              => 'skin-care',
        'boroplus'          => 'body-care',
        'dot and key'       => 'skin-care',
        'dot & key'         => 'skin-care',
        '3w clinic'         => 'skin-care',
        'the derma co'      => 'skin-care',
        'derma co'          => 'skin-care',
        'minimalist'        => 'skin-care',
        'cerave'            => 'skin-care',
        'isntree'           => 'skin-care',
        'anua'              => 'skin-care',
        'beauty of joseon'  => 'skin-care',

        // Bath / body
        'dove'              => 'bath',
        'nivea'             => 'body-care',
        'vaseline'          => 'body-care',
        'rexona'            => 'bath',
        'gillette'          => 'bath',
        'johnson'           => 'baby-care',
        "johnson's"         => 'baby-care',

        // Hair
        'loreal'            => 'hair',
        "l'oreal"           => 'hair',
        'sunsilk'           => 'hair',
        'tresemme'          => 'hair',

        // Makeup
        'maybelline'        => 'makeup',
        'revlon'            => 'makeup',
        'milani'            => 'makeup',
        'w7'                => 'makeup',
        'lakme'             => 'makeup',
        'huda beauty'       => 'makeup',
        'nars'              => 'makeup',
        'catrice'           => 'makeup',
        'essence'           => 'makeup',
        'etude'             => 'makeup',
        'wardah'            => 'makeup',
        'flormar'           => 'makeup',
        'sheglam'           => 'makeup',
        'swiss beauty'      => 'makeup',
        'pinkflash'         => 'makeup',
        'imagic'            => 'makeup',
        'beauty glazed'     => 'makeup',
        'focallure'         => 'makeup',
        'kiko'              => 'makeup',
        'technic'           => 'makeup',
        'wet n wild'        => 'makeup',
        'nyx'               => 'makeup',
        'l.a girl'          => 'makeup',
        'la girl'           => 'makeup',
        'highlighter'       => 'face',
    ];

    /** Max fallback products to inject. */
    const LIMIT = 8;

    /** @var string Current search phrase, captured from the 'phrase' filter. */
    private $current_keyword = '';

    public function __construct() {
        if ( fse_get_option( 'competitor_fallback_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase', [ $this, 'capture_keyword' ], 1 );
        // Priority 50 — after every widening module's products_raw filter, so
        // this only fires when the search is genuinely still empty.
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'maybe_inject_fallback' ], 50 );
    }

    /**
     * Record the (unmodified) search phrase for use once products_raw fires.
     */
    public function capture_keyword( $keyword ) {
        $this->current_keyword = strtolower( trim( (string) $keyword ) );
        return $keyword;
    }

    /**
     * If nothing else matched anything for this search, and the phrase
     * contains a known unstocked-brand keyword, inject best-sellers from
     * that brand's equivalent category instead of returning empty.
     */
    public function maybe_inject_fallback( $products ) {
        if ( ! empty( $products ) || empty( $this->current_keyword ) ) return $products;

        $map = $this->get_map();

        foreach ( $map as $brand => $category_slug ) {
            if ( preg_match( '/\b' . preg_quote( $brand, '/' ) . '\b/i', $this->current_keyword ) ) {
                // A query like "vaseline lotion" carries real signal beyond
                // the unstocked brand name — "lotion" describes what the
                // shopper actually wants. Try narrowing the category by any
                // other significant word in the query first (e.g. body-care
                // products with "lotion" in the title), so the fallback
                // shows genuinely matching products instead of arbitrary
                // best-sellers from the category. Falls back to plain
                // category best-sellers if nothing narrows down.
                $other_words = array_values( array_diff(
                    FSE_Helpers::significant_words( $this->current_keyword ),
                    [ $brand ]
                ) );

                $narrowed = $this->fetch_category_bestsellers( $category_slug, $other_words );
                if ( ! empty( $narrowed ) ) return $narrowed;

                return $this->fetch_category_bestsellers( $category_slug );
            }
        }

        return $products;
    }

    /**
     * @return array<string, string>
     */
    private function get_map(): array {
        $custom = get_option( self::OPTION_KEY, [] );
        return ( is_array( $custom ) && ! empty( $custom ) ) ? $custom : self::DEFAULT_MAP;
    }

    /**
     * Best-selling published products in a category, by slug, optionally
     * narrowed to those whose title contains at least one of $title_words.
     * Returns an empty array if the category doesn't exist on this install
     * (e.g. after a re-organized taxonomy) rather than erroring.
     *
     * @param string[] $title_words
     * @return WP_Post[]
     */
    private function fetch_category_bestsellers( string $slug, array $title_words = [] ): array {
        if ( empty( $slug ) || ! term_exists( $slug, 'product_cat' ) ) return [];

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => self::LIMIT,
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $slug,
                ],
            ],
            'suppress_filters'    => true,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];

        if ( ! empty( $title_words ) ) {
            $args['post__in'] = $this->product_ids_with_title_word( $slug, $title_words );
            if ( empty( $args['post__in'] ) ) return [];
        }

        return get_posts( $args );
    }

    /**
     * Product IDs in the given category whose title contains at least one
     * of $words.
     *
     * @param string[] $words
     * @return int[]
     */
    private function product_ids_with_title_word( string $slug, array $words ): array {
        global $wpdb;

        $or_fragments = [];
        $word_params  = [];
        foreach ( $words as $word ) {
            $or_fragments[] = 'p.post_title LIKE %s';
            $word_params[]  = '%' . $wpdb->esc_like( $word ) . '%';
        }
        $where  = '(' . implode( ' OR ', $or_fragments ) . ')';
        $params = array_merge( [ $slug ], $word_params );

        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE tt.taxonomy = 'product_cat'
                AND t.slug = %s
                AND p.post_type = 'product'
                AND p.post_status = 'publish'
                AND {$where}",
            $params
        ) ) );
    }
}
