# Search Behavior Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the data-capture foundation (impressions, clicks, cart-adds, purchases, cross-session attribution) that later search-ranking, dashboard, and personalization phases will read from — no ranking or UI changes in this plan.

**Architecture:** Three new PHP classes — `FSE_BehaviorSchema` (creates/upgrades two DB tables via `dbDelta()`), `FSE_BehaviorTracker` (visitor cookie, event-write AJAX endpoint, WooCommerce cart/purchase attribution hooks), `FSE_BehaviorRollup` (hourly aggregation + daily purge WP-Cron jobs) — plus one new JS file that observes FiboSearch's own AJAX responses and DOM to capture impressions/clicks without patching FiboSearch itself.

**Tech Stack:** PHP 8.4, WordPress `$wpdb`/dbDelta/Cron/hooks APIs, WooCommerce hooks, vanilla JS + jQuery (already a FiboSearch dependency on every page this runs), no build step, no package manager, no existing automated test suite (verification is `php -l` syntax checks + `wp eval`/`wp db query`/`curl` manual functional testing, matching this plugin's existing AI-module plan).

## Global Constraints

- Every new PHP file starts with `if ( ! defined( 'ABSPATH' ) ) exit;` (matches every existing file in this plugin).
- No exceptions may cross module boundaries — every tracking write path (beacon handler, cart-add hook, purchase hooks) must no-op silently on any failure. Tracking must never be able to break search, cart, or checkout.
- New settings live in the single `fse_settings` option via `FSE_Admin::sanitize_settings`, reachable through `fse_get_option( $key, $default )` — no new standalone `add_option`/`register_setting` calls. One new key: `behavior_tracking_enabled` (default `'1'`).
- Cookie name: `fse_vid`. TTL: `YEAR_IN_SECONDS`. `httponly` true, `SameSite=Lax`.
- Attribution window: **30 days**, last-click model (class constant `ATTRIBUTION_WINDOW_DAYS = 30`, not admin-configurable in this phase).
- Raw-event retention: **90 days** (class constant `RETENTION_DAYS = 90`, not admin-configurable in this phase). Aggregate stats table is never purged.
- `cart_add` and `purchase` events are **never** accepted from client input — only written server-side from WooCommerce hooks, to prevent a shopper (or bot) spoofing fake conversions via the public beacon endpoint. The beacon endpoint only accepts `impression` and `click`.
- FiboSearch AJAX search endpoint: `GET /?wc-ajax=dgwt_wcas_ajax_search&s={keyword}`. Response shape (confirmed by live testing against this store): `{"suggestions": [...], "total": N, "time": "...", "engine": "...", "v": "..."}`. Each product suggestion has `type: "product"`, `post_id` (int), `value` (title), `url` — non-product entries (`type: "headline"`, `type: "taxonomy"`) are mixed into the same array and must be filtered out.
- Suggestion DOM elements FiboSearch renders have class `dgwt-wcas-suggestion` and a `data-index="N"` attribute matching that suggestion's index in the last response's `suggestions` array (confirmed in `ajax-search-for-woocommerce/assets/js/search.js`).

---

## File Structure

- Create: `includes/class-behavior-schema.php` — `FSE_BehaviorSchema`, table creation/versioning (no hooks).
- Create: `includes/class-behavior-tracker.php` — `FSE_BehaviorTracker`, cookie + beacon endpoint + attribution hooks.
- Create: `includes/class-behavior-rollup.php` — `FSE_BehaviorRollup`, cron jobs.
- Create: `assets/js/behavior-tracker.js` — client-side capture.
- Modify: `fibosearch-enhancer.php` — require/instantiate the three new classes, register the activation hook, enqueue the JS file.
- Modify: `admin/class-admin.php` — extend `sanitize_settings()` to accept `behavior_tracking_enabled`.
- Modify: `admin/views/page-settings.php` — add the toggle checkbox (reuse the existing toggle-row markup pattern already used for e.g. `fuzzy_enabled`).

---

### Task 1: FSE_BehaviorSchema — table creation and versioning

**Files:**
- Create: `includes/class-behavior-schema.php`

**Interfaces:**
- Produces: `FSE_BehaviorSchema::install(): void` (idempotent `dbDelta()` call), `FSE_BehaviorSchema::maybe_upgrade(): void` (cheap version-gated check, calls `install()` only when needed). Consumed by Task 6 (plugin bootstrap) and by the activation hook.
- Produces table names as public constants: `FSE_BehaviorSchema::EVENTS_TABLE` and `FSE_BehaviorSchema::STATS_TABLE`, both `{$wpdb->prefix}`-relative table *suffixes* (e.g. `'fse_search_events'`) — callers build the full name via `$wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE`. Consumed by Tasks 2–5, 7–8.

- [ ] **Step 1: Create the file**

```php
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

    const DB_VERSION = '1.0';
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
) {$charset_collate};";

        dbDelta( $sql );
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-schema.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manually verify table creation**

Run (from the plugin's WordPress root, via WP-CLI):
```bash
wp eval "require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-schema.php'; FSE_BehaviorSchema::install(); echo 'done';"
wp db query "DESCRIBE {prefix}fse_search_events;"
wp db query "DESCRIBE {prefix}fse_search_stats;"
```
(Replace `{prefix}` with the site's actual table prefix, e.g. `hreco_`.)

Expected: both `DESCRIBE` calls list exactly the columns defined above, no errors.

- [ ] **Step 4: Verify version gating works**

Run:
```bash
wp eval "require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-schema.php'; var_dump(get_option('fse_behavior_schema_version'));"
```
Expected: `string(3) "1.0"`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-behavior-schema.php
git commit -m "Add FSE_BehaviorSchema: creates search-behavior tracking tables"
```

---

### Task 2: FSE_BehaviorTracker — visitor cookie + core event writer + beacon endpoint

**Files:**
- Create: `includes/class-behavior-tracker.php`

**Interfaces:**
- Consumes: `FSE_BehaviorSchema::EVENTS_TABLE` (Task 1).
- Produces: `FSE_BehaviorTracker::insert_event( array $args ): void` where `$args` has keys `event_type` (string), `keyword` (string), `product_id` (int), `position` (?int), `visitor_id` (string), `user_id` (?int), `order_id` (?int). Consumed by Tasks 4 and 5 (cart-add/purchase hooks) inside this same class.
- Produces the AJAX action `fse_track_event` (both `wp_ajax_` and `wp_ajax_nopriv_` variants), accepting POST field `events` (JSON-encoded array of `{type: 'impression'|'click', keyword: string, product_id: int, position: int|null}`) and POST field `nonce`. Consumed by Task 3 (JS).

- [ ] **Step 1: Create the file with cookie handling, bot/rate-limit filtering, the core writer, and the beacon endpoint**

```php
<?php
/**
 * Visitor identity, event writes, and WooCommerce attribution hooks for
 * search-behavior tracking. See
 * docs/superpowers/specs/2026-07-18-search-behavior-tracking-design.md.
 *
 * Every public entry point here (the beacon handler, and the WooCommerce
 * hooks added in later tasks) must fail silently on any error condition —
 * this is optional analytics instrumentation, never load-bearing for
 * search, cart, or checkout.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_BehaviorTracker {

    const COOKIE_NAME = 'fse_vid';
    const ATTRIBUTION_WINDOW_DAYS = 30;
    const MAX_EVENTS_PER_MINUTE = 60;
    const NONCE_ACTION = 'fse_track_event';

    /** Case-insensitive substrings checked against the User-Agent header. */
    const BOT_UA_SUBSTRINGS = [
        'bot', 'crawl', 'spider', 'slurp', 'ahrefs', 'semrush',
        'mj12bot', 'yandex', 'baidu', 'facebookexternalhit',
    ];

    public function __construct() {
        if ( fse_get_option( 'behavior_tracking_enabled', '1' ) !== '1' ) return;

        add_action( 'init', [ $this, 'ensure_visitor_cookie' ] );
        add_action( 'wp_ajax_fse_track_event', [ $this, 'handle_track_event' ] );
        add_action( 'wp_ajax_nopriv_fse_track_event', [ $this, 'handle_track_event' ] );
    }

    /**
     * Assigns a persistent, cross-session visitor id on first visit. Set
     * httponly (JS never needs to read it — the beacon endpoint reads
     * $_COOKIE server-side) and SameSite=Lax (survives normal navigation,
     * blocked on cross-site requests).
     */
    public function ensure_visitor_cookie(): void {
        if ( is_admin() ) return;
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) return;
        if ( headers_sent() ) return;

        $visitor_id = wp_generate_uuid4();

        setcookie(
            self::COOKIE_NAME,
            $visitor_id,
            [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[ self::COOKIE_NAME ] = $visitor_id;
    }

    private function get_visitor_id(): string {
        return isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : '';
    }

    private function is_bot(): bool {
        $ua = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        if ( '' === $ua ) return true; // no UA at all — treat as non-human.

        foreach ( self::BOT_UA_SUBSTRINGS as $needle ) {
            if ( false !== strpos( $ua, $needle ) ) return true;
        }
        return false;
    }

    /**
     * Simple per-visitor throttle using a transient counter. Returns true
     * (caller should drop the event) once the visitor exceeds
     * MAX_EVENTS_PER_MINUTE in the current 60-second window.
     */
    private function is_rate_limited( string $visitor_id ): bool {
        if ( '' === $visitor_id ) return true;

        $key   = 'fse_bt_rl_' . md5( $visitor_id );
        $count = (int) get_transient( $key );

        if ( $count >= self::MAX_EVENTS_PER_MINUTE ) return true;

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return false;
    }

    /**
     * Core writer. Every field is expected pre-validated by the caller —
     * this method itself never throws; a DB error here must not propagate.
     */
    public function insert_event( array $args ): void {
        global $wpdb;

        $table = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;

        $wpdb->insert(
            $table,
            [
                'event_type' => $args['event_type'],
                'keyword'    => $args['keyword'],
                'product_id' => (int) $args['product_id'],
                'position'   => $args['position'] ?? null,
                'visitor_id' => $args['visitor_id'],
                'user_id'    => $args['user_id'] ?? null,
                'order_id'   => $args['order_id'] ?? null,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s' ]
        );
    }

    /**
     * Beacon endpoint for impression/click events only — cart_add and
     * purchase are never accepted from client input (see Global
     * Constraints: only WooCommerce hooks may write those event types).
     */
    public function handle_track_event(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( null, 403 );
        }

        $visitor_id = $this->get_visitor_id();

        if ( '' === $visitor_id || $this->is_bot() || $this->is_rate_limited( $visitor_id ) ) {
            wp_send_json_success(); // fail open — client doesn't need to know/retry.
        }

        $raw_events = isset( $_POST['events'] ) ? json_decode( wp_unslash( (string) $_POST['events'] ), true ) : null;
        if ( ! is_array( $raw_events ) ) {
            wp_send_json_success();
        }

        $user_id = get_current_user_id();
        $user_id = $user_id > 0 ? $user_id : null;

        foreach ( array_slice( $raw_events, 0, 40 ) as $event ) {
            $type = is_string( $event['type'] ?? null ) ? $event['type'] : '';
            if ( ! in_array( $type, [ 'impression', 'click' ], true ) ) continue;

            $keyword = isset( $event['keyword'] ) ? strtolower( trim( sanitize_text_field( (string) $event['keyword'] ) ) ) : '';
            $product_id = isset( $event['product_id'] ) ? (int) $event['product_id'] : 0;
            if ( '' === $keyword || $product_id <= 0 ) continue;

            $this->insert_event( [
                'event_type' => $type,
                'keyword'    => $keyword,
                'product_id' => $product_id,
                'position'   => isset( $event['position'] ) ? (int) $event['position'] : null,
                'visitor_id' => $visitor_id,
                'user_id'    => $user_id,
            ] );
        }

        wp_send_json_success();
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-tracker.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Wire it into the plugin bootstrap temporarily to test in isolation**

Run:
```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-schema.php';
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
FSE_BehaviorSchema::install();
\$t = new FSE_BehaviorTracker();
\$t->insert_event([
  'event_type' => 'impression',
  'keyword'    => 'test keyword',
  'product_id' => 1,
  'position'   => 0,
  'visitor_id' => 'test-visitor-uuid',
]);
echo 'inserted';
"
wp db query "SELECT * FROM {prefix}fse_search_events ORDER BY id DESC LIMIT 1;"
```
Expected: one row with `keyword = 'test keyword'`, `event_type = 'impression'`, `visitor_id = 'test-visitor-uuid'`.

- [ ] **Step 4: Manually verify the beacon endpoint end-to-end (bypassing nonce, since a raw curl call has none)**

This step confirms the endpoint rejects unauthenticated calls as designed — a 403/failure here is the *expected* passing result:
```bash
curl -s -X POST "http://localhost/herlanlive5/wp-admin/admin-ajax.php" \
  -d "action=fse_track_event&events=%5B%5D&nonce=invalid"
```
Expected: `{"success":false,"data":null}` (or similar WP `wp_send_json_error` shape) — confirms the nonce check rejects bad requests. Full working-nonce verification happens in Task 3 once the JS supplies a real nonce.

- [ ] **Step 5: Commit**

```bash
git add includes/class-behavior-tracker.php
git commit -m "Add FSE_BehaviorTracker: visitor cookie, event writer, beacon endpoint"
```

---

### Task 3: behavior-tracker.js — client-side impression/click capture

**Files:**
- Create: `assets/js/behavior-tracker.js`
- Modify: `fibosearch-enhancer.php` (enqueue only — full bootstrap wiring happens in Task 6; this step just adds the `wp_enqueue_scripts` registration so this task is independently testable)

**Interfaces:**
- Consumes: global `fseTrack` object (localized via `wp_localize_script`) with shape `{ ajax_url: string, nonce: string }`.
- Consumes: the beacon endpoint from Task 2 (`action=fse_track_event`).
- Produces: no interface consumed by later tasks — this is a leaf.

- [ ] **Step 1: Create the JS file**

```js
/**
 * Captures search-result impressions and clicks by observing FiboSearch's
 * own AJAX responses and rendered DOM, rather than patching FiboSearch's
 * internals — resilient to FiboSearch JS updates.
 */
( function ( $ ) {
	'use strict';

	if ( typeof fseTrack === 'undefined' ) return;

	// Most recent search's suggestions, indexed exactly as FiboSearch
	// indexes them in the DOM's data-index attribute (confirmed: includes
	// non-product entries like headlines/taxonomy terms).
	var lastSuggestions = [];
	var lastKeyword = '';

	function extractKeywordFromUrl( url ) {
		var match = /[?&]s=([^&]*)/.exec( url );
		return match ? decodeURIComponent( match[ 1 ].replace( /\+/g, ' ' ) ) : '';
	}

	function sendEvents( events ) {
		if ( ! events.length ) return;

		var body = new URLSearchParams();
		body.set( 'action', 'fse_track_event' );
		body.set( 'nonce', fseTrack.nonce );
		body.set( 'events', JSON.stringify( events ) );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon(
				fseTrack.ajax_url,
				new Blob( [ body.toString() ], { type: 'application/x-www-form-urlencoded' } )
			);
		} else {
			$.post( fseTrack.ajax_url, Object.fromEntries( body ) );
		}
	}

	// Impressions: fires after every FiboSearch search AJAX call completes.
	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! settings.url || settings.url.indexOf( 'dgwt_wcas_ajax_search' ) === -1 ) return;

		var keyword = extractKeywordFromUrl( settings.url );
		if ( ! keyword ) return;

		var data;
		try {
			data = typeof xhr.responseJSON !== 'undefined' ? xhr.responseJSON : JSON.parse( xhr.responseText );
		} catch ( e ) {
			return;
		}
		if ( ! data || ! Array.isArray( data.suggestions ) ) return;

		lastKeyword = keyword.toLowerCase();
		lastSuggestions = data.suggestions;

		var impressions = [];
		data.suggestions.forEach( function ( suggestion, index ) {
			if ( suggestion.type !== 'product' || ! suggestion.post_id ) return;
			impressions.push( {
				type: 'impression',
				keyword: lastKeyword,
				product_id: suggestion.post_id,
				position: index,
			} );
		} );

		sendEvents( impressions );
	} );

	// Clicks: delegated listener on FiboSearch's rendered suggestion items.
	$( document ).on( 'click', '.dgwt-wcas-suggestion', function () {
		var index = parseInt( $( this ).attr( 'data-index' ), 10 );
		if ( isNaN( index ) || ! lastSuggestions[ index ] ) return;

		var suggestion = lastSuggestions[ index ];
		if ( suggestion.type !== 'product' || ! suggestion.post_id ) return;

		sendEvents( [ {
			type: 'click',
			keyword: lastKeyword,
			product_id: suggestion.post_id,
			position: index,
		} ] );
	} );

} )( jQuery );
```

- [ ] **Step 2: Enqueue the script**

In `fibosearch-enhancer.php`, inside `fse_init()`, after the existing module instantiations and before the `is_admin()` block, add:

```php
    add_action( 'wp_enqueue_scripts', 'fse_enqueue_behavior_tracker' );
