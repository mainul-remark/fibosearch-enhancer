# Search Behavior Tracking (ML for Search — Phase 1)

## Goal

Add the data foundation for machine-learned search ranking to the existing
`fibosearch-enhancer` plugin: capture what shoppers search for, what they see,
what they click, what they add to cart, and what they buy, tied together well
enough to compute per-keyword, per-product engagement and conversion.

This is Phase 1 of a larger initiative (see the customer-provided "Machine
Learning for Search" requirements). It is deliberately scoped to **tracking
and storage only** — no ranking changes, no admin dashboard, no
auto-suggestion changes, no personalization. Those are later phases that
depend on this data existing first:

- Phase 2: feed the aggregate stats into a new `FSE_BehaviorScore` module,
  adjusting ranking *within* each `FSE_PriorityMapping` tier (business rules
  still take precedence, per the customer's spec).
- Phase 3: admin analytics dashboard reading the same aggregate table.
- Phase 4: auto-suggestion / predictive-prefix ranking using tracked
  popularity.
- Phase 5 (explicitly "future phase" in the customer's own spec):
  personalization for logged-in users.

"ML" in this context means statistical learning from aggregated
click-through/conversion rates, not a trained model — the store's catalog and
traffic volume don't support a real ML model, and simple counters deliver
everything the requirements ask for (automatic, no manual per-keyword tuning)
without the operational cost of a model pipeline.

Out of scope for Phase 1: any change to search results or ranking, any
admin-visible UI, personalization, predictive prefix search.

## Architecture

### New files

**`includes/class-behavior-schema.php`** — `FSE_BehaviorSchema`

Creates and version-upgrades the two DB tables via `dbDelta()`. Runs on
plugin activation (`register_activation_hook`) and is also checked on
`plugins_loaded` against a `fse_behavior_schema_version` option, so an
in-place plugin update that changes the schema upgrades existing installs
without requiring deactivate/reactivate.

**`includes/class-behavior-tracker.php`** — `FSE_BehaviorTracker`

Owns visitor identity, the event-write AJAX endpoint, WooCommerce
cart/purchase hooks, and attribution matching. This is the only class that
writes to `fse_search_events`.

**`includes/class-behavior-rollup.php`** — `FSE_BehaviorRollup`

Owns the two WP-Cron jobs: hourly aggregation into `fse_search_stats`, and
daily purge of raw events older than the retention window.

**`assets/js/behavior-tracker.js`**

Front-end: listens for FiboSearch's AJAX response to capture impressions,
captures clicks on suggestions, sends both via `navigator.sendBeacon()`.

### Schema

**`{$wpdb->prefix}fse_search_events`** — raw, one row per event

| column | type | notes |
|---|---|---|
| `id` | `bigint unsigned` | PK, auto_increment |
| `event_type` | `varchar(20)` | `impression`, `click`, `cart_add`, `purchase` |
| `keyword` | `varchar(191)` | normalized: `strtolower(trim($keyword))` |
| `product_id` | `bigint unsigned` | |
| `position` | `smallint unsigned` | nullable; rank position in results (impressions/clicks only) |
| `visitor_id` | `varchar(36)` | UUID from the `fse_vid` cookie |
| `user_id` | `bigint unsigned` | nullable; backfilled on login |
| `order_id` | `bigint unsigned` | nullable; purchase events only |
| `created_at` | `datetime` | |

Indexes: `(keyword, event_type)`, `(product_id)`, `(visitor_id)`,
`(created_at)` — the last one exists specifically to make the daily purge
query efficient.

**`{$wpdb->prefix}fse_search_stats`** — aggregate, what ranking will read

| column | type | notes |
|---|---|---|
| `keyword` | `varchar(191)` | |
| `product_id` | `bigint unsigned` | |
| `impressions` | `bigint unsigned` | default 0 |
| `clicks` | `bigint unsigned` | default 0 |
| `cart_adds` | `bigint unsigned` | default 0 |
| `purchases` | `bigint unsigned` | default 0 |
| `last_event_at` | `datetime` | |

Primary key `(keyword, product_id)`. Sized so a future ranking hook can do a
single indexed lookup per keyword+product during a live search request
without reintroducing the latency problem the AI module had.

### Visitor identity

- On any frontend request without an `fse_vid` cookie, `FSE_BehaviorTracker`
  sets one: `wp_generate_uuid4()`, 1-year expiry, `httponly` (server-only;
  no JS needs to read it — the beacon endpoint reads `$_COOKIE` directly), `SameSite=Lax`.
- On `wp_login`, backfill: `UPDATE fse_search_events SET user_id = %d WHERE
  visitor_id = %s AND user_id IS NULL` for the logging-in user's cookie, so
  guest browsing history carries into their identified history once they
  sign in.
- **Attribution window: 30 days, last-click model.** A `cart_add` or
  `purchase` is attributed to the most recent `click` event for that
  product by that visitor (matched by `visitor_id`, or by `user_id` if
  logged in) within the last 30 days. Matches the industry-standard GA
  attribution default; no multi-touch logic in Phase 1.

### Event capture points

**1. Impressions & clicks (client-side, `behavior-tracker.js`)**

Rather than scraping FiboSearch's rendered DOM or patching its internals,
hook `jQuery(document).ajaxSuccess()` filtered to the
`dgwt_wcas_ajax_search` endpoint and read the JSON response directly (same
shape confirmed via manual testing: `{suggestions: [...], total, time}`).
For each product suggestion, queue an `impression` event with its position
in the list. On a delegated click handler for suggestion links, queue a
`click` event with the same keyword/product/position, then send immediately
via `navigator.sendBeacon()` so it can't delay the browser's navigation to
the product page.

Both event types batch into a single `sendBeacon()` call per search
response (impressions) or fire individually (a click, being a single
event). The beacon endpoint is `admin-ajax.php?action=fse_track_event`,
registered `wp_ajax_fse_track_event` + `wp_ajax_nopriv_fse_track_event`.

**2. Cart-add (server-side, `woocommerce_add_to_cart`)**

Read `$_COOKIE['fse_vid']`. Query for the visitor's most recent `click`
event on this `product_id` within the 30-day window. If found, write a
`cart_add` event with that click's keyword. If no matching click exists
(shopper found the product some other way), no event is written — cart-adds
are only tracked when attributable to a search.

**3. Purchase (server-side, two WooCommerce hooks)**

- `woocommerce_checkout_order_processed` (fires within the customer's own
  request, where `$_COOKIE` is reliably available): store the visitor's
  `fse_vid` as order meta (`_fse_visitor_id`). This is the only point in
  the order lifecycle guaranteed to have cookie context — later hooks may
  fire from an admin action, webhook, or cron.
- `woocommerce_order_status_completed` (not `processing` — avoids
  attributing purchases that are later refunded or cancelled before
  completing): read `_fse_visitor_id` from order meta, and for each line
  item, look up the most recent matching `click` within the window and
  write a `purchase` event carrying that keyword and the `order_id`.

### Housekeeping (WP-Cron, registered by `FSE_BehaviorRollup`)

- **`fse_behavior_rollup`, hourly.** Reads raw events with `id` greater than
  a watermark (stored in option `fse_behavior_rollup_watermark`), groups by
  `(keyword, product_id, event_type)`, and applies counts to
  `fse_search_stats` via `INSERT ... ON DUPLICATE KEY UPDATE
  impressions = impressions + %d, ...`. Watermark only advances forward, so
  a re-run after a partial failure can't double-count.
- **`fse_behavior_purge`, daily.** Deletes raw rows where
  `created_at < NOW() - INTERVAL 90 DAY`, batched at 1000 rows per
  `DELETE ... LIMIT 1000` iteration to avoid long table locks. Never
  touches `fse_search_stats` — aggregates are kept indefinitely, per
  requirements.

### Bot / abuse filtering

- Skip logging for common crawler user agents (substring match against a
  short static list: Googlebot, Bingbot, AhrefsBot, SemrushBot, and similar
  — same lightweight approach used by most analytics plugins, not a
  full bot-detection service).
- Rate-limit: cap at 60 tracked events per `visitor_id` per minute (checked
  via a short-lived transient counter) as basic protection against a
  runaway script or malicious beacon flood; excess events are silently
  dropped, not errored.

### Admin settings

One new toggle in the existing `fse_settings` option, following the current
pattern: `behavior_tracking_enabled` (default `'1'`). No other new options —
retention window (90 days) and attribution window (30 days) are class
constants in Phase 1, not admin-configurable, to keep this phase's surface
area small. (Revisit as settings if Phase 3's dashboard needs them
adjustable.)

## Error handling

Tracking must never be able to degrade search or checkout:

- The beacon endpoint runs on a separate async request
  (`sendBeacon`/`ajaxSuccess`), fully decoupled from the
  `dgwt_wcas_ajax_search` request/response cycle it observes.
- Every write path (beacon handler, cart-add hook, purchase hooks) no-ops
  silently on any failure condition — missing cookie, no attribution match,
  a DB write error, tables not yet created. No exceptions escape these
  hooks; nothing here can turn into a fatal error on checkout, which is the
  one absolutely unacceptable failure mode.
- This mirrors the "fail open" philosophy already used by
  `FSE_AIQueryEnhancer` — an optional enhancement layer must be inert on
  failure, never load-bearing.

## Testing plan

Manual verification (no existing automated test suite in this plugin):

1. Activate/update the plugin; confirm both tables exist with the expected
   columns and indexes (`DESCRIBE {prefix}fse_search_events`, `...fse_search_stats`).
2. Perform a search; confirm impression events are logged for each shown
   suggestion, with correct `position` values.
3. Click a suggestion; confirm a `click` event is logged with matching
   keyword/product_id, and confirm navigation to the product page is not
   delayed (sendBeacon must not block).
4. Add that product to cart within the same session; confirm a `cart_add`
   event appears, attributed to the correct keyword.
5. Complete a test order for that product and mark it Completed; confirm a
   `purchase` event appears with the correct keyword and `order_id`.
6. As a guest who searched before, log in; confirm `wp_login` backfills
   `user_id` onto their prior events.
7. Manually back-date some raw event rows, run
   `wp cron event run fse_behavior_purge`; confirm rows older than 90 days
   are deleted and `fse_search_stats` is unaffected.
8. Run `wp cron event run fse_behavior_rollup` twice in a row with no new
   events between runs; confirm `fse_search_stats` counts don't change
   (watermark prevents double-counting).
9. Request the search endpoint with a Googlebot user agent; confirm no
   events are logged.
10. Confirm zero PHP notices/warnings/fatals in the debug log across all of
    the above, and confirm a forced DB error (e.g., temporarily renaming a
    table) doesn't break add-to-cart or checkout.
