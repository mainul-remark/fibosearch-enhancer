<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_Admin {

    public function init() {
        add_action( 'admin_menu',            [ $this, 'add_menu_page' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_fse_save_synonyms', [ $this, 'ajax_save_synonyms' ] );
        add_action( 'wp_ajax_fse_save_priority_mapping', [ $this, 'ajax_save_priority_mapping' ] );
        add_action( 'admin_post_fse_clear_zero_result_log', [ $this, 'clear_zero_result_log' ] );
        add_action( 'admin_post_fse_clear_ai_failure_log', [ $this, 'clear_ai_failure_log' ] );
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

        register_setting( 'fse_competitor_map_group', 'fse_competitor_brand_map', [
            'sanitize_callback' => [ $this, 'sanitize_competitor_map' ],
        ] );
    }

    /**
     * Parse the "brand => category-slug" textarea into an associative array.
     * Lines that aren't valid "key => value" pairs, or resolve to an empty
     * brand/slug after sanitizing, are silently dropped.
     */
    public function sanitize_competitor_map( $input ) {
        $lines = preg_split( '/\r\n|\r|\n/', (string) $input );
        $map = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' || strpos( $line, '=>' ) === false ) continue;

            [ $brand, $slug ] = array_map( 'trim', explode( '=>', $line, 2 ) );
            $brand = strtolower( sanitize_text_field( $brand ) );
            $slug  = sanitize_title( $slug );

            if ( $brand !== '' && $slug !== '' ) {
                $map[ $brand ] = $slug;
            }
        }

        return $map;
    }

    public function sanitize_settings( $input ) {
        $toggles = [
            'variation_sku_enabled',
            'attribute_search_enabled',
            'category_search_enabled',
            'tag_search_enabled',
            'custom_fields_enabled',
            'custom_taxonomy_search_enabled',
            'synonyms_enabled',
            'fuzzy_enabled',
            'stemming_enabled',
            'score_boost_enabled',
            'field_weight_score_enabled',
            'ingredient_field_boost_enabled',
            'zero_result_logging_enabled',
            'typo_correction_enabled',
            'bangla_translation_enabled',
            'filler_word_strip_enabled',
            'competitor_fallback_enabled',
            'discount_intent_fallback_enabled',
            'priority_mapping_enabled',
            'ai_failure_logging_enabled',
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
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'fse_admin_nonce' ),
            'synonyms'         => get_option( 'fse_synonyms', [] ),
            'priority_mapping' => $this->get_priority_mapping_for_js(),
            'categories'       => $this->get_product_categories_for_js(),
            'saved'            => __( 'Saved!', 'fse' ),
            'error'            => __( 'Error saving.', 'fse' ),
        ] );
    }

    /**
     * Existing priority-mapping entries, with the mapped product's title
     * resolved for display (the input still shows/saves the title as text,
     * re-resolved to an ID on save).
     */
    private function get_priority_mapping_for_js(): array {
        $entries = get_option( 'fse_priority_mapping', [] );
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            $entries = class_exists( 'FSE_PriorityMapping' ) ? FSE_PriorityMapping::DEFAULT_MAPPING : [];
        }

        $out = [];
        foreach ( $entries as $entry ) {
            $product_id = (int) ( $entry['product_id'] ?? 0 );
            $out[] = [
                'keyword'                => $entry['keyword'] ?? '',
                'product'                => $product_id ? get_the_title( $product_id ) : '',
                'main_subcategory'       => $entry['main_subcategory'] ?? '',
                'relevant_subcategories' => array_values( (array) ( $entry['relevant_subcategories'] ?? [] ) ),
                'parent_category'        => $entry['parent_category'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * All product_cat terms as {slug, name}, for the mapping table's
     * category dropdowns.
     */
    private function get_product_categories_for_js(): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

        return array_map( function ( $t ) {
            return [ 'slug' => $t->slug, 'name' => $t->name ];
        }, $terms );
    }

    public function render_page() {
        require FSE_DIR . 'admin/views/page-settings.php';
    }

    /**
     * Clear the logged zero-result search terms.
     */
    public function clear_zero_result_log() {
        check_admin_referer( 'fse_clear_zero_result_log' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        FSE_SearchLogger::clear();

        wp_safe_redirect( add_query_arg( [ 'page' => 'fibosearch-enhancer', 'tab' => 'insights' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Clear the logged AI vendor failures.
     */
    public function clear_ai_failure_log() {
        check_admin_referer( 'fse_clear_ai_failure_log' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        FSE_AIFailureLogger::clear();

        wp_safe_redirect( add_query_arg( [ 'page' => 'fibosearch-enhancer', 'tab' => 'ai-log' ], admin_url( 'admin.php' ) ) );
        exit;
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

    /**
     * AJAX: save the priority-mapping table. Every row is independently
     * validated (product must resolve to a real published product, every
     * category must be a real product_cat slug); rows that fail validation
     * are dropped and reported back rather than silently saved wrong or
     * failing the whole save.
     */
    public function ajax_save_priority_mapping() {
        check_ajax_referer( 'fse_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $raw = json_decode( wp_unslash( $_POST['mapping'] ?? '[]' ), true );
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Invalid data' );
        }

        $clean  = [];
        $errors = [];

        foreach ( $raw as $i => $row ) {
            if ( ! is_array( $row ) ) continue;

            $row_num = $i + 1;
            $keyword = strtolower( sanitize_text_field( $row['keyword'] ?? '' ) );
            if ( '' === $keyword ) continue;

            $product_input = trim( (string) ( $row['product'] ?? '' ) );
            $product_id    = $this->resolve_product_id( $product_input );
            if ( ! $product_id ) {
                $errors[] = "Row {$row_num} (\"{$keyword}\"): product \"{$product_input}\" not found — must be an exact product title or a numeric product ID.";
                continue;
            }

            $main = sanitize_title( $row['main_subcategory'] ?? '' );
            if ( '' !== $main && ! term_exists( $main, 'product_cat' ) ) {
                $errors[] = "Row {$row_num} (\"{$keyword}\"): main sub-category \"{$main}\" doesn't exist.";
                continue;
            }

            $relevant     = [];
            $invalid_slug = false;
            foreach ( [ 'relevant1', 'relevant2', 'relevant3', 'relevant4' ] as $key ) {
                $slug = sanitize_title( $row[ $key ] ?? '' );
                if ( '' === $slug ) continue;

                if ( ! term_exists( $slug, 'product_cat' ) ) {
                    $errors[]     = "Row {$row_num} (\"{$keyword}\"): relevant sub-category \"{$slug}\" doesn't exist.";
                    $invalid_slug = true;
                    break;
                }
                $relevant[] = $slug;
            }
            if ( $invalid_slug ) continue;

            $parent = sanitize_title( $row['parent_category'] ?? '' );
            if ( '' !== $parent && ! term_exists( $parent, 'product_cat' ) ) {
                $errors[] = "Row {$row_num} (\"{$keyword}\"): parent category \"{$parent}\" doesn't exist.";
                continue;
            }

            $clean[] = [
                'keyword'                => $keyword,
                'product_id'             => $product_id,
                'main_subcategory'       => $main,
                'relevant_subcategories' => $relevant,
                'parent_category'        => $parent,
            ];
        }

        update_option( 'fse_priority_mapping', $clean );

        wp_send_json_success( [ 'count' => count( $clean ), 'errors' => $errors ] );
    }

    /**
     * Resolve a "Mapped Product" input (numeric product ID, or exact
     * product title) to a real published product ID. Returns 0 if nothing
     * matches.
     */
    private function resolve_product_id( string $input ): int {
        global $wpdb;

        $input = trim( $input );
        if ( '' === $input ) return 0;

        if ( ctype_digit( $input ) ) {
            $id = (int) $input;
            return ( 'product' === get_post_type( $id ) ) ? $id : 0;
        }

        // Product titles are stored with HTML entities for special
        // characters (e.g. "Silk & Shine" is stored as "Silk &amp; Shine"),
        // but an admin copying the title from the products list sees the
        // decoded form — try both so a plain "&" still resolves.
        $candidates = array_unique( [ $input, htmlspecialchars( $input, ENT_QUOTES ) ] );
        $placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type = 'product' AND post_status = 'publish' AND post_title IN ({$placeholders})
              LIMIT 1",
            $candidates
        ) );
    }
}
