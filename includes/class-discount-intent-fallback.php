<?php
/**
 * Discount-intent fallback.
 *
 * Queries like "50% discount products", "discount", "50% off" aren't text
 * searches at all — the shopper wants "what's currently on sale", which has
 * no textual anchor in the catalog to match against (confirmed: only one
 * product on this store even contains the literal word "discount"
 * anywhere). No amount of synonym/typo correction fixes a query that isn't
 * really a keyword search. Instead, when the phrase signals discount intent
 * and every other module still found nothing, this surfaces the store's
 * actual on-sale products (via WooCommerce's own sale-price data), ranked by
 * biggest discount percentage first.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_DiscountIntentFallback {

    const TRIGGER_WORDS = [ 'discount', 'discounts', 'sale', 'sales', 'offer', 'offers', 'off', 'deal', 'deals', 'clearance' ];

    /** Max on-sale products to inject. */
    const LIMIT = 12;

    /** @var string Current search phrase, captured from the 'phrase' filter. */
    private $current_keyword = '';

    public function __construct() {
        if ( fse_get_option( 'discount_intent_fallback_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/phrase', [ $this, 'capture_keyword' ], 1 );
        // Priority 60 — after FSE_CompetitorFallback (50), so a query that
        // happens to match both only gets the more specific brand/category
        // fallback, not this one.
        add_filter( 'dgwt/wcas/search_results/products_raw', [ $this, 'maybe_inject_sale_products' ], 60 );
    }

    public function capture_keyword( $keyword ) {
        $this->current_keyword = strtolower( trim( (string) $keyword ) );
        return $keyword;
    }

    public function maybe_inject_sale_products( $products ) {
        if ( ! empty( $products ) || empty( $this->current_keyword ) ) return $products;
        if ( ! $this->has_discount_intent( $this->current_keyword ) ) return $products;

        return $this->fetch_on_sale_products();
    }

    private function has_discount_intent( string $keyword ): bool {
        foreach ( preg_split( '/\s+/', $keyword ) as $word ) {
            if ( in_array( rtrim( $word, '%' ), self::TRIGGER_WORDS, true ) ) return true;
        }
        return false;
    }

    /**
     * Published, purchasable on-sale products, ranked by discount
     * percentage (biggest savings first).
     *
     * @return WP_Post[]
     */
    private function fetch_on_sale_products(): array {
        if ( ! function_exists( 'wc_get_product_ids_on_sale' ) ) return [];

        $sale_ids = wc_get_product_ids_on_sale();
        if ( empty( $sale_ids ) ) return [];

        $discount_pct = [];
        foreach ( $sale_ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product || 'publish' !== get_post_status( $id ) ) continue;

            $regular = (float) $product->get_regular_price();
            $sale    = (float) $product->get_sale_price();
            if ( $regular <= 0 || $sale <= 0 ) continue;

            $discount_pct[ $id ] = ( ( $regular - $sale ) / $regular ) * 100;
        }

        if ( empty( $discount_pct ) ) return [];

        arsort( $discount_pct );
        $ranked_ids = array_slice( array_keys( $discount_pct ), 0, self::LIMIT );

        return get_posts( [
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'post__in'            => $ranked_ids,
            'orderby'             => 'post__in',
            'posts_per_page'      => count( $ranked_ids ),
            'suppress_filters'    => true,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ] );
    }
}
