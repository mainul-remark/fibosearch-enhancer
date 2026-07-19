<?php
/**
 * Priority-mapping search re-ranking ("Search Bar Enhancement Module").
 *
 * When a searched keyword is mapped (competitor brand, product type, concern,
 * or campaign keyword), the ENTIRE result set for that search is reordered
 * into 9 fixed priority tiers, per spec:
 *
 *   1. The directly mapped product
 *   2. Other products from the same brand + same main sub-category
 *   3. All other products in the main sub-category
 *   4-7. Products from up to 4 configured "relevant" sub-categories, in order
 *   8. Remaining products in the same parent category
 *   9. Everything else
 *
 * This is NOT a last-resort fallback (unlike FSE_CompetitorFallback /
 * FSE_DiscountIntentFallback, which only act when nothing else matched) —
 * per the spec's own examples (e.g. a customer searching a real category
 * word), it re-ranks the whole result set whenever the keyword matches an
 * entry, whether or not native search would have found something anyway.
 *
 * Implementation rides FiboSearch's own architecture: its native
 * `overwriteSearchPage()` (Search.php, hooked on pre_get_posts at priority
 * 900001) drives the ENTIRE results page — not just the autocomplete
 * dropdown — by calling getSearchResults() and forcing the page's WP_Query
 * into post__in order from that result. So injecting candidates via
 * `products_raw` and controlling order via `product/score` (the same two
 * filters every other module in this plugin uses) governs the real results
 * page automatically, with no separate hook needed.
 *
 * Because this runs at a low `products_raw` priority (30, before
 * FSE_CompetitorFallback's 50 and FSE_DiscountIntentFallback's 60), a
 * matched keyword's injected products mean $products is no longer empty by
 * the time those fallbacks check — so nothing needs explicit coordination
 * to avoid double-handling the same keyword.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_PriorityMapping {

    const OPTION_KEY = 'fse_priority_mapping';

    /** Tier base scores — spaced far enough apart that no other scoring
     * module in this plugin (max ~100) can ever cross a tier boundary, while
     * still letting other signals subtly reorder products within a tier. */
    const TIER_BASE_SCORE = 1000000;
    const TIER_STEP        = 10000;

    /** Max candidate products injected per tier, to keep results bounded. */
    const TIER_LIMIT = 60;

    /** @var array|null The mapping entry matched for the current search, or null. */
    private $matched_entry = null;

    /** @var array<int,int> product_id => tier (1-8) for the current search. */
    private $tier_by_product = [];

    public function __construct() {
        if ( fse_get_option( 'priority_mapping_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase', [ $this, 'match_keyword' ], 0 );
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'inject_tier_products' ], 30 );
        add_filter( 'dgwt/wcas/search_results/product/score', [ $this, 'apply_tier_score' ], 5, 4 );
    }

    /**
     * Find a mapping entry for the current phrase (exact, partial, or
     * typo/spelling-variation match) and, if found, precompute every tier's
     * product IDs. Runs at priority 0 — before any other phrase filter in
     * this plugin — so keyword matching sees the shopper's raw input, not a
     * typo-corrected/translated version. Does not mutate the phrase.
     */
    public function match_keyword( $keyword ) {
        $this->matched_entry   = null;
        $this->tier_by_product = [];

        if ( empty( $keyword ) ) return $keyword;

        $entry = $this->find_matching_entry( (string) $keyword );
        if ( null === $entry ) return $keyword;

        $this->matched_entry   = $entry;
        $this->tier_by_product = $this->build_tiers( $entry );

        return $keyword;
    }

    /**
     * Inject every tier's candidate products into the raw results list.
     */
    public function inject_tier_products( $products ) {
        if ( null === $this->matched_entry ) return $products;

        $all_ids = array_keys( $this->tier_by_product );
        return FSE_Helpers::merge_extra_products( $products, $all_ids );
    }

    /**
     * Score every product by its tier — tier 1 highest, tier 8 next, and
     * anything not classified into a tier (i.e. tier 9, "everything else")
     * left at whatever score other modules already gave it, which is always
     * far below the tier 1-8 floor.
     */
    public function apply_tier_score( $score, $keyword, $post_id, $post ) {
        if ( null === $this->matched_entry ) return $score;

        $tier = $this->tier_by_product[ $post_id ] ?? null;
        if ( null === $tier ) return $score;

        // Add (not replace) so other modules' finer-grained signals (exact
        // title match, best-seller, ingredient match) still influence order
        // *within* a tier, without ever being able to cross into the next one.
        return self::TIER_BASE_SCORE - ( ( $tier - 1 ) * self::TIER_STEP ) + $score;
    }

    /**
     * Find the mapping entry for a phrase: exact match, then partial
     * (keyword appears as a whole word/phrase within the query), then a
     * collapsed-spacing match, then — for single-word keywords only — a
     * SOUNDEX phonetic match, so "Sunsilk", "Sunsilk shampoo", "Sun silk",
     * and "Sunslik" all resolve to the same entry.
     */
    private function find_matching_entry( string $phrase ): ?array {
        $phrase_lower = strtolower( trim( $phrase ) );
        if ( '' === $phrase_lower ) return null;

        $entries = $this->get_entries();
        if ( empty( $entries ) ) return null;

        // 1. Exact match
        foreach ( $entries as $entry ) {
            if ( $entry['keyword'] === $phrase_lower ) return $entry;
        }

        // 2. Partial match — keyword as a whole word/phrase within the query
        foreach ( $entries as $entry ) {
            if ( preg_match( '/\b' . preg_quote( $entry['keyword'], '/' ) . '\b/i', $phrase_lower ) ) {
                return $entry;
            }
        }

        // 3. Spelling variation — spaces/hyphens collapsed on both sides
        $collapsed_phrase = FSE_Helpers::collapse( $phrase_lower );
        foreach ( $entries as $entry ) {
            $collapsed_keyword = FSE_Helpers::collapse( $entry['keyword'] );
            if ( $collapsed_keyword !== '' && false !== strpos( $collapsed_phrase, $collapsed_keyword ) ) {
                return $entry;
            }
        }

        // 4. Typo match — SOUNDEX, only for single-word keywords (multi-word
        // SOUNDEX on the whole phrase is unreliable, per earlier testing).
        $words = FSE_Helpers::significant_words( $phrase_lower );
        foreach ( $entries as $entry ) {
            if ( 1 !== str_word_count( $entry['keyword'] ) ) continue;
            foreach ( $words as $word ) {
                if ( soundex( $word ) === soundex( $entry['keyword'] ) ) return $entry;
            }
        }

        return null;
    }

    /**
     * Build the tier => [product_ids] map for a matched entry.
     *
     * @return array<int,int> product_id => tier
     */
    private function build_tiers( array $entry ): array {
        $tier_by_product = [];
        $seen            = [];

        $mapped_id = (int) ( $entry['product_id'] ?? 0 );
        if ( $mapped_id && 'publish' === get_post_status( $mapped_id ) ) {
            $tier_by_product[ $mapped_id ] = 1;
            $seen[ $mapped_id ]            = true;
        }

        $brand_term_id = $mapped_id ? $this->get_brand_term_id( $mapped_id ) : 0;
        $main_slug     = (string) ( $entry['main_subcategory'] ?? '' );

        if ( $brand_term_id && $main_slug ) {
            foreach ( $this->products_in_category( $main_slug, $seen, [ 'taxonomy' => 'brand', 'term_id' => $brand_term_id ] ) as $id ) {
                $tier_by_product[ $id ] = 2;
                $seen[ $id ]            = true;
            }
        }

        if ( $main_slug ) {
            foreach ( $this->products_in_category( $main_slug, $seen ) as $id ) {
                $tier_by_product[ $id ] = 3;
                $seen[ $id ]            = true;
            }
        }

        $relevant = array_values( array_filter( (array) ( $entry['relevant_subcategories'] ?? [] ) ) );
        foreach ( array_slice( $relevant, 0, 4 ) as $i => $slug ) {
            foreach ( $this->products_in_category( (string) $slug, $seen ) as $id ) {
                $tier_by_product[ $id ] = 4 + $i;
                $seen[ $id ]            = true;
            }
        }

        $parent_slug = (string) ( $entry['parent_category'] ?? '' );
        if ( $parent_slug ) {
            foreach ( $this->products_in_category( $parent_slug, $seen ) as $id ) {
                $tier_by_product[ $id ] = 8;
                $seen[ $id ]            = true;
            }
        }

        return $tier_by_product;
    }

    /**
     * Published product IDs in a product_cat (by slug), best-sellers first,
     * excluding IDs already assigned to an earlier tier, optionally
     * intersected with another taxonomy (e.g. brand).
     *
     * @param array<int,bool> $exclude_ids Keyed by product ID for fast lookup.
     * @param array{taxonomy:string,term_id:int}|null $and_taxonomy
     * @return int[]
     */
    private function products_in_category( string $slug, array $exclude_ids, ?array $and_taxonomy = null ): array {
        if ( '' === $slug || ! term_exists( $slug, 'product_cat' ) ) return [];

        $tax_query = [
            [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $slug,
            ],
        ];

        if ( ! empty( $and_taxonomy['taxonomy'] ) && ! empty( $and_taxonomy['term_id'] ) ) {
            $tax_query['relation'] = 'AND';
            $tax_query[] = [
                'taxonomy' => $and_taxonomy['taxonomy'],
                'field'    => 'term_id',
                'terms'    => (int) $and_taxonomy['term_id'],
            ];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => self::TIER_LIMIT,
            'fields'         => 'ids',
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'tax_query'      => $tax_query,
            'suppress_filters'    => true,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];

        if ( ! empty( $exclude_ids ) ) {
            $args['post__not_in'] = array_keys( $exclude_ids );
        }

        return array_map( 'intval', get_posts( $args ) );
    }

    /**
     * The 'brand' taxonomy term_id for a product, or 0 if it has none.
     */
    private function get_brand_term_id( int $product_id ): int {
        $terms = get_the_terms( $product_id, 'brand' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) return 0;

        return (int) $terms[0]->term_id;
    }

    /**
     * Seeded default entries — the spec's own example mapping table, using
     * real products/categories verified against this store's catalog (not
     * invented data). Editable/overridable from the Priority Mapping admin
     * tab; this is only used while that option is still empty.
     *
     * product_id 73430  = Lily Silkore Silk & Shine Shampoo 340 ml (brand: Lily)
     * product_id 21126  = Lily Cucumber Facewash 100ml (brand: Lily)
     * product_id 37657  = Nior No Transfer Matte Lipstick No. 02 (brand: Nior)
     *
     * Category slugs confirmed to exist: shampoo, conditioner, hair-oil,
     * hair-serum, hair-mask, hair, face-wash, cleanser, toner, moisturizer,
     * sunscreen, skin-care, lipstick, lip-gloss, lip-balm, makeup-remover,
     * makeup, concealer, pressed-powder, bb-cream, powder-contour, mascara,
     * eyeliner, eyebrow, eye-shadow, shower-gel, body-lotion, soap-body-care,
     * body-cream, body-care, baby-care, regular-nail-polish, gel-nail-polish,
     * glitter-nail-polish, holographic-nail-polish, nail-polish-remover,
     * nail. No "Lip Liner" category exists on this store, so that slot is
     * left blank for the lipstick entry rather than pointing at a fake slug;
     * "Baby Care" has no sub-categories at all (it's a flat top-level
     * category), so that entry's relevant/parent slots are also left blank.
     *
     * Flagship products used below (all verified real, published):
     *   73430  Lily Silkore Silk & Shine Shampoo 340 ml       (brand: Lily)
     *   21126  Lily Cucumber Facewash 100ml                    (brand: Lily)
     *   37657  Nior No Transfer Matte Lipstick No. 02          (brand: Nior)
     *   9659   Lily Dazzling Beauty Brightening Skin Cream 50g (brand: Lily)
     *   14509  Nior Aqua Splash Sunscreen SPF 50 PA++++ 50ml   (brand: Nior)
     *   8364   Nior Your Best Skin Soft Matte Foundation       (brand: Nior)
     *   8340   Nior Your Best Skin Perfecting Concealer        (brand: Nior)
     *   38752  Lily Ultra Flutter Mascara                      (brand: Lily)
     *   7646   Color Vibes Gel Liner Dangerous Lies            (brand: Herlan)
     *   21847  Lily Whipped Shea Body Wash 250ml               (brand: Lily)
     *   25134  Little One Baby Skin Lotion- 100ml              (brand: Little One)
     *   8758   NIOR Classic Nail Polish Raven Eye              (brand: Nior)
     */
    const DEFAULT_MAPPING = [
        // Hair — Shampoo
        [
            'keyword'                => 'sunsilk',
            'product_id'             => 73430,
            'main_subcategory'       => 'shampoo',
            'relevant_subcategories' => [ 'conditioner', 'hair-oil', 'hair-serum', 'hair-mask' ],
            'parent_category'        => 'hair',
        ],
        [
            'keyword'                => 'dove shampoo',
            'product_id'             => 73430,
            'main_subcategory'       => 'shampoo',
            'relevant_subcategories' => [ 'conditioner', 'hair-oil', 'hair-serum', 'hair-mask' ],
            'parent_category'        => 'hair',
        ],
        [
            'keyword'                => 'tresemme',
            'product_id'             => 73430,
            'main_subcategory'       => 'shampoo',
            'relevant_subcategories' => [ 'conditioner', 'hair-oil', 'hair-serum', 'hair-mask' ],
            'parent_category'        => 'hair',
        ],

        // Skin Care — Face Wash
        [
            'keyword'                => 'garnier facewash',
            'product_id'             => 21126,
            'main_subcategory'       => 'face-wash',
            'relevant_subcategories' => [ 'cleanser', 'toner', 'moisturizer', 'sunscreen' ],
            'parent_category'        => 'skin-care',
        ],

        // Skin Care — Moisturizer/Cream
        [
            'keyword'                => 'olay cream',
            'product_id'             => 9659,
            'main_subcategory'       => 'moisturizer',
            'relevant_subcategories' => [ 'face-wash', 'serum', 'toner', 'sunscreen' ],
            'parent_category'        => 'skin-care',
        ],
        [
            'keyword'                => 'ponds cream',
            'product_id'             => 9659,
            'main_subcategory'       => 'moisturizer',
            'relevant_subcategories' => [ 'face-wash', 'serum', 'toner', 'sunscreen' ],
            'parent_category'        => 'skin-care',
        ],

        // Skin Care — Sunscreen
        [
            'keyword'                => 'neutrogena sunscreen',
            'product_id'             => 14509,
            'main_subcategory'       => 'sunscreen',
            'relevant_subcategories' => [ 'moisturizer', 'face-wash', 'serum', 'toner' ],
            'parent_category'        => 'skin-care',
        ],

        // Makeup — Lipstick
        [
            'keyword'                => 'maybelline lipstick',
            'product_id'             => 37657,
            'main_subcategory'       => 'lipstick',
            'relevant_subcategories' => [ 'lip-gloss', 'lip-balm', 'makeup-remover' ],
            'parent_category'        => 'makeup',
        ],

        // Makeup — Foundation
        [
            'keyword'                => 'maybelline foundation',
            'product_id'             => 8364,
            'main_subcategory'       => 'foundation',
            'relevant_subcategories' => [ 'concealer', 'pressed-powder', 'bb-cream', 'powder-contour' ],
            'parent_category'        => 'makeup',
        ],
        [
            'keyword'                => 'revlon foundation',
            'product_id'             => 8364,
            'main_subcategory'       => 'foundation',
            'relevant_subcategories' => [ 'concealer', 'pressed-powder', 'bb-cream', 'powder-contour' ],
            'parent_category'        => 'makeup',
        ],

        // Makeup — Concealer
        [
            'keyword'                => 'maybelline concealer',
            'product_id'             => 8340,
            'main_subcategory'       => 'concealer',
            'relevant_subcategories' => [ 'foundation', 'pressed-powder', 'bb-cream' ],
            'parent_category'        => 'makeup',
        ],

        // Makeup — Mascara / Eyeliner
        [
            'keyword'                => 'maybelline mascara',
            'product_id'             => 38752,
            'main_subcategory'       => 'mascara',
            'relevant_subcategories' => [ 'eyeliner', 'eyebrow', 'eye-shadow' ],
            'parent_category'        => 'makeup',
        ],
        [
            'keyword'                => 'lakme eyeliner',
            'product_id'             => 7646,
            'main_subcategory'       => 'eyeliner',
            'relevant_subcategories' => [ 'mascara', 'eyebrow', 'eye-shadow' ],
            'parent_category'        => 'makeup',
        ],

        // Makeup — Nail Polish
        [
            'keyword'                => 'nyx nail polish',
            'product_id'             => 8758,
            'main_subcategory'       => 'regular-nail-polish',
            'relevant_subcategories' => [ 'gel-nail-polish', 'glitter-nail-polish', 'holographic-nail-polish', 'nail-polish-remover' ],
            'parent_category'        => 'nail',
        ],

        // Body Care — Body Wash
        [
            'keyword'                => 'dove body wash',
            'product_id'             => 21847,
            'main_subcategory'       => 'shower-gel',
            'relevant_subcategories' => [ 'body-lotion', 'soap-body-care', 'body-cream' ],
            'parent_category'        => 'body-care',
        ],

        // Baby Care (flat category, no sub-categories on this store)
        [
            'keyword'                => 'johnson baby lotion',
            'product_id'             => 25134,
            'main_subcategory'       => 'baby-care',
            'relevant_subcategories' => [],
            'parent_category'        => '',
        ],
    ];

    /**
     * @return array<int, array{keyword:string, product_id:int, main_subcategory:string, relevant_subcategories:string[], parent_category:string}>
     */
    private function get_entries(): array {
        $entries = get_option( self::OPTION_KEY, [] );
        if ( is_array( $entries ) && ! empty( $entries ) ) return $entries;

        return self::DEFAULT_MAPPING;
    }
}
