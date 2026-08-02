<?php
/**
 * WP-Cron jobs for search-behavior tracking: hourly rollup of raw events
 * into the aggregate stats table, and daily purge of old raw events. See
 * docs/superpowers/specs/2026-07-18-search-behavior-tracking-design.md.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_BehaviorRollup {

    const ROLLUP_HOOK = 'fse_behavior_rollup';
    const PURGE_HOOK   = 'fse_behavior_purge';
    const RETENTION_DAYS = 90;
    const WATERMARK_OPTION = 'fse_behavior_rollup_watermark';

    public function __construct() {
        add_action( self::ROLLUP_HOOK, [ $this, 'run_rollup' ] );
        add_action( self::PURGE_HOOK, [ $this, 'run_purge' ] );
    }

    public static function schedule_events(): void {
        if ( ! wp_next_scheduled( self::ROLLUP_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::ROLLUP_HOOK );
        }
        if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::PURGE_HOOK );
        }
    }

    public static function unschedule_events(): void {
        wp_clear_scheduled_hook( self::ROLLUP_HOOK );
        wp_clear_scheduled_hook( self::PURGE_HOOK );
    }

    /**
     * Aggregates every raw event with id in (watermark, max_id] into
     * fse_search_stats, then advances the watermark to max_id. Bounding
     * the batch by max_id (captured before the INSERT..SELECT runs) means
     * events written concurrently, mid-run, are simply picked up by next
     * hour's run rather than raced against.
     */
    public function run_rollup(): void {
        global $wpdb;

        $events_table = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;
        $stats_table   = $wpdb->prefix . FSE_BehaviorSchema::STATS_TABLE;

        $watermark = (int) get_option( self::WATERMARK_OPTION, 0 );
        $max_id    = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$events_table}" );

        if ( $max_id <= $watermark ) return;

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$stats_table} (keyword, product_id, impressions, clicks, cart_adds, purchases, last_event_at)
             SELECT keyword, product_id,
               SUM(event_type = 'impression'),
               SUM(event_type = 'click'),
               SUM(event_type = 'cart_add'),
               SUM(event_type = 'purchase'),
               MAX(created_at)
             FROM {$events_table}
             WHERE id > %d AND id <= %d
             GROUP BY keyword, product_id
             ON DUPLICATE KEY UPDATE
               impressions = impressions + VALUES(impressions),
               clicks = clicks + VALUES(clicks),
               cart_adds = cart_adds + VALUES(cart_adds),
               purchases = purchases + VALUES(purchases),
               last_event_at = GREATEST(last_event_at, VALUES(last_event_at))",
            $watermark, $max_id
        ) );

        // Only advance the watermark when the aggregation actually
        // succeeded — on failure (false), leave it where it is so the
        // next scheduled run retries these same events rather than
        // silently losing them once they age past the retention purge.
        if ( false !== $result ) {
            update_option( self::WATERMARK_OPTION, $max_id, false );
        }
    }

    /**
     * Deletes raw events older than RETENTION_DAYS, batched to avoid a
     * single long-running DELETE locking the table. fse_search_stats is
     * never touched here — aggregates are kept indefinitely.
     */
    public function run_purge(): void {
        global $wpdb;

        $table = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;
        $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::RETENTION_DAYS * DAY_IN_SECONDS );

        $max_batches = 50; // safety cap: at most 50,000 rows per cron run.
        for ( $i = 0; $i < $max_batches; $i++ ) {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s ORDER BY id LIMIT 1000",
                $cutoff
            ) );
            if ( ! $deleted ) break;
        }
    }
}
