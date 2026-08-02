# Dynamic Priority Mapping (ML for Search Phase 2, applied to FSE_PriorityMapping)

## Goal

`FSE_PriorityMapping` currently re-ranks search results for a keyword using a
fixed, admin-curated `product_id` (e.g. `'sunsilk' → product_id 73430`,
hardcoded or set once in `DEFAULT_MAPPING`/the admin option). That mapping
never adapts: if the pinned product goes out of stock, gets discontinued, or
a different product starts converting better for that keyword, nothing
changes until someone manually edits the config.

This adds a data-driven resolution step, using the `fse_search_stats`
aggregate table built in the search-behavior-tracking work
(`docs/superpowers/specs/2026-07-18-search-behavior-tracking-design.md`),
that picks the tier-1 product from real click/purchase performance instead
of a fixed admin value — for *any* searched keyword, not only ones an admin
has pre-configured — while falling back to today's admin-configured table
when no qualifying dynamic candidate exists.

This is Phase 2 of the "ML for Search" initiative referenced in the earlier
design doc, applied specifically to this module rather than as a new
system: *"Business rules should always take precedence over ML predictions
where configured"* — the category scaffolding (tiers 2-8) still follows
real product-taxonomy structure exactly as before; only the *source* of a
tier-1 pick and its scaffolding becomes data-driven when no admin rule
exists.

Out of scope: modifying the admin-configured table's format or UI, changing
`build_tiers()`'s tier scoring/limits, any change to `FSE_CompetitorFallback`
or `FSE_DiscountIntentFallback` (separate modules), personalization.

## Architecture

### Modified file

**`includes/class-priority-mapping.php`** — `FSE_PriorityMapping`

`match_keyword()` gets one new step inserted before the existing lookup:

```php
public function match_keyword( $keyword ) {
    $this->matched_entry   = null;
    $this->tier_by_product = [];

    if ( empty( $keyword ) ) return $keyword;

    $entry = $this->resolve_dynamic_entry( (string) $keyword )
        ?? $this->find_matching_entry( (string) $keyword );

    if ( null === $entry ) return $keyword;

    $this->matched_entry   = $entry;
    $this->tier_by_product = $this->build_tiers( $entry );

    return $keyword;
}
```

`build_tiers()`, `products_in_category()`, `apply_tier_score()`, and
`inject_tier_products()` are **completely unchanged** — `resolve_dynamic_entry()`
only needs to produce an array shaped identically to an admin-configured
entry (`keyword`, `product_id`, `main_subcategory`, `relevant_subcategories`,
`parent_category`) for the rest of the pipeline to work unmodified.

### `resolve_dynamic_entry( string $keyword ): ?array`

**1. Candidate lookup.** One query against `fse_search_stats`:

```sql
SELECT product_id, purchases, clicks
FROM {$wpdb->prefix}fse_search_stats
WHERE keyword = %s
ORDER BY purchases DESC, clicks DESC
LIMIT 10
```

Purchases rank above clicks — a keyword that converts on a product is a
stronger signal than one that merely gets clicked. `keyword` here is the
normalized (lowercased, trimmed) form, matching how `fse_search_stats` rows
are written by `FSE_BehaviorTracker`. Empty result set → return `null`
immediately (no wasted second query).

**2. Relevance filter.** One query against `wp_posts` for the ≤10 candidate
IDs:

```sql
SELECT ID FROM {$wpdb->posts}
WHERE ID IN (...)
  AND (post_title LIKE %s OR post_excerpt LIKE %s OR post_content LIKE %s)
```

