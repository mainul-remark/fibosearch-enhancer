# Popular Searches Pre-Suggestions Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a visitor focuses the FiboSearch input with nothing typed, show a "Popular searches" pill-chip row (top terms from FiboSearch's own analytics table) above the existing recent-searches history list.

**Architecture:** A new `FSE_PopularSearches` PHP class queries `dgwt_wcas_stats`, caches results in a 1-hour transient, and inlines the term array into the page via `wp_localize_script`. A new JS file listens for FiboSearch's `fibosearch/show-pre-suggestions` DOM event and a `focus` fallback, then prepends a pill-chip block into FiboSearch's existing suggestions container. FiboSearch's own recent-searches list is enabled via a one-line PHP filter.

**Tech Stack:** PHP 7.4+, jQuery (already loaded by FiboSearch), vanilla CSS custom properties.

## Global Constraints

- Never overwrite or monkey-patch FiboSearch's JS or its suggestions container content — only prepend.
- Fail silently: no PHP notices, no JS errors if `fsePopular` is undefined or the analytics table is absent.
- No AJAX on focus — popular terms are inlined at page load.
- `popular_searches_enabled` default `'1'` (on).
- Chips are `<a href="#">` tags — accessible by default, no extra ARIA needed.
- Brand hover colour via CSS custom property `--fse-brand` defaulting to `#2563eb`.
- Minimum 3 terms required before anything is inlined or rendered.

---

### Task 1: PHP class, bootstrap, and admin setting

**Files:**
- Create: `includes/class-popular-searches.php`
- Modify: `fibosearch-enhancer.php`
- Modify: `admin/class-admin.php`
- Modify: `admin/views/page-settings.php`

**Interfaces:**
- Produces: `FSE_PopularSearches::get_terms(): string[]` (used by `inline_terms()` internally; exposed for manual testing)
- Produces: `window.fsePopular = { terms: string[] }` inlined on every front-end page when enabled and ≥3 terms exist
- Produces: filter `dgwt/wcas/scripts/show_recently_searched_phrases` forced to `true`
- Produces: setting key `popular_searches_enabled` readable via `fse_get_option('popular_searches_enabled', '1')`