```

And add this new function below `fse_get_option()`:

```php
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
        'nonce'    => wp_create_nonce( FSE_BehaviorTracker::NONCE_ACTION ),
    ] );
}
```

- [ ] **Step 3: Syntax check the PHP change**

Run: `php -l fibosearch-enhancer.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Manual browser verification**

1. Load the site homepage with the search box, open browser DevTools → Network tab.
2. Type a search term that returns real products (e.g. a known product name).
3. Confirm a request to `?wc-ajax=dgwt_wcas_ajax_search...` completes, and immediately after, a `POST admin-ajax.php` request with `action=fse_track_event` fires (impressions).
4. Click a product suggestion. Confirm a second `fse_track_event` POST fires *before* the page navigates away (Network tab should show it as a completed/pending beacon, not cancelled).
5. Run: `wp db query "SELECT event_type, keyword, product_id, position FROM {prefix}fse_search_events ORDER BY id DESC LIMIT 5;"`
   Expected: rows for both `impression` (one per shown product, increasing `position`) and `click` (one row, matching the product you clicked).

- [ ] **Step 5: Commit**

```bash
git add assets/js/behavior-tracker.js fibosearch-enhancer.php
git commit -m "Add client-side impression/click capture for search behavior tracking"
```

---

### Task 4: Cart-add attribution

