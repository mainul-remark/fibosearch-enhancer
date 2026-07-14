<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_Admin {

    public function init() {
        add_action( 'admin_menu',            [ $this, 'add_menu_page' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_fse_save_synonyms', [ $this, 'ajax_save_synonyms' ] );
    }

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            'FiboSearch Enhancer',
            'Search Enhancer',
            'manage_options',
            'fibosearch-enhancer',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'fse_settings_group', 'fse_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ) {
        $toggles = [
            'variation_sku_enabled',
            'attribute_search_enabled',
            'tag_search_enabled',
            'custom_fields_enabled',
            'custom_taxonomy_search_enabled',
            'synonyms_enabled',
            'fuzzy_enabled',
            'score_boost_enabled',
            'ai_enabled',
            'ai_gemini_enabled',
            'ai_openrouter_enabled',
            'ai_groq_enabled',
        ];

        $clean = [];
        foreach ( $toggles as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? '1' : '0';
        }

        $text_fields = [
            'ai_gemini_key',
            'ai_openrouter_key',
            'ai_openrouter_model',
            'ai_groq_key',
            'ai_groq_model',
        ];
        foreach ( $text_fields as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
        }

        $ttl = isset( $input['ai_cache_ttl_hours'] ) ? (int) $input['ai_cache_ttl_hours'] : 24;
        $clean['ai_cache_ttl_hours'] = (string) max( 1, min( 168, $ttl ) );

        $timeout = isset( $input['ai_timeout_seconds'] ) ? (float) $input['ai_timeout_seconds'] : 1.5;
        $clean['ai_timeout_seconds'] = (string) max( 0.5, min( 5.0, $timeout ) );

        // Manual custom field keys — commented out (now auto-detects all public meta fields)
        // $clean['custom_field_keys'] = sanitize_textarea_field( $input['custom_field_keys'] ?? '' );

        return $clean;
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'woocommerce_page_fibosearch-enhancer' ) return;

        wp_enqueue_style(
            'fse-admin',
            FSE_URL . 'assets/css/admin.css',
            [],
            FSE_VERSION
        );

        wp_enqueue_script(
            'fse-admin',
            FSE_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            FSE_VERSION,
            true
        );

        wp_localize_script( 'fse-admin', 'fse_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'fse_admin_nonce' ),
            'synonyms' => get_option( 'fse_synonyms', [] ),
            'saved'    => __( 'Saved!', 'fse' ),
            'error'    => __( 'Error saving.', 'fse' ),
        ] );
    }

    public function render_page() {
        require FSE_DIR . 'admin/views/page-settings.php';
    }

    /**
     * AJAX: save the synonym groups (array of arrays of terms).
     */
    public function ajax_save_synonyms() {
        check_ajax_referer( 'fse_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $raw = json_decode( wp_unslash( $_POST['synonyms'] ?? '[]' ), true );
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Invalid data' );
        }

        $clean = [];
        foreach ( $raw as $group ) {
            if ( ! is_array( $group ) ) continue;
            $terms = array_values( array_filter( array_map( 'sanitize_text_field', $group ) ) );
            if ( count( $terms ) >= 2 ) {
                $clean[] = $terms;
            }
        }

        update_option( 'fse_synonyms', $clean );

        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }
}