- [ ] **Step 1: Create `includes/class-popular-searches.php`**

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_PopularSearches {

    const TRANSIENT_KEY = 'fse_popular_searches';
    const TRANSIENT_TTL = HOUR_IN_SECONDS;
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

        $terms = $this->query_terms();
        set_transient( self::TRANSIENT_KEY, $terms, self::TRANSIENT_TTL );
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
```

- [ ] **Step 2: Require, enqueue, and instantiate in `fibosearch-enhancer.php`**

Add the require alongside the other includes, the enqueue inside `fse_init()` alongside the `fse_enqueue_behavior_tracker` action, and the instantiation at the end of the instantiation block. Three edits:

2a. Add require after `class-behavior-rollup.php` (around line 63):

```php
    require_once FSE_DIR . 'includes/class-popular-searches.php';
```

2b. Inside `fse_init()`, after the `add_action( 'wp_enqueue_scripts', 'fse_enqueue_behavior_tracker' )` line (around line 91), add:

```php
    add_action( 'wp_enqueue_scripts', 'fse_enqueue_popular_searches' );
```

2c. After the closing brace of `fse_init()`, add the enqueue function and the instantiation. Replace this existing function at the bottom of the file:

```php
function fse_enqueue_behavior_tracker() {
```

…by inserting the new function **before** it:

```php
function fse_enqueue_popular_searches() {
    if ( fse_get_option( 'popular_searches_enabled', '1' ) !== '1' ) return;

    wp_enqueue_style(
        'fse-pre-suggestions',
        FSE_URL . 'assets/css/search-pre-suggestions.css',
        [],
        FSE_VERSION
    );

    wp_enqueue_script(
        'fse-pre-suggestions',
        FSE_URL . 'assets/js/search-pre-suggestions.js',
        [ 'jquery' ],
        FSE_VERSION,
        true
    );
}
```

2d. Inside `fse_init()`, after `new FSE_AIQueryEnhancer();` (around line 84), add:

```php
    new FSE_PopularSearches();
```

- [ ] **Step 3: Add `popular_searches_enabled` to `sanitize_settings()` in `admin/class-admin.php`**

In the `$toggles` array inside `sanitize_settings()` (around line 63–88), add one entry:

```php
            'popular_searches_enabled',
```

Place it alphabetically or at the end of the list — either works. The full `$toggles` array should now include `'popular_searches_enabled'` alongside the others.

- [ ] **Step 4: Add the toggle row to `admin/views/page-settings.php`**

Inside the Features tab `<table class="form-table fse-table">`, add a new `<tr>` block. Place it after the "Variation SKU Search" row (the first row, around line 33–40) since this is a front-end UX feature:

```php
                <tr>
                    <th scope="row">Popular Searches Panel</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[popular_searches_enabled]" value="1" <?php checked( fse_opt( 'popular_searches_enabled' ) ); ?>>
                            Show <strong>popular search suggestions</strong> when the search box is focused
                        </label>
                        <p class="description">Displays the top searched keywords (from FiboSearch Analytics) as clickable chips above the recent-searches list. Requires FiboSearch Analytics to be enabled. Chips update hourly.</p>
                    </td>
                </tr>
```

- [ ] **Step 5: Verify wiring manually**

Open a front-end page (e.g. the shop or home page) in the browser. Open DevTools → Sources or page source. Search for `fsePopular`. If FiboSearch Analytics is enabled and has ≥3 searches with results in the last 30 days, you should see:

```js
var fsePopular = {"terms":["term1","term2","term3",...]}
```

If Analytics has no data yet, `fsePopular` won't appear — that's correct (graceful degradation).

Open the admin panel → WooCommerce → Search Enhancer → Features tab. Confirm the "Popular Searches Panel" checkbox is present and saves correctly.

- [ ] **Step 6: Commit**

```bash
git add includes/class-popular-searches.php fibosearch-enhancer.php admin/class-admin.php admin/views/page-settings.php
git commit -m "Add FSE_PopularSearches: inline popular terms and enable history list"
```

---

### Task 2: Pill chip CSS

**Files:**
- Create: `assets/css/search-pre-suggestions.css`

**Interfaces:**
- Produces: styles for `.fse-popular-searches`, `.fse-popular-searches__label`, `.fse-popular-searches__chips`, `.fse-popular-chips__item`, `.fse-popular-chips__item:hover`

- [ ] **Step 1: Create `assets/css/search-pre-suggestions.css`**

```css
:root {
    --fse-brand: #2563eb;
}

.fse-popular-searches {
    padding: 10px 12px;
    border-bottom: 1px solid #e5e7eb;
}

.fse-popular-searches__label {
    font-size: 11px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
    font-weight: 600;
}

.fse-popular-searches__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.fse-popular-chips__item {
    display: inline-block;
    border-radius: 20px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    padding: 5px 12px;
    font-size: 13px;
    color: #374151;
    min-width: 60px;
    text-align: center;
    text-decoration: none;
    line-height: 1.4;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
    white-space: nowrap;
}

.fse-popular-chips__item:hover,
.fse-popular-chips__item:focus {
    background: var(--fse-brand);
    color: #fff;
    border-color: var(--fse-brand);
    text-decoration: none;
    outline: none;
}
```

- [ ] **Step 2: Visual check**

Load the front-end page. Open DevTools → Network → filter by "search-pre-suggestions.css" — confirm the file loads with HTTP 200. Inspect the `<head>` to confirm the stylesheet link is present.

- [ ] **Step 3: Commit**

```bash
git add assets/css/search-pre-suggestions.css
git commit -m "Add pill chip CSS for popular searches panel"
```

---

### Task 3: JS — event wiring, DOM injection, chip interaction

**Files:**
- Create: `assets/js/search-pre-suggestions.js`

**Interfaces:**
- Consumes: `window.fsePopular.terms` (string array, inlined by Task 1)
- Consumes: DOM events `fibosearch/show-pre-suggestions` and `fibosearch/close` (dispatched by FiboSearch's `search.js`)
- Consumes: jQuery `focus` on `.dgwt-wcas-search-input`
- Consumes: jQuery `ajaxSuccess` for requests containing `dgwt_wcas_ajax_search`
- Produces: `.fse-popular-searches` block prepended to `.dgwt-wcas-suggestions-wrapp` on focus
- Produces: chip click fills `.dgwt-wcas-search-input` and triggers FiboSearch search

- [ ] **Step 1: Create `assets/js/search-pre-suggestions.js`**

```js
( function ( $ ) {
    'use strict';

    if ( typeof fsePopular === 'undefined' || ! Array.isArray( fsePopular.terms ) || fsePopular.terms.length < 3 ) {
        return;
    }

    var terms            = fsePopular.terms;
    var BLOCK_CLASS      = 'fse-popular-searches';
    var INJECTED_SEL     = '.' + BLOCK_CLASS;
    var CONTAINER_SEL    = '.dgwt-wcas-suggestions-wrapp';

    function escapeHtml( str ) {
        return $( '<div>' ).text( str ).html();
    }

    function buildHtml() {
        var html = '<div class="' + BLOCK_CLASS + '">';
        html += '<div class="fse-popular-searches__label">&#128293; Popular searches</div>';
        html += '<div class="fse-popular-searches__chips">';
        terms.forEach( function ( term ) {
            var safe = escapeHtml( term );
            html += '<a href="#" class="fse-popular-chips__item" data-term="' + safe + '">' + safe + '</a>';
        } );
        html += '</div></div>';
        return html;
    }

    function inject( $container ) {
        // Idempotent: only inject once per open.
        if ( $container.find( INJECTED_SEL ).length ) return;
        $container.prepend( buildHtml() );
        $container.show();
        $( 'body' ).addClass( 'dgwt-wcas-open' );
    }

    function remove() {
        $( INJECTED_SEL ).remove();
    }

    // Normal path: visitor has search history → FiboSearch fires this event.
    document.addEventListener( 'fibosearch/show-pre-suggestions', function () {
        var $container = $( CONTAINER_SEL );
        if ( $container.length ) {
            inject( $container );
        }
    } );

    // First-visit path: no history → FiboSearch never fires show-pre-suggestions.
    $( document ).on( 'focus', '.dgwt-wcas-search-input', function () {
        if ( $( this ).val().length > 0 ) return;
        if ( $( 'body' ).hasClass( 'dgwt-wcas-open' ) ) return; // already open (history showing)

        var $container = $( CONTAINER_SEL );
        if ( $container.length ) {
            inject( $container );
        }
    } );

    // Cleanup: FiboSearch dropdown closed.
    document.addEventListener( 'fibosearch/close', function () {
        remove();
    } );

    // Cleanup: real AJAX search results are loading — remove chips so they
    // don't flash under the real results.
    $( document ).ajaxSuccess( function ( event, xhr, settings ) {
        if ( settings.url && settings.url.indexOf( 'dgwt_wcas_ajax_search' ) !== -1 ) {
            remove();
        }
    } );

    // Chip click: fill input and trigger FiboSearch's own search.
    $( document ).on( 'click', '.fse-popular-chips__item', function ( e ) {
        e.preventDefault();
        var term   = $( this ).data( 'term' );
        var $input = $( '.dgwt-wcas-search-input' ).first();
        $input.val( term ).trigger( 'input' );
    } );

} )( jQuery );
```

- [ ] **Step 2: Full manual test — first-visit path (no history)**

Open the shop page in a private/incognito window (no localStorage history). Make sure FiboSearch Analytics has ≥3 phrases with results.

1. Click the search box. Expect: pill chip row appears with "🔥 Popular searches" label and keyword chips.
2. Click a chip. Expect: the search input fills with that keyword and FiboSearch shows product results immediately.
3. Press Escape or click outside. Expect: the dropdown closes, chips disappear.
4. Click the search box again. Expect: chips reappear.
5. Type a character. Expect: chips disappear and FiboSearch shows real autocomplete suggestions.

- [ ] **Step 3: Full manual test — returning visitor path (has history)**

In a normal browser window, search for 2–3 terms so FiboSearch stores them in localStorage (check DevTools → Application → Local Storage for `dgwt_wcas_recently_searched_phrases`).

1. Click the search box. Expect: pill chip row appears at the top, FiboSearch's "Recent searches" list appears below it with the clock-icon history items.
2. Click a chip. Expect: input fills with keyword, real results appear, chips are gone.
3. The recent-searches list still works normally (clicking a history item also searches).

- [ ] **Step 4: Graceful-degradation test**

Temporarily disable the feature from admin (WooCommerce → Search Enhancer → Features → uncheck "Popular Searches Panel" → Save). Reload the front end. Click the search box. Expect: no chip row (chips disabled), and FiboSearch's existing behaviour is completely unchanged.

Re-enable it and save.

- [ ] **Step 5: Commit**

```bash
git add assets/js/search-pre-suggestions.js
git commit -m "Add popular searches pre-suggestions: pill chips on search focus"
```
