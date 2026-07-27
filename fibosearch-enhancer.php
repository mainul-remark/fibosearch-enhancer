<?php
/**
 * Plugin Name: FiboSearch Enhancer
 * Description: Extends FiboSearch (Ajax Search for WooCommerce) with fuzzy search, synonyms, variation SKU search, attribute value search, and custom field search.
 * Version: 2.3.1
 * Author: Mainul Islam
 * Text Domain: fse
 * Requires Plugins: ajax-search-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FSE_VERSION', '2.3.1' );
define( 'FSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'fse_init', 20 );

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
    new FSE_ScoreBoost();
    new FSE_FieldWeightScore( $variation_sku, $attribute, $taxonomy, $tag, $category );
    new FSE_IngredientFieldBoost();
    new FSE_SearchLogger();
    new FSE_PriorityMapping();
    new FSE_CompetitorFallback();
    new FSE_DiscountIntentFallback();
    new FSE_AIQueryEnhancer();

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
