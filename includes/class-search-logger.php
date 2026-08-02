<?php
/**
 * Logs search terms that returned zero results.
 *
 * The other modules widen matching based on guessed synonym/taxonomy/stemming
 * rules, but the only way to know if those guesses are actually right for
 * this store is to see what real customers search for and get nothing back.
 * Stored as a capped list in a single option (zero-result searches are rare
 * enough on a working setup that a dedicated table would be overkill).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_SearchLogger {

    const OPTION_KEY = 'fse_zero_result_log';
    const MAX_ENTRIES = 200;

    public function __construct() {
        if ( fse_get_option( 'zero_result_logging_enabled', '1' ) !== '1' ) return;

        add_action( 'dgwt/wcas/analytics/after_searching', [ $this, 'log_if_empty' ], 10, 2 );
    }

    /**
     * @param string $keyword The search phrase.
     * @param int    $hits    Total hits across all suggestion groups.
     */
    public function log_if_empty( $keyword, $hits ) {
        $keyword = trim( (string) $keyword );
        if ( $keyword === '' || (int) $hits > 0 ) return;

        global $wpdb;
        require_once FSE_DIR . 'includes/class-behavior-schema.php';
        $table = $wpdb->prefix . FSE_BehaviorSchema::ZERO_RESULTS_TABLE;
        
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (keyword, hits, last_seen) VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)",
             strtolower($keyword), current_time('mysql')
        ));
    }

    /**
     * Zero-result search terms, most frequent first.
     *
     * @return array<int, array{term: string, count: int, last_seen: int}>
     */
    public static function get_entries(): array {
        global $wpdb;
        require_once FSE_DIR . 'includes/class-behavior-schema.php';
        $table = $wpdb->prefix . FSE_BehaviorSchema::ZERO_RESULTS_TABLE;
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword as term, hits as count, UNIX_TIMESTAMP(last_seen) as last_seen FROM {$table} ORDER BY hits DESC LIMIT %d",
            self::MAX_ENTRIES
        ), ARRAY_A );
        
        return is_array($results) ? $results : [];
    }

    public static function clear(): void {
        global $wpdb;
        require_once FSE_DIR . 'includes/class-behavior-schema.php';
        $table = $wpdb->prefix . FSE_BehaviorSchema::ZERO_RESULTS_TABLE;
        $wpdb->query("TRUNCATE TABLE {$table}");
    }
}