(all three `LIKE` params = `%{$keyword}%`, escaped via `$wpdb->esc_like()`).
Results are re-sorted in PHP to match the step-1 candidate ranking (SQL's
`IN()` doesn't preserve order); the first ID in that order is the winner.

This is the gate that stops click/purchase noise on an unrelated product
from ever winning — the product has to genuinely be *about* the keyword,
not just have accumulated some clicks on it. It's also what deliberately
excludes the competitor-brand mapping pattern (`'sunsilk' → our shampoo`)
from ever being picked dynamically: a product literally never mentions a
competitor's brand name, so that pattern only ever resolves through the
admin-configured table, exactly as intended.

**3. No candidate passes the relevance filter** → return `null`. Caller
falls through to `find_matching_entry()` (today's admin-table lookup),
unchanged.

**4. Category derivation**, from the winning product's own real
`product_cat` terms:
- `main_subcategory` = the product's first assigned `product_cat` term slug
  (`get_the_terms( $product_id, 'product_cat' )[0]`). WooCommerce has no
  built-in "primary category" concept without a separate plugin (e.g.
  Yoast's primary-category field), so "first" here means whatever order
  `get_the_terms()` returns — not guaranteed deterministic across products,
  but acceptable as a heuristic: most products in this catalog carry one
  specific category, and a multi-category product's other assignments still
  surface via `relevant_subcategories`.
- `parent_category` = that term's parent term's slug (via `get_term()`'s
  `parent` field → `get_term( $parent_id )->slug`), or `''` if the main
  category has no parent (flat top-level category — matches how the
  existing `baby-care` seed entry already leaves this blank).
- `relevant_subcategories` = up to 4 sibling term slugs under the same
  parent (`get_terms(['taxonomy' => 'product_cat', 'parent' => $parent_id,
  'exclude' => [$main_term_id]])`), or `[]` if there's no parent (same flat-
  category case).

If the winning product has no `product_cat` terms at all (uncategorized),
`main_subcategory` is `''` and `build_tiers()` naturally skips tiers 2-3
exactly as it already does today when an admin entry's `main_subcategory`
is empty — no special-casing needed.

### Data flow example

Shopper searches "sunsilk" (a competitor brand, never mentioned on this
store's own products). `fse_search_stats` might show some product has
accumulated purchases against that keyword from past searches, but its
title/description never contain "sunsilk" literally — step 2's relevance
filter rejects it. `resolve_dynamic_entry()` returns `null`. Control falls
through to `find_matching_entry()`, which still finds the admin-configured
`'sunsilk' → product_id 73430` entry exactly as today.

Shopper searches "sunscreen" (a literal, on-catalog term). If a specific
sunscreen product has accumulated strong purchase performance for that
exact keyword, it passes the relevance filter (title contains "sunscreen"),
and becomes the dynamic tier-1 pick — with `main_subcategory`/
`parent_category`/`relevant_subcategories` derived fresh from that
product's own real category assignments, with no admin configuration
required for "sunscreen" to have ever existed as a mapped keyword.

### Error handling

Matches the module's existing philosophy (see the file's own header
comment): any failure path — no `fse_search_stats` rows for the keyword,
a candidate product that's been deleted/unpublished since the stats were
recorded, a product with no `product_cat` terms — results in
`resolve_dynamic_entry()` returning `null` and falling through to the
existing, unchanged admin-table path. No exception can propagate out of
this method; a malformed row or missing data degrades to "dynamic picking
didn't find anything this time," never a fatal.

### Testing plan

Manual verification (no automated test suite in this plugin, matching
established pattern):

1. Seed `fse_search_stats` with a row for a real product whose title
   genuinely contains a test keyword, with enough purchases to rank first;
   confirm `resolve_dynamic_entry()` returns that product with correctly
   derived `main_subcategory`/`parent_category`/`relevant_subcategories`
   matching its actual live category assignments.
2. Seed a row for a keyword where the top-purchasing product's title/
   excerpt/content does NOT contain that keyword; confirm it's rejected and
   the method returns `null`.
3. With an admin-configured entry present for a keyword AND no qualifying
   dynamic candidate for it, confirm the search still resolves via
   `find_matching_entry()` exactly as before this change (regression check
   against the module's existing behavior).
4. With BOTH a qualifying dynamic candidate and an admin-configured entry
   for the same keyword, confirm the dynamic candidate wins (per the
   "layer — dynamic first" design decision).
5. Search a keyword with zero `fse_search_stats` rows at all; confirm no
   extra queries beyond the single empty-result candidate lookup, and that
   the module falls through cleanly to the admin table / no match, exactly
   as it does today for any unmapped keyword.
6. Confirm a product with no `product_cat` terms, if it wins dynamically,
   doesn't break tier building (tiers 2-3 simply don't populate, same as
   an admin entry with an empty `main_subcategory` today).
