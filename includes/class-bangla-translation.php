<?php
/**
 * Bangla-script → English term translation.
 *
 * Product titles/content on this store are English (Latin script), so a
 * Bangla-script query can never LIKE-match them no matter how lenient the
 * fuzzy/typo matching gets — it's not a spelling problem, it's a different
 * script entirely. Sourced from real Bangla queries in the zero-result
 * analytics export (সিরাম, শ্যাম্পু, ফাউন্ডেশন, etc.).
 *
 * Rewrites the phrase itself on 'dgwt/wcas/phrase', at the same early
 * priority as FSE_TypoCorrection, so the translated English term flows
 * through the main query, category/tag/attribute lookups, and scoring —
 * everything downstream just sees English from here on.
 *
 * Deliberately curated, not a general-purpose translator: covers the
 * specific Bangla product terms customers actually searched. A handful of
 * multi-word phrases are matched whole first (higher specificity), then
 * remaining words are translated individually.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_BanglaTranslation {

    /** Whole-phrase matches, checked before per-word translation. */
    const PHRASE_MAP = [
        'চুলের শ্যাম্পু'          => 'hair shampoo',
        'ফুল কভারেজ ফাউন্ডেশন'    => 'full coverage foundation',
        'ফেস পাউডার'              => 'face powder',
        'ভালো সিরাম'              => 'good serum',
        'ভিটামিন সি সিরাম'        => 'vitamin c serum',
        'লিপ লাইনার'              => 'lip liner',
        'সান ক্রিম'               => 'sunscreen',
        'কনসিলার+ ফাউন্ডেশন'      => 'concealer foundation',
        'সিরাম প্রোডাক্ট'         => 'serum product',
        'চোখের লেন্স'             => 'eye lens',
        'দি অর্ডিনারী সিরাম'      => 'the ordinary serum',
        'সিওডিল ব্রাইটেনি'        => 'siodil brightening',
        'ড্রাই স্কিনের ফেসওয়াস'  => 'dry skin face wash',
    ];

    /** Single-word translations, applied to any word not caught by PHRASE_MAP. */
    const WORD_MAP = [
        'সিরাম'      => 'serum',
        'শ্যাম্পু'    => 'shampoo',
        'কাজল'       => 'kajal',
        'কাজোল'      => 'kajal',
        'ব্লাশ'       => 'blush',
        'ব্লাশার'     => 'blusher',
        'কনসিলার'    => 'concealer',
        'জেল'        => 'gel',
        'ফাউন্ডেশন'  => 'foundation',
        'পাউডার'     => 'powder',
        'লাইনার'     => 'liner',
        'ক্রিম'       => 'cream',
        'ফেসওয়াস'   => 'face wash',
        'ফেসও'       => 'face wash',
        'প্রাইমার'    => 'primer',
        'লিলি'        => 'lily',
        'নিয়র'       => 'nior',
        'নিৰ'         => 'nior',
        'চিরুনি'      => 'comb',
        'লেন্স'       => 'lens',
        'চুলের'       => 'hair',
        'মেকআপ'      => 'makeup',
        'সেটিং'       => 'setting',
        'স্প্রে'       => 'spray',
        'নিউ'         => 'new',
        '指甲油'      => 'nail polish',
    ];

    public function __construct() {
        if ( fse_get_option( 'bangla_translation_enabled', '1' ) !== '1' ) return;

        // Same early priority as FSE_TypoCorrection — order between the two
        // doesn't matter, they operate on disjoint vocabularies.
        add_filter( 'dgwt/wcas/phrase', [ $this, 'translate' ], 1 );
    }

    public function translate( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $trimmed = trim( $keyword );
        if ( isset( self::PHRASE_MAP[ $trimmed ] ) ) {
            return self::PHRASE_MAP[ $trimmed ];
        }

        $tokens = preg_split( '/(\s+)/', $keyword, -1, PREG_SPLIT_DELIM_CAPTURE );

        foreach ( $tokens as &$token ) {
            if ( isset( self::WORD_MAP[ $token ] ) ) {
                $token = self::WORD_MAP[ $token ];
            }
        }
        unset( $token );

        return implode( '', $tokens );
    }
}
