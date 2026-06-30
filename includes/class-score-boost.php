<?php
/**
 * Relevance score boosts.
 *
 * FiboSearch (free) calculates a basic similarity score per product and sorts
 * results by it. This class hooks into that score to boost products that have
 * the keyword in high-signal places (SKU, exact title match, variation SKU).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_ScoreBoost {

    public function __construct() {
        if ( fse_get_option( 'score_boost_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/search_results/product/score', [ $this, 'boost' ], 10, 4 );
    }

    /**
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object.
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        $keyword_lower = strtolower( trim( $keyword ) );
        $title_lower   = strtolower( $post->post_title );

        // Exact title match — highest boost
        if ( $title_lower === $keyword_lower ) {
            $score += 100;
        }

        // Title starts with the keyword
        if ( $keyword_lower && strpos( $title_lower, $keyword_lower ) === 0 ) {
            $score += 50;
        }

        // Parent SKU contains keyword
        $sku = (string) get_post_meta( $post_id, '_sku', true );
        if ( $sku && stripos( $sku, $keyword ) !== false ) {
            $score += 40;
        }

        // Any variation SKU contains keyword
        $variation_ids = get_posts( [
            'post_type'      => 'product_variation',
            'post_parent'    => $post_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ] );

        foreach ( (array) $variation_ids as $vid ) {
            $vsku = (string) get_post_meta( $vid, '_sku', true );
            if ( $vsku && stripos( $vsku, $keyword ) !== false ) {
                $score += 30;
                break;
            }
        }

        return $score;
    }
}
