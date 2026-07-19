<?php
/**
 * Strip generic filler words that carry no product-identifying signal.
 *
 * FiboSearch's native search ANDs every word in the query — a product must
 * match ALL of them. Confirmed directly in the zero-result analytics: "baby
 * products" (16 searches), "discount products" (36), "girls products" (9),
 * "makeup discount product" (14) all return nothing, not because the
 * products don't exist, but because no title/content contains the literal
 * word "products"/"items", so it fails the AND requirement. Dropping these
 * words before the query runs fixes the pattern across every category, not
 * just one.
 *
 * Only strips a word if at least one other word remains — a query that is
 * ONLY filler words (e.g. someone literally searches "products") is left
 * untouched rather than emptied out.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_FillerWordStrip {

    const FILLER_WORDS = [
        'items', 'item', 'products', 'product', 'stuff', 'things', 'thing',
        'collection', 'category', 'range',
    ];

    public function __construct() {
        if ( fse_get_option( 'filler_word_strip_enabled', '1' ) !== '1' ) return;

        // Priority 2 — after typo correction / Bangla translation (priority
        // 1), so filler stripping sees already-corrected words.
        add_filter( 'dgwt/wcas/phrase', [ $this, 'strip' ], 2 );
    }

    public function strip( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $words = preg_split( '/\s+/', trim( $keyword ) );
        $kept  = array_values( array_filter( $words, function ( $word ) {
            return ! in_array( strtolower( $word ), self::FILLER_WORDS, true );
        } ) );

        if ( empty( $kept ) ) return $keyword;

        return implode( ' ', $kept );
    }
}
