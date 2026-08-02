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

    /** @var FSE_VariationSkuSearch|null */
    private $variation_sku;

    /** @var array<int,true>|null Hash map of variation-SKU matched IDs, built once. */
    private $sku_id_set = null;

    public function __construct( $variation_sku = null ) {
        if ( fse_get_option( 'score_boost_enabled', '1' ) !== '1' ) return;

        $this->variation_sku = $variation_sku;

        add_filter( 'dgwt/wcas/search_results/product/score', [ $this, 'boost' ], 10, 4 );
    }

    /**
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object.
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        // Push out-of-stock products to the bottom regardless of relevance.
        if ( get_post_meta( $post_id, '_stock_status', true ) === 'outofstock' ) {
            $score -= 10000;
        }

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

        // Any variation SKU contains keyword — reuses FSE_VariationSkuSearch's
        // own once-per-phrase lookup (same keyword, same request) instead of
        // running a fresh get_posts() query per product here, which this
        // per-product score hook fires for every raw result (50-300+ times
        // on a broad search) — see FSE_FieldWeightScore for the established
        // pattern this mirrors.
        if ( $this->variation_sku ) {
            if ( null === $this->sku_id_set ) {
                $this->sku_id_set = array_fill_keys( $this->variation_sku->get_matched_ids(), true );
            }
            if ( isset( $this->sku_id_set[ $post_id ] ) ) {
                $score += 30;
            }
        }

        return $score;
    }
}