**Files:**
- Modify: `includes/class-behavior-tracker.php`

**Interfaces:**
- Consumes: `insert_event()` (Task 2, same class).
- Produces: `find_recent_click( int $product_id, string $visitor_id, ?int $user_id ): ?string` (returns the attributed keyword, or `null`). Consumed by Task 5.

- [ ] **Step 1: Add the attribution lookup and the `woocommerce_add_to_cart` hook**

In `includes/class-behavior-tracker.php`, add to the constructor:

```php
        add_action( 'woocommerce_add_to_cart', [ $this, 'handle_add_to_cart' ], 10, 6 );
```

And add these two methods to the class:

```php
    /**
     * The most recent 'click' event on this product by this visitor within
     * the attribution window — the keyword that gets credit for whatever
     * happens next (cart-add or purchase). Matches by visitor_id (stable
     * across login, since the cookie itself never changes) with a
     * user_id fallback for the case where the cookie was cleared but the
     * shopper is signed in.
     */
    private function find_recent_click( int $product_id, string $visitor_id, ?int $user_id ): ?string {
        global $wpdb;

        $table    = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;
        $since    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ATTRIBUTION_WINDOW_DAYS * DAY_IN_SECONDS );

        if ( null !== $user_id ) {
            $sql = $wpdb->prepare(
                "SELECT keyword FROM {$table}
                 WHERE event_type = 'click' AND product_id = %d AND created_at >= %s
                   AND ( visitor_id = %s OR user_id = %d )
                 ORDER BY created_at DESC LIMIT 1",
                $product_id, $since, $visitor_id, $user_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT keyword FROM {$table}
                 WHERE event_type = 'click' AND product_id = %d AND created_at >= %s
                   AND visitor_id = %s
                 ORDER BY created_at DESC LIMIT 1",
                $product_id, $since, $visitor_id
            );
        }

        $keyword = $wpdb->get_var( $sql );
        return is_string( $keyword ) && '' !== $keyword ? $keyword : null;
    }

    /**
     * Only logs a cart_add when it's attributable to a tracked search —
     * cart-adds that didn't originate from search are simply not tracked
     * here (that's outside this module's scope).
     */
    public function handle_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
        $visitor_id = $this->get_visitor_id();
        if ( '' === $visitor_id ) return;

        $user_id = get_current_user_id();
        $user_id = $user_id > 0 ? $user_id : null;

        $keyword = $this->find_recent_click( (int) $product_id, $visitor_id, $user_id );
        if ( null === $keyword ) return;

        $this->insert_event( [
            'event_type' => 'cart_add',
            'keyword'    => $keyword,
            'product_id' => (int) $product_id,
            'visitor_id' => $visitor_id,
            'user_id'    => $user_id,
        ] );
    }
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-tracker.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-schema.php';
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
\$t = new FSE_BehaviorTracker();
\$t->insert_event(['event_type'=>'click','keyword'=>'test lipstick','product_id'=>21617,'visitor_id'=>'cart-test-visitor']);
\$_COOKIE['fse_vid'] = 'cart-test-visitor';
do_action( 'woocommerce_add_to_cart', 'fakekey', 21617, 1, 0, [], [] );
echo 'done';
"
wp db query "SELECT event_type, keyword, product_id FROM {prefix}fse_search_events WHERE visitor_id='cart-test-visitor' ORDER BY id;"
```
Expected: two rows — the seeded `click` and a new `cart_add`, both `keyword = 'test lipstick'`, `product_id = 21617`.

