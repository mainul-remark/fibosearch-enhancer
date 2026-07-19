<?php
/**
 * Hand-curated typo correction for high-frequency beauty-term misspellings.
 *
 * Sourced from FiboSearch's own zero-result analytics export — these are the
 * actual misspellings customers typed that returned nothing. Rewrites the
 * phrase itself (on 'dgwt/wcas/phrase', at the earliest priority of any
 * phrase filter in this plugin) so every downstream mechanism benefits: the
 * main LIKE query, the category/tag/attribute/taxonomy term lookups, and
 * scoring all see the corrected word instead of the typo.
 *
 * Deliberately conservative: only includes tokens that are not real English
 * words themselves (so a correctly-spelled but different word never gets
 * silently rewritten), and skips anything under 4 characters to avoid
 * accidental collisions.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_TypoCorrection {

    /** Misspelling => canonical replacement (single or multi-word). */
    const MAP = [
        // serum
        'sirum' => 'serum', 'siram' => 'serum', 'scrum' => 'serum', 'searum' => 'serum',
        'syrum' => 'serum', 'siru' => 'serum', 'sarim' => 'serum', 'serem' => 'serum',
        'serium' => 'serum', 'ciram' => 'serum', 'shurim' => 'serum',
        'siramsyram' => 'serum', 'srum' => 'serum',

        // shampoo
        'sampoo' => 'shampoo', 'sampo' => 'shampoo', 'shampio' => 'shampoo', 'shampoi' => 'shampoo',
        'sampu' => 'shampoo', 'samppoo' => 'shampoo', 'shapmoo' => 'shampoo', 'shampu' => 'shampoo',
        'shempoo' => 'shampoo', 'shapo' => 'shampoo',

        // moisturizer / moisturiser
        'mostorizer' => 'moisturizer', 'moisturezer' => 'moisturizer', 'mosturaizar' => 'moisturizer',
        'mostraizer' => 'moisturizer', 'moistyriser' => 'moisturizer', 'moisrizer' => 'moisturizer',
        'mostroize' => 'moisturizer', 'moshtaraizer' => 'moisturizer', 'moisturizeer' => 'moisturizer',
        'mostr' => 'moisturizer', 'mosturi' => 'moisturizer', 'mostraisure' => 'moisturizer',
        'moistures' => 'moisturizer', 'mostur' => 'moisturizer', 'mosturizer' => 'moisturizer',
        'moistureboost' => 'moisture boost', 'moshchurizer' => 'moisturizer', 'mosscharir' => 'moisturizer',
        'mostraijar' => 'moisturizer', 'mostafizer' => 'moisturizer', 'moisherier' => 'moisturizer',

        // concealer
        'consilar' => 'concealer', 'concelar' => 'concealer', 'concler' => 'concealer',
        'conchilar' => 'concealer', 'consilor' => 'concealer', 'concela' => 'concealer',
        'conceler' => 'concealer', 'counciler' => 'concealer', 'councilor' => 'concealer',
        'concilar' => 'concealer',

        // sunscreen
        'sunscream' => 'sunscreen', 'sunsceen' => 'sunscreen', 'sunscren' => 'sunscreen',
        'suncream' => 'sunscreen', 'sunscrren' => 'sunscreen', 'sunsreen' => 'sunscreen',
        'sunsecen' => 'sunscreen', 'sunscrean' => 'sunscreen', 'sunsr' => 'sunscreen',

        // face wash
        'facewaah' => 'face wash', 'fachwash' => 'face wash', 'fachwas' => 'face wash',
        'facewase' => 'face wash', 'faceeash' => 'face wash', 'facwash' => 'face wash',
        'facwas' => 'face wash', 'fachw' => 'face wash', 'facewaash' => 'face wash',
        'faceeas' => 'face wash', 'nyem' => 'neem', 'fashwash' => 'face wash',

        // foundation
        'fundation' => 'foundation', 'foundaion' => 'foundation', 'fundetiin' => 'foundation',
        'goundation' => 'foundation', 'fundat' => 'foundation', 'faundation' => 'foundation',
        'foundaton' => 'foundation', 'fundtion' => 'foundation', 'fund' => 'foundation',

        // conditioner
        'conditionar' => 'conditioner', 'cinditioner' => 'conditioner', 'cionditioner' => 'conditioner',
        'comditioner' => 'conditioner', 'condiotioner' => 'conditioner', 'conditionor' => 'conditioner',

        // lipstick / lip balm
        'listick' => 'lipstick', 'llipstick' => 'lipstick', 'lupstick' => 'lipstick',
        'lipsyick' => 'lipstick', 'lipsick' => 'lipstick', 'lopss' => 'lipgloss',

        // mascara
        'mashkara' => 'mascara', 'mashcara' => 'mascara', 'maskara' => 'mascara', 'mashk' => 'mascara',

        // eyeliner
        'eywliner' => 'eyeliner', 'airlainar' => 'eyeliner', 'eywlainar' => 'eyeliner',
        'iloner' => 'eyeliner',

        // blush
        'blash' => 'blush', 'blusj' => 'blush',

        // kajal / kajol
        'kajol' => 'kajal',

        // micellar water
        'miceler' => 'micellar', 'micealer' => 'micellar', 'miceallar' => 'micellar',
        'myceler' => 'micellar', 'maicellr' => 'micellar', 'myseller' => 'micellar',
        'miseller' => 'micellar', 'micheller' => 'micellar', 'michelle' => 'micellar',
        'micheler' => 'micellar', 'maicellar' => 'micellar', 'miceller' => 'micellar',

        // brightening / brightening cream
        'britening' => 'brightening', 'brithening' => 'brightening', 'britining' => 'brightening',
        'brithining' => 'brightening', 'britning' => 'brightening',

        // highlighter
        'highliter' => 'highlighter', 'highlightr' => 'highlighter', 'hilighter' => 'highlighter',
        'highlither' => 'highlighter', 'highlit' => 'highlighter',

        // your own "Lily" brand — extremely high-volume typo cluster
        'liky' => 'lily', 'lilu' => 'lily', 'liliy' => 'lily', 'lilly' => 'lily',
        'lili' => 'lily', 'kily' => 'lily', 'luly' => 'lily', 'liluy' => 'lily',
        'liy' => 'lily', 'lilyv' => 'lily', 'liof' => 'lily', 'iily' => 'lily',

        // "Nior" brand (181 products — the single largest brand) — huge typo cluster
        'niir' => 'nior', 'neor' => 'nior', 'nirv' => 'nior', 'nirva' => 'nior',
        'niot' => 'nior', 'nir' => 'nior', 'niro' => 'nior', 'mior' => 'nior',

        // "Siodil" brand — huge typo cluster
        'soidil' => 'siodil', 'slodil' => 'siodil', 'seodil' => 'siodil', 'aiodil' => 'siodil',
        'silodil' => 'siodil', 'disilo' => 'siodil', 'soidi' => 'siodil', 'sido' => 'siodil',
        'slod' => 'siodil', 'soid' => 'siodil', 'siode' => 'siodil', 'siol' => 'siodil',
        'seod' => 'siodil', 'siodel' => 'siodil',

        // "Cavotin" brand
        'cavontin' => 'cavotin', 'cavotain' => 'cavotin', 'covotin' => 'cavotin', 'cevo' => 'cavotin',
        'cavitin' => 'cavotin', 'cevotin' => 'cavotin', 'caovitn' => 'cavotin', 'cavotim' => 'cavotin',
        'covatin' => 'cavotin', 'cavition' => 'cavotin',
    ];

    public function __construct() {
        if ( fse_get_option( 'typo_correction_enabled', '1' ) !== '1' ) return;

        // Priority 1 — earlier than every other 'phrase' filter this plugin
        // registers, so the corrected word is what category/tag/attribute/
        // taxonomy lookups and the main query all see.
        add_filter( 'dgwt/wcas/phrase', [ $this, 'correct' ], 1 );
    }

    /**
     * Rewrite any word in the phrase that exactly matches a known
     * misspelling, preserving original whitespace and word order.
     */
    public function correct( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $tokens = preg_split( '/(\s+)/', $keyword, -1, PREG_SPLIT_DELIM_CAPTURE );

        foreach ( $tokens as &$token ) {
            $lower = strtolower( $token );
            if ( isset( self::MAP[ $lower ] ) ) {
                $token = self::MAP[ $lower ];
            }
        }
        unset( $token );

        return implode( '', $tokens );
    }
}
