<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_PopularSearches {

    const TRANSIENT_KEY = 'fse_popular_searches';
    const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;
    const MIN_TERMS     = 3;
    const MAX_TERMS     = 8;

    public function __construct() {
        if ( fse_get_option( 'popular_searches_enabled', '1' ) !== '1' ) return;

        add_filter( 'dgwt/wcas/scripts/show_recently_searched_phrases', '__return_true' );
        add_action( 'wp_enqueue_scripts', [ $this, 'inline_terms' ], 20 );
    }

    public function inline_terms(): void {
        if ( ! wp_script_is( 'fse-pre-suggestions', 'enqueued' ) ) return;

        $terms = $this->get_terms();
        if ( count( $terms ) < self::MIN_TERMS ) return;

        wp_localize_script( 'fse-pre-suggestions', 'fsePopular', [
            'terms' => $terms,
        ] );
    }

    public function get_terms(): array {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) return $cached;

        $terms = $this->merge_terms();
        set_transient( self::TRANSIENT_KEY, $terms, self::TRANSIENT_TTL );
        return $terms;
    }

    private function merge_terms(): array {
        $manual = $this->manual_terms();
        if ( ! empty( $manual ) ) {
            return array_slice( $manual, 0, self::MAX_TERMS );
        }

        return $this->query_terms();
    }

    private function manual_terms(): array {
        $raw = (string) fse_get_option( 'popular_searches_manual', '' );
        if ( '' === trim( $raw ) ) return [];

        $terms = [];
        foreach ( explode( ',', $raw ) as $term ) {
            $term = sanitize_text_field( trim( $term ) );
            if ( '' !== $term ) {
                $terms[] = $term;
            }
        }
        return $terms;
    }

    private function query_terms(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'dgwt_wcas_stats';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $since   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT phrase
             FROM {$table}
             WHERE hits > 0
               AND autocomplete = 1
               AND created_at > %s
             GROUP BY phrase
             ORDER BY COUNT(id) DESC
             LIMIT %d",
            $since,
            self::MAX_TERMS
        ) );

        return is_array( $results ) ? array_values( $results ) : [];
    }
}
