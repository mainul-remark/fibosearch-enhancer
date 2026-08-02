<?php
/**
 * Creates and version-upgrades the behavior-tracking DB tables.
 *
 * Two tables: a raw per-event log (fse_search_events) and an aggregate
 * rollup (fse_search_stats) that later ranking/dashboard work reads from
 * instead of scanning raw events live. See
 * docs/superpowers/specs/2026-07-18-search-behavior-tracking-design.md.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_BehaviorSchema {

    const EVENTS_TABLE = 'fse_search_events';
    const STATS_TABLE   = 'fse_search_stats';
    const ZERO_RESULTS_TABLE = 'fse_zero_results';

    const DB_VERSION = '1.1';
    const VERSION_OPTION = 'fse_behavior_schema_version';

    /**
     * Cheap check run on every request (plugins_loaded) — only calls the
     * comparatively expensive dbDelta() when the stored version differs
     * from DB_VERSION, so normal requests pay just one get_option() call.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) return;

        self::install();
        update_option( self::VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * dbDelta() is idempotent — safe to call on every activation and on
     * any version bump. Column/key definitions must follow dbDelta's exact
     * formatting rules (two spaces before PRIMARY KEY, no backticks around
     * types) or it silently skips the change.
     */
    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $events_table    = $wpdb->prefix . self::EVENTS_TABLE;
        $stats_table      = $wpdb->prefix . self::STATS_TABLE;
        $zero_results_table = $wpdb->prefix . self::ZERO_RESULTS_TABLE;

        // keyword(100) in the composite keys below (not the full 191)
        // keeps the index within MySQL's 767-byte prefix limit under
        // utf8mb4 once combined with product_id — a full varchar(191)
        // utf8mb4 column already uses 764 bytes on its own.
        $sql = "CREATE TABLE {$events_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_type varchar(20) NOT NULL,
  keyword varchar(191) NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  position smallint(5) unsigned DEFAULT NULL,
  visitor_id varchar(36) NOT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY keyword_type (keyword(100),event_type),
  KEY product_id (product_id),
  KEY visitor_id (visitor_id),
  KEY created_at (created_at)
) {$charset_collate};
CREATE TABLE {$stats_table} (
  keyword varchar(191) NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  impressions bigint(20) unsigned NOT NULL DEFAULT 0,
  clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  cart_adds bigint(20) unsigned NOT NULL DEFAULT 0,
  purchases bigint(20) unsigned NOT NULL DEFAULT 0,
  last_event_at datetime NOT NULL,
  PRIMARY KEY  (keyword(100),product_id)
) {$charset_collate};
CREATE TABLE {$zero_results_table} (
  keyword varchar(191) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (keyword(100))
) {$charset_collate};";

        dbDelta( $sql );
    }
}
