<?php
/**
 * Plugin Name: FiboSearch Enhancer
 * Description: Extends FiboSearch (Ajax Search for WooCommerce) with fuzzy search, synonyms, variation SKU search, attribute value search, and custom field search.
 * Version: 1.0.0
 * Author: Herlan.com
 * Text Domain: fse
 * Requires Plugins: ajax-search-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FSE_VERSION', '1.0.0' );
define( 'FSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'fse_init', 20 );

function fse_init() {
    if ( ! class_exists( 'DgoraWcas\Search' ) ) {
        add_action( 'admin_notices', 'fse_missing_plugin_notice' );
        return;
    }

    require_once FSE_DIR . 'includes/class-helpers.php';
    require_once FSE_DIR . 'includes/class-variation-sku-search.php';
    require_once FSE_DIR . 'includes/class-attribute-search.php';
    require_once FSE_DIR . 'includes/class-tag-search.php';
    require_once FSE_DIR . 'includes/class-custom-field-search.php';
    require_once FSE_DIR . 'includes/class-synonym-search.php';
    require_once FSE_DIR . 'includes/class-fuzzy-search.php';
    require_once FSE_DIR . 'includes/class-score-boost.php';

    new FSE_VariationSkuSearch();
    new FSE_AttributeSearch();
    new FSE_TagSearch();
    new FSE_CustomFieldSearch();
    new FSE_SynonymSearch();
    new FSE_FuzzySearch();
    new FSE_ScoreBoost();

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
