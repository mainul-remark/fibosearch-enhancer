<?php
/**
 * Field-weighted scoring for products injected by the "widening" modules
 * (variation SKU, attribute, taxonomy, tag).
 *
 * FiboSearch's own calcScore() only compares the keyword against post_title,
 * so a product pulled in purely because of a tag/attribute/taxonomy/variation
 * match (not present in the title at all) scores ~0 — same as every other
 * ~0-scoring product. That leaves their relative order among each other
 * effectively arbitrary (DB/insertion order) instead of reflecting which
 * signal found them. This applies a small, deliberately modest boost per
 * signal type — well below FSE_ScoreBoost's title/SKU tiers (100/50/40/30) —
 * so title and SKU matches always still win, but within the injected tail,
 * a variation-SKU match consistently outranks a looser tag match.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_FieldWeightScore {

    /** @var FSE_VariationSkuSearch|null */
    private $variation_sku;

    /** @var FSE_AttributeSearch|null */
    private $attribute;

    /** @var FSE_CustomTaxonomySearch|null */
    private $taxonomy;

    /** @var FSE_TagSearch|null */
    private $tag;

    /** @var FSE_CategorySearch|null */
    private $category;

    /**
     * Hash-map cache of matched IDs per module, built once on first use.
     * Converts the modules' indexed arrays into O(1) isset() lookups so
     * boost() doesn't do an O(n) in_array() scan for every product.
     *
     * @var array<string, array<int,true>>
     */
    private $id_sets = [];

    public function __construct( $variation_sku, $attribute, $taxonomy, $tag, $category = null ) {
        if ( fse_get_option( 'field_weight_score_enabled', '1' ) !== '1' ) return;

        $this->variation_sku = $variation_sku;
        $this->attribute      = $attribute;
        $this->taxonomy       = $taxonomy;
        $this->tag            = $tag;
        $this->category       = $category;

        add_filter( 'dgwt/wcas/search_results/product/score', [ $this, 'boost' ], 10, 4 );
    }

    /**
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase (unused — matches are keyed by product ID).
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object (unused).
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        if ( $this->variation_sku && $this->in_set( 'sku', $this->variation_sku, $post_id ) ) {
            $score += 12;
        }

        if ( $this->attribute && $this->in_set( 'attr', $this->attribute, $post_id ) ) {
            $score += 10;
        }

        if ( $this->taxonomy && $this->in_set( 'tax', $this->taxonomy, $post_id ) ) {
            $score += 10;
        }

        if ( $this->tag && $this->in_set( 'tag', $this->tag, $post_id ) ) {
            $score += 8;
        }

        if ( $this->category && $this->in_set( 'cat', $this->category, $post_id ) ) {
            $score += 8;
        }

        return $score;
    }

    /**
     * O(1) membership test — builds the hash map once per module per request.
     */
    private function in_set( string $key, $module, int $post_id ): bool {
        if ( ! isset( $this->id_sets[ $key ] ) ) {
            $this->id_sets[ $key ] = array_fill_keys( $module->get_matched_ids(), true );
        }
        return isset( $this->id_sets[ $key ][ $post_id ] );
    }
}