- [ ] **Step 4: Verify the no-attribution case doesn't log anything**

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
\$t = new FSE_BehaviorTracker();
\$_COOKIE['fse_vid'] = 'cart-test-visitor-2';
do_action( 'woocommerce_add_to_cart', 'fakekey2', 999999, 1, 0, [], [] );
echo 'done';
"
wp db query "SELECT COUNT(*) FROM {prefix}fse_search_events WHERE visitor_id='cart-test-visitor-2';"
```
Expected: `0` — no prior click exists for this visitor/product, so nothing is logged.

- [ ] **Step 5: Commit**

```bash
git add includes/class-behavior-tracker.php
git commit -m "Add cart-add attribution to search behavior tracking"
```

---

### Task 5: Purchase attribution

**Files:**
- Modify: `includes/class-behavior-tracker.php`

**Interfaces:**
- Consumes: `find_recent_click()` (Task 4, same class).
- Produces: nothing consumed by later tasks — this is a leaf within the tracker.

- [ ] **Step 1: Add the two WooCommerce order hooks**

Add to the constructor:

```php
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'store_visitor_on_order' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ] );
```

And add these two methods:

```php
    /**
     * Runs inside the customer's own checkout request — the only point in
     * the order lifecycle guaranteed to have $_COOKIE available (later
     * hooks may fire from an admin action, webhook, or cron with no
     * request-level cookie context at all).
     */
    public function store_visitor_on_order( int $order_id ): void {
        $visitor_id = $this->get_visitor_id();
        if ( '' === $visitor_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $order->update_meta_data( '_fse_visitor_id', $visitor_id );
        $order->save();
    }

    /**
     * Fires once payment/fulfillment is confirmed (not on 'processing',
     * so refunded/cancelled orders never get credited). For each line
     * item, attributes to whatever search led to the click that (per
     * find_recent_click) is most plausibly responsible.
     */
    public function handle_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $visitor_id = (string) $order->get_meta( '_fse_visitor_id' );
        if ( '' === $visitor_id ) return;

        $user_id = $order->get_customer_id();
        $user_id = $user_id > 0 ? $user_id : null;

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( ! $product_id ) continue;

            $keyword = $this->find_recent_click( (int) $product_id, $visitor_id, $user_id );
            if ( null === $keyword ) continue;

            $this->insert_event( [
                'event_type' => 'purchase',
                'keyword'    => $keyword,
                'product_id' => (int) $product_id,
                'visitor_id' => $visitor_id,
                'user_id'    => $user_id,
                'order_id'   => $order_id,
            ] );
        }
    }
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-tracker.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

