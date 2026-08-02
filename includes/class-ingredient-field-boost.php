<?php
/**
 * Score boost for matches found in the store's documented product-detail
 * fields — ingredient_list, active_ingridients_with_percentage, benefits,
 * directions. These are the meaningful, curated custom fields on this
 * store (rendered as accordion tabs alongside the short description), not
 * arbitrary meta clutter — FSE_CustomFieldSearch already searches every
 * public custom field to find matching products, but treats them all
 * equally. An ingredient-precision search ("niacinamide", "salicylic
 * acid") signals strong purchase intent and deserves to rank above a
 * product that only coincidentally matches elsewhere.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_IngredientFieldBoost {

    const FIELDS = [ 'ingredient_list', 'active_ingridients_with_percentage', 'benefits', 'directions' ];

    /** Boost per matching field, capped at 2 fields' worth. */
    const BOOST_PER_FIELD = 15;
    const MAX_FIELDS_COUNTED = 2;

    /**
     * Per-request cache of additional score per post_id.
     * boost() fires once per product (50-300+ times per search); caching
     * avoids re-running the meta lookups and string comparisons for products
     * that appear in multiple filter passes.
     *
     * @var array<int, int>
     */
    private $score_cache = [];

    /** Significant words for the current search phrase, computed once. */
    private $current_words = [];
    private $current_keyword = '';

    public function __construct() {
        if ( fse_get_option( 'ingredient_field_boost_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/search_results/product/score', [ $this, 'boost' ], 10, 4 );
    }

    /**
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object (unused).
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        // Recompute significant words only when the keyword changes (it's
        // constant within a single search, but reset it defensively).
        if ( $keyword !== $this->current_keyword ) {
            $this->current_keyword = $keyword;
            $this->current_words   = FSE_Helpers::significant_words( (string) $keyword, 3 );
            $this->score_cache     = [];
        }

        if ( empty( $this->current_words ) ) return $score;

        if ( ! isset( $this->score_cache[ $post_id ] ) ) {
            $matched_fields = 0;

            foreach ( self::FIELDS as $field ) {
                $value = get_post_meta( $post_id, $field, true );
                if ( '' === $value || ! is_string( $value ) ) continue;

                $value_lower = strtolower( wp_strip_all_tags( $value ) );

                foreach ( $this->current_words as $word ) {
                    if ( false !== strpos( $value_lower, $word ) ) {
                        $matched_fields++;
                        break;
                    }
                }

                if ( $matched_fields >= self::MAX_FIELDS_COUNTED ) break;
            }

            $this->score_cache[ $post_id ] = $matched_fields > 0
                ? self::BOOST_PER_FIELD * $matched_fields
                : 0;
        }

        return $score + $this->score_cache[ $post_id ];
    }
}
