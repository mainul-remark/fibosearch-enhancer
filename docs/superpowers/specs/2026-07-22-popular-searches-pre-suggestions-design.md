# Design Spec: Popular Searches Pre-Suggestions Panel

**Date:** 2026-07-22
**Status:** Approved

## Goal

When a visitor focuses the FiboSearch input with nothing typed yet, show a "Popular searches" pill-chip row (server-side frequency data) above FiboSearch's existing "Recent searches" history list. Chips click-to-fill the input and immediately trigger a search. Zero extra network requests on focus — keywords are baked into the page at load time.

---

## Files

### New
| File | Purpose |
|------|---------|
| `includes/class-popular-searches.php` | Data query, transient cache, wp_localize_script, PHP filter hook |
| `assets/js/search-pre-suggestions.js` | DOM injection, chip interaction, event wiring |
| `assets/css/search-pre-suggestions.css` | Pill chip styles |

### Modified
| File | Change |
|------|--------|
| `fibosearch-enhancer.php` | Require + instantiate `FSE_PopularSearches`; enqueue JS + CSS |
| `admin/class-admin.php` | Register `popular_searches_enabled` setting |
| `admin/views/page-settings.php` | Toggle UI in admin panel |

---

## Data Layer — `FSE_PopularSearches`

### Source
FiboSearch's own analytics table `{prefix}dgwt_wcas_stats`, which records every autocomplete search phrase with a hit count. No new tracking needed.

### Query
```sql
SELECT phrase, COUNT(id) AS qty
FROM {prefix}dgwt_wcas_stats
WHERE hits > 0
  AND autocomplete = 1
  AND created_at > NOW() - INTERVAL 30 DAY
GROUP BY phrase
ORDER BY qty DESC
LIMIT 8
```

### Caching
Result stored in a WordPress transient `fse_popular_searches` with a 1-hour TTL. No manual invalidation — popular searches reflect search-frequency data, not product catalogue state, so a 1-hour staleness window is acceptable.

### Inlining
`wp_localize_script` attaches the term array to `fse-pre-suggestions` (the new JS handle):
```js
window.fsePopular = { terms: ['শাড়ি', 'কুর্তি', 'পাঞ্জাবি', ...] };
```

### Graceful degradation
- Table doesn't exist (FiboSearch Analytics never enabled): return empty array, feature silently does nothing.
- Fewer than 3 terms found: don't inline anything; JS skips rendering.
- Setting `popular_searches_enabled` !== `'1'`: class constructor returns early.

### History list enablement
```php
add_filter( 'dgwt/wcas/scripts/show_recently_searched_phrases', '__return_true' );
```
This enables FiboSearch's own recently-searched-phrases history list unconditionally while this feature is active. It is a one-liner with no side effects beyond turning on behaviour that FiboSearch already ships.

---

## JS Behaviour — `search-pre-suggestions.js`

### Normal path (FiboSearch history exists)
1. Listen for the `fibosearch/show-pre-suggestions` DOM event (FiboSearch fires this when it opens the pre-search panel for a visitor who has search history in localStorage).
2. Prepend `<div class="fse-popular-searches">` — the pill chip block — to `.dgwt-wcas-suggestions-wrapp` above the history list.
3. On `fibosearch/close`, remove the injected block.

### First-visit path (FiboSearch history is empty)
FiboSearch's `showPreSuggestions()` returns early when both history arrays are empty, so `fibosearch/show-pre-suggestions` never fires. We handle this by:
1. Listening for `focus` on `.dgwt-wcas-search-input`.
2. If `input.val().length === 0` and `fsePopular.terms.length >= 3`, manually show the suggestions container with our chips-only block.
3. Remove the block on `fibosearch/close` or on the first `input` / `keyup` event that triggers a real search.

### Chip interaction
Each chip is an `<a href="#">` element. On click:
1. Fill `.dgwt-wcas-search-input` with the chip's term.
2. Trigger jQuery's `input` event on the input (FiboSearch listens to `input` to fire the search AJAX).
3. `e.preventDefault()` to suppress navigation.

### Cleanup
Remove injected `.fse-popular-searches` block on:
- `fibosearch/close`
- `ajaxSuccess` for `dgwt_wcas_ajax_search` (real results are now showing)

---

## Visual Structure

Inside FiboSearch's `.dgwt-wcas-suggestions-wrapp`, top to bottom:

```
┌─────────────────────────────────────────┐
│ 🔥 Popular searches                      │
│  [শাড়ি]  [কুর্তি]  [পাঞ্জাবি]  [লেহেঙ্গা]  │  ← pill chips (new)
├─────────────────────────────────────────┤
│ 🕐 Recent searches                       │  ← FiboSearch's existing list
│    শাড়ি                                  │
│    কুর্তি                                 │
└─────────────────────────────────────────┘
```

If the visitor has no search history (first visit), only the pill chip section shows.

---

## CSS — `search-pre-suggestions.css`

```
.fse-popular-searches            wrapper, padding 10px 12px
.fse-popular-searches__label     small muted heading: "🔥 Popular searches"
.fse-popular-searches__chips     flex-wrap row, gap 6px
.fse-popular-chips__item         pill: border-radius 20px, bg #f3f4f6, border 1px #e5e7eb,
                                 padding 5px 12px, font-size 13px, color #374151,
                                 min-width 60px, text-align center
.fse-popular-chips__item:hover   bg brand colour (#...), color white, border-color brand
```

Brand colour value is set via a CSS custom property `--fse-brand` defaulting to `#2563eb` (can be overridden by the theme).

A thin `border-bottom: 1px solid #e5e7eb` separates the chip section from FiboSearch's history list below it.

---

## Settings

One setting added to the FSE admin panel:

| Key | Default | Label |
|-----|---------|-------|
| `popular_searches_enabled` | `'1'` | Enable Popular Searches panel |

No setting for the lookback period or limit (30 days / 8 terms are sensible constants; exposing them adds admin UI complexity for negligible benefit).

---

## Constraints

- **Never breaks FiboSearch's own behaviour.** We only prepend to its container; we never overwrite it or monkey-patch its JS.
- **Fails silently.** No JS errors if `fsePopular` is undefined or empty.
- **No extra HTTP requests on focus.** Keywords are inlined at page load.
- **Accessible.** Chips are `<a>` tags with readable text; no ARIA additions needed beyond the native anchor role.