Requires a real (or test-mode) WooCommerce order. Using WP-CLI with the `wc` command (WooCommerce's CLI, already available since WooCommerce is active):

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
\$t = new FSE_BehaviorTracker();
\$t->insert_event(['event_type'=>'click','keyword'=>'test purchase item','product_id'=>21617,'visitor_id'=>'purchase-test-visitor']);
echo 'seeded';
"
wp eval "
\$order = wc_create_order();
\$order->add_product( wc_get_product( 21617 ), 1 );
\$order->calculate_totals();
\$order->save();
\$_COOKIE['fse_vid'] = 'purchase-test-visitor';
do_action( 'woocommerce_checkout_order_processed', \$order->get_id() );
\$order->update_status( 'completed' );
echo \$order->get_id();
"
wp db query "SELECT event_type, keyword, product_id, order_id FROM {prefix}fse_search_events WHERE visitor_id='purchase-test-visitor' ORDER BY id;"
```
Expected: three rows — the seeded `click`, and (from `update_status('completed')` firing `woocommerce_order_status_completed`) a `purchase` row with `keyword = 'test purchase item'`, `product_id = 21617`, and `order_id` matching the created order.

- [ ] **Step 4: Clean up the test order**

```bash
wp eval "wc_get_order( \$order_id_from_step_3 )->delete( true );"
```
(Substitute the actual order ID printed in Step 3.)

- [ ] **Step 5: Commit**

```bash
git add includes/class-behavior-tracker.php
git commit -m "Add purchase attribution to search behavior tracking"
```

---

### Task 6: wp_login backfill

**Files:**
- Modify: `includes/class-behavior-tracker.php`

**Interfaces:**
- Consumes: none new.
- Produces: none consumed by later tasks.

- [ ] **Step 1: Add the login hook**

Add to the constructor:

```php
        add_action( 'wp_login', [ $this, 'backfill_user_id' ], 10, 2 );
```

And add this method:

```php
    /**
     * On login, attach this visitor's identified user_id to any of their
     * prior events that were recorded while they were still a guest.
     */
    public function backfill_user_id( string $user_login, WP_User $user ): void {
        $visitor_id = $this->get_visitor_id();
        if ( '' === $visitor_id ) return;

        global $wpdb;
        $table = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET user_id = %d WHERE visitor_id = %s AND user_id IS NULL",
            $user->ID, $visitor_id
        ) );
    }
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-tracker.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
\$t = new FSE_BehaviorTracker();
\$t->insert_event(['event_type'=>'impression','keyword'=>'login test','product_id'=>1,'visitor_id'=>'login-test-visitor']);
\$_COOKIE['fse_vid'] = 'login-test-visitor';
\$user = get_user_by('id', 1);
do_action( 'wp_login', \$user->user_login, \$user );
echo 'done';
"
wp db query "SELECT keyword, visitor_id, user_id FROM {prefix}fse_search_events WHERE visitor_id='login-test-visitor';"
```
Expected: the row's `user_id` is now `1` (or whichever user ID was used), was `NULL` before.

- [ ] **Step 4: Commit**

```bash
git add includes/class-behavior-tracker.php
git commit -m "Backfill user_id onto prior guest events on login"
```

---

### Task 7: FSE_BehaviorRollup — hourly aggregation

**Files:**
- Create: `includes/class-behavior-rollup.php`

**Interfaces:**
- Consumes: `FSE_BehaviorSchema::EVENTS_TABLE`, `FSE_BehaviorSchema::STATS_TABLE` (Task 1).
- Produces: WP-Cron hook `fse_behavior_rollup`. Consumed by Task 9 (bootstrap wiring schedules it).

- [ ] **Step 1: Create the file**

```php
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

        $wpdb->query( $wpdb->prepare(
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

        update_option( self::WATERMARK_OPTION, $max_id, false );
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
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/class-behavior-rollup.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification of rollup**

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-schema.php';
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-tracker.php';
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-rollup.php';
FSE_BehaviorSchema::install();
\$t = new FSE_BehaviorTracker();
\$t->insert_event(['event_type'=>'impression','keyword'=>'rollup test','product_id'=>555,'visitor_id'=>'rollup-visitor']);
\$t->insert_event(['event_type'=>'click','keyword'=>'rollup test','product_id'=>555,'visitor_id'=>'rollup-visitor']);
\$r = new FSE_BehaviorRollup();
\$r->run_rollup();
echo 'done';
"
wp db query "SELECT * FROM {prefix}fse_search_stats WHERE keyword='rollup test';"
```
Expected: one row, `keyword='rollup test'`, `product_id=555`, `impressions=1`, `clicks=1`.

- [ ] **Step 4: Verify re-running doesn't double-count**

```bash
wp eval "
require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-rollup.php';
(new FSE_BehaviorRollup())->run_rollup();
echo 'done';
"
wp db query "SELECT impressions, clicks FROM {prefix}fse_search_stats WHERE keyword='rollup test';"
```
Expected: still `impressions=1`, `clicks=1` — unchanged, confirming the watermark prevented reprocessing.

- [ ] **Step 5: Manual verification of purge**

```bash
wp db query "INSERT INTO {prefix}fse_search_events (event_type, keyword, product_id, visitor_id, created_at) VALUES ('impression', 'old event', 1, 'purge-test', DATE_SUB(NOW(), INTERVAL 100 DAY));"
wp eval "require_once 'wp-content/plugins/fibosearch-enhancer/includes/class-behavior-rollup.php'; (new FSE_BehaviorRollup())->run_purge(); echo 'done';"
wp db query "SELECT COUNT(*) FROM {prefix}fse_search_events WHERE keyword='old event';"
```
Expected: `0` — the 100-day-old row was deleted (retention is 90 days).

- [ ] **Step 6: Commit**

```bash
git add includes/class-behavior-rollup.php
git commit -m "Add FSE_BehaviorRollup: hourly stats aggregation and daily purge"
```

---

### Task 8: Admin setting toggle

**Files:**
- Modify: `admin/class-admin.php`
- Modify: `admin/views/page-settings.php`

**Interfaces:**
- Consumes: existing `FSE_Admin::sanitize_settings()` allow-list pattern.
- Produces: `fse_settings['behavior_tracking_enabled']`, consumed by Tasks 2 and 3 (already written to read it via `fse_get_option`).

- [ ] **Step 1: Find the existing toggle allow-list in `admin/class-admin.php`**

Run: `grep -n "fuzzy_enabled" admin/class-admin.php`

This locates the sanitize_settings() array of boolean toggle keys — add `'behavior_tracking_enabled'` to that same list (same pattern used for every other feature toggle, e.g. `'fuzzy_enabled'`, `'synonyms_enabled'`).

- [ ] **Step 2: Find the existing toggle row markup in `admin/views/page-settings.php`**

Run: `grep -n "fuzzy_enabled" admin/views/page-settings.php`

Copy the checkbox row markup for an existing toggle (e.g. the fuzzy-search enable row) and adapt it: same HTML structure, `name="fse_settings[behavior_tracking_enabled]"`, checked when `fse_get_option('behavior_tracking_enabled', '1') === '1'`, label text "Enable search behavior tracking" and a short description: "Collects search impressions, clicks, cart-adds, and purchases to power future ranking improvements. Required for any of the upcoming ranking/analytics phases."

- [ ] **Step 3: Syntax check both files**

Run: `php -l admin/class-admin.php && php -l admin/views/page-settings.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual verification**

1. Load the plugin's settings page in wp-admin.
2. Confirm a new "Enable search behavior tracking" checkbox appears, checked by default.
3. Uncheck it, save.
4. Run: `wp option get fse_settings --format=json | php -r '$d=json_decode(file_get_contents("php://stdin"),true); var_dump($d["behavior_tracking_enabled"]);'`
   Expected: `string(1) "0"`.
5. Search the site; confirm no `fse_track_event` beacon requests fire (tracking is now off) and no new rows land in `fse_search_events`.
6. Re-check the box, save, confirm tracking resumes.

- [ ] **Step 5: Commit**

```bash
git add admin/class-admin.php admin/views/page-settings.php
git commit -m "Add behavior tracking enable/disable setting"
```

---

### Task 9: Wire everything into the plugin bootstrap

**Files:**
- Modify: `fibosearch-enhancer.php`

**Interfaces:**
- Consumes: `FSE_BehaviorSchema`, `FSE_BehaviorTracker`, `FSE_BehaviorRollup` (Tasks 1, 2, 7).
- Produces: fully working feature on plugin load/activation. Terminal task — nothing later depends on this.

- [ ] **Step 1: Require the new files and instantiate the classes**

In `fibosearch-enhancer.php`'s `fse_init()`, alongside the existing `require_once` calls, add:

```php
    require_once FSE_DIR . 'includes/class-behavior-schema.php';
    require_once FSE_DIR . 'includes/class-behavior-tracker.php';
    require_once FSE_DIR . 'includes/class-behavior-rollup.php';
```

and alongside the existing `new FSE_*()` instantiations, add:

```php
    FSE_BehaviorSchema::maybe_upgrade();
    new FSE_BehaviorTracker();
    new FSE_BehaviorRollup();
```

- [ ] **Step 2: Register activation/deactivation hooks for cron scheduling**

At the top level of `fibosearch-enhancer.php` (outside `fse_init()`, alongside any other top-level `add_action` calls — this plugin currently only registers `plugins_loaded`, so add these as new top-level statements):

```php
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
```

- [ ] **Step 3: Syntax check**

Run: `php -l fibosearch-enhancer.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Full end-to-end manual verification**

1. Deactivate and reactivate the plugin in wp-admin (exercises the activation hook fresh).
2. Run: `wp db query "DESCRIBE {prefix}fse_search_events;"` — confirm the table exists.
3. Run: `wp cron event list | grep fse_behavior` — confirm both `fse_behavior_rollup` and `fse_behavior_purge` are scheduled.
4. Perform a real search + click + add-to-cart on the live site (through the browser, as in Task 3 Step 4).
5. Run: `wp cron event run fse_behavior_rollup` — confirm it completes without error.
6. Run: `wp db query "SELECT * FROM {prefix}fse_search_stats ORDER BY last_event_at DESC LIMIT 5;"` — confirm the click/cart-add you just performed shows up aggregated.
7. Deactivate the plugin. Run: `wp cron event list | grep fse_behavior` — confirm both events are gone (deactivation hook cleaned them up).
8. Reactivate before continuing any further work.

- [ ] **Step 5: Commit**

```bash
git add fibosearch-enhancer.php
git commit -m "Wire search behavior tracking into plugin bootstrap and activation lifecycle"
```
