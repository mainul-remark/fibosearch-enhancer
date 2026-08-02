<?php
/**
 * Plugin Name: FiboSearch Enhancer
 * Description: Extends FiboSearch (Ajax Search for WooCommerce) with fuzzy search, synonyms, variation SKU search, attribute value search, and custom field search.
 * Version: 2.3.1
 * Author: Herlan.com
 * Text Domain: fse
 * Requires Plugins: ajax-search-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FSE_VERSION', '2.3.1' );
define( 'FSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'fse_init', 20 );

register_activation_hook( __FILE__, function () {
    require_once FSE_DIR . 'includes/class-behavior-schema.php';
    FSE_BehaviorSchema::install();

    require_once FSE_DIR . 'includes/class-behavior-rollup.php';
    FSE_BehaviorRollup::schedule_events();
} );

register_deactivation_hook( __FILE__, function () {
    require_once FSE_DIR . 'includes/class-behavior-rollup.php';
    FSE_BehaviorRollup::unschedule_events();
} );

function fse_init() {
    if ( ! class_exists( 'DgoraWcas\Search' ) ) {
        add_action( 'admin_notices', 'fse_missing_plugin_notice' );
        return;
    }

    require_once FSE_DIR . 'includes/class-helpers.php';
    require_once FSE_DIR . 'includes/class-typo-correction.php';
    require_once FSE_DIR . 'includes/class-bangla-translation.php';
    require_once FSE_DIR . 'includes/class-filler-word-strip.php';
    require_once FSE_DIR . 'includes/class-variation-sku-search.php';
    require_once FSE_DIR . 'includes/class-attribute-search.php';
    require_once FSE_DIR . 'includes/class-category-search.php';
    require_once FSE_DIR . 'includes/class-tag-search.php';
    require_once FSE_DIR . 'includes/class-custom-field-search.php';
    require_once FSE_DIR . 'includes/class-custom-taxonomy-search.php';
    require_once FSE_DIR . 'includes/class-synonym-search.php';
    require_once FSE_DIR . 'includes/class-fuzzy-search.php';
    require_once FSE_DIR . 'includes/class-stemming-search.php';
    require_once FSE_DIR . 'includes/class-score-boost.php';
    require_once FSE_DIR . 'includes/class-field-weight-score.php';
    require_once FSE_DIR . 'includes/class-ingredient-field-boost.php';
    require_once FSE_DIR . 'includes/class-search-logger.php';
    require_once FSE_DIR . 'includes/class-priority-mapping.php';
    require_once FSE_DIR . 'includes/class-competitor-fallback.php';
    require_once FSE_DIR . 'includes/class-discount-intent-fallback.php';
    require_once FSE_DIR . 'includes/class-ai-failure-logger.php';
    require_once FSE_DIR . 'includes/class-ai-client.php';
    require_once FSE_DIR . 'includes/class-ai-query-enhancer.php';
    require_once FSE_DIR . 'includes/class-behavior-schema.php';
    require_once FSE_DIR . 'includes/class-behavior-tracker.php';
    require_once FSE_DIR . 'includes/class-behavior-rollup.php';
    require_once FSE_DIR . 'includes/class-popular-searches.php';

    new FSE_TypoCorrection();
    new FSE_BanglaTranslation();
    new FSE_FillerWordStrip();
    $variation_sku = new FSE_VariationSkuSearch();
    $attribute     = new FSE_AttributeSearch();
    $category      = new FSE_CategorySearch();
    $tag           = new FSE_TagSearch();
    new FSE_CustomFieldSearch();
    $taxonomy      = new FSE_CustomTaxonomySearch();
    new FSE_SynonymSearch();
    new FSE_FuzzySearch();
    new FSE_StemmingSearch();
    new FSE_ScoreBoost( $variation_sku );
    new FSE_FieldWeightScore( $variation_sku, $attribute, $taxonomy, $tag, $category );
    new FSE_IngredientFieldBoost();
    new FSE_SearchLogger();
    new FSE_PriorityMapping();
    new FSE_CompetitorFallback();
    new FSE_DiscountIntentFallback();
    new FSE_AIQueryEnhancer();
    new FSE_PopularSearches();

    FSE_BehaviorSchema::maybe_upgrade();
    new FSE_BehaviorTracker();
    new FSE_BehaviorRollup();
    FSE_BehaviorRollup::schedule_events();

    add_action( 'wp_enqueue_scripts', 'fse_enqueue_behavior_tracker' );
    add_action( 'wp_enqueue_scripts', 'fse_enqueue_popular_searches' );

    if ( is_admin() ) {
        require_once FSE_DIR . 'admin/class-admin.php';
        ( new FSE_Admin() )->init();
    }
}

function fse_missing_plugin_notice() {
    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>FiboSearch Enhancer</strong> requires <strong>Ajax Search for WooCommerce (FiboSearch)</strong> to be active.</p></div>'
    );
}

/**
 * Get a plugin setting value.
 */
function fse_get_option( $key, $default = '' ) {
    static $cache = null;
    if ( $cache === null ) {
        $cache = get_option( 'fse_settings', [] );
    }
    return isset( $cache[ $key ] ) ? $cache[ $key ] : $default;
}

function fse_enqueue_popular_searches() {
    if ( fse_get_option( 'popular_searches_enabled', '1' ) !== '1' ) return;

    wp_enqueue_style(
        'fse-pre-suggestions',
        FSE_URL . 'assets/css/search-pre-suggestions.css',
        [],
        FSE_VERSION
    );

    wp_enqueue_script(
        'fse-pre-suggestions',
        FSE_URL . 'assets/js/search-pre-suggestions.js',
        [ 'jquery' ],
        FSE_VERSION,
        true
    );
}

function fse_enqueue_behavior_tracker() {
    if ( fse_get_option( 'behavior_tracking_enabled', '1' ) !== '1' ) return;

    wp_enqueue_script(
        'fse-behavior-tracker',
        FSE_URL . 'assets/js/behavior-tracker.js',
        [ 'jquery' ],
        FSE_VERSION,
        true
    );

    wp_localize_script( 'fse-behavior-tracker', 'fseTrack', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fse_track_event' ),
    ] );
}
