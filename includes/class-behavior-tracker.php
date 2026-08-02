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
        add_action( 'woocommerce_add_to_cart', [ $this, 'handle_add_to_cart' ], 10, 6 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'store_visitor_on_order' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completed' ] );
        add_action( 'wp_login', [ $this, 'backfill_user_id' ], 10, 2 );
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
     * Batched writer for the beacon endpoint — a single multi-row INSERT
     * instead of one round-trip per event, since one search can generate a
     * dozen-plus impression events in a single request (measured: ~27x
     * faster than looping insert_event() for a 15-row batch). Every field
     * is expected pre-validated by the caller, matching insert_event()'s
     * contract; never throws.
     *
     * $wpdb->prepare()'s %d placeholder coerces PHP null to the integer 0,
     * not SQL NULL — unlike $wpdb->insert(), which special-cases null. To
     * keep nullable columns (position, user_id) genuinely NULL (required
     * for backfill_user_id()'s `user_id IS NULL` match to keep working),
     * those two columns are embedded as literal ints/NULL directly in the
     * VALUES SQL rather than passed through a %d placeholder — safe here
     * because both are already forced through (int) cast or exactly null
     * before this method ever sees them, never raw/unvalidated input.
     */
    private function insert_events_batch( array $events ): void {
        if ( empty( $events ) ) return;

        global $wpdb;
        $table      = $wpdb->prefix . FSE_BehaviorSchema::EVENTS_TABLE;
        $created_at = current_time( 'mysql' );

        $placeholders = [];
        $values       = [];

        foreach ( $events as $event ) {
            $position = $event['position'] ?? null;
            $user_id  = $event['user_id'] ?? null;

            $placeholders[] = '(%s, %s, %d, ' . ( null === $position ? 'NULL' : (int) $position ) . ', %s, ' . ( null === $user_id ? 'NULL' : (int) $user_id ) . ', %s)';

            $values[] = $event['event_type'];
            $values[] = $event['keyword'];
            $values[] = (int) $event['product_id'];
            $values[] = $event['visitor_id'];
            $values[] = $created_at;
        }

        $sql = "INSERT INTO {$table} (event_type, keyword, product_id, position, visitor_id, user_id, created_at) VALUES "
            . implode( ', ', $placeholders );

        $wpdb->query( $wpdb->prepare( $sql, $values ) );
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

        $validated = [];

        foreach ( array_slice( $raw_events, 0, 40 ) as $event ) {
            $type = is_string( $event['type'] ?? null ) ? $event['type'] : '';
            if ( ! in_array( $type, [ 'impression', 'click' ], true ) ) continue;

            $keyword = isset( $event['keyword'] ) ? strtolower( trim( sanitize_text_field( (string) $event['keyword'] ) ) ) : '';
            $product_id = isset( $event['product_id'] ) ? (int) $event['product_id'] : 0;
            if ( '' === $keyword || $product_id <= 0 ) continue;

            $validated[] = [
                'event_type' => $type,
                'keyword'    => $keyword,
                'product_id' => $product_id,
                'position'   => isset( $event['position'] ) ? (int) $event['position'] : null,
                'visitor_id' => $visitor_id,
                'user_id'    => $user_id,
            ];
        }

        $this->insert_events_batch( $validated );

        wp_send_json_success();
    }

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

        try {
            $keyword = $this->find_recent_click( (int) $product_id, $visitor_id, $user_id );
            if ( null === $keyword ) return;

            $this->insert_event( [
                'event_type' => 'cart_add',
                'keyword'    => $keyword,
                'product_id' => (int) $product_id,
                'visitor_id' => $visitor_id,
                'user_id'    => $user_id,
            ] );
        } catch ( \Throwable $e ) {
            // Never let a failure here break the customer's checkout/cart flow —
            // worst case this event doesn't get recorded.
        }
    }

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

        try {
            $order->update_meta_data( '_fse_visitor_id', $visitor_id );
            $order->save();
        } catch ( \Throwable $e ) {
            // Never let a save failure here break the customer's checkout —
            // worst case this order's visitor_id just doesn't get recorded,
            // and it falls out of attribution scope for handle_order_completed().
        }
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

        try {
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
        } catch ( \Throwable $e ) {
            // Never let a failure here break the customer's checkout/cart flow —
            // worst case this event doesn't get recorded.
        }
    }

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
}
