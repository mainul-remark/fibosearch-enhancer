# AI Query Enhancement for FiboSearch Enhancer

## Goal

Add an AI-assisted query understanding module to the existing `fibosearch-enhancer`
plugin. On each FiboSearch query, call an AI vendor to correct typos, expand
synonyms, and detect a likely product category — then feed those into
FiboSearch's existing SQL search and score-boost hooks. This is a new module
inside the current plugin, not a new plugin.

Out of scope: offline per-product AI enrichment, a second AI call to re-rank
final results, and attribute/price-intent detection. Category-only intent
boosting for now.

## Architecture

### New files

**`includes/class-ai-client.php`** — `FSE_AI_Client`

Multi-vendor AI caller supporting Gemini, OpenRouter, and Groq. Races all
enabled/configured vendors in parallel using `curl_multi` (same pattern as
`herlan-ai-product-tags`'s `HAIPT_Ajax::fetch_tags_from_all_vendors`), takes
the first vendor to return a valid parseable JSON response, and lets the
others finish or hit the timeout without blocking the caller.

- Hard overall timeout: `ai_timeout_seconds` setting, default `1.5`.
- Never throws. On total failure (no vendors configured, all vendors error,
  or timeout exceeded) returns `null`.
- On success returns an associative array:
  ```php
  [
      'corrected' => string,      // normalized/typo-corrected query
      'synonyms'  => string[],    // related search terms
      'category'  => string|null // AI's best-guess category name
  ]
  ```
- Prompt asks the model to return strict JSON only (no markdown fences, no
  prose). Response parsing: try direct `json_decode`; on failure, regex out
  the first `{...}` block and retry — same fallback approach as
  `HAIPT_Ajax::parse_response`.
- Uses the same cURL hardening as `herlan-ai-product-tags`
  (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_CAINFO` pointed at WP's bundled CA
  bundle, `CURLOPT_TIMEOUT`).

**`includes/class-ai-query-enhancer.php`** — `FSE_AIQueryEnhancer`

Orchestrates caching + the AI call, and hooks into FiboSearch:

- `dgwt/wcas/phrase` (priority `4`, runs before `FSE_SynonymSearch`'s
  priority `5`) — for the incoming keyword:
  1. Skip entirely if `ai_enabled` is off or keyword is empty/too short
     (reuse a minimum length similar to `FSE_FuzzySearch::MIN_LENGTH`).
  2. Check WP transient cache (see Caching below). On hit, use cached result.
  3. On miss, call `FSE_AI_Client`. On success, cache the result. On
     failure/timeout, cache nothing and treat as "no AI data" for this
     request (fail open).
  4. Store the resolved result (or null) in an instance property keyed by
     the raw keyword, for use by the other two hooks in the same request.
  5. Return the keyword **unchanged** — this hook must not mutate
     FiboSearch's own tokenization, matching the existing synonym module's
     approach.

- `dgwt/wcas/native/search_query/search_or` — if AI data exists for the
  current term, OR in extra conditions for the `corrected` term and each
  `synonyms` entry, using the same SQL-building style as
  `FSE_SynonymSearch::add_conditions` (title/content/excerpt LIKE, via
  `$wpdb->prepare`).

- `dgwt/wcas/search_results/product/score` — if AI detected a `category`,
  resolve it against real `product_cat` terms (exact name or slug match,
  case-insensitive). If it matches an actual term, and the product belongs
  to that term, add a fixed boost (`AI_CATEGORY_BOOST = 25`, a class
  constant). If the AI's guess doesn't match any real category, no boost is
  applied — a hallucinated category can't affect ranking.

### Caching

- WP transient, key: `'fse_ai_' . md5( strtolower( trim( $keyword ) ) )`.
- TTL: `ai_cache_ttl_hours` setting (default `24`), stored as hours,
  converted to seconds for `set_transient`.
- Cache stores the full result array (`corrected`/`synonyms`/`category`).
  Failures are not cached, so a transient AI outage self-heals on the next
  search for that term once the vendor recovers.

### Admin settings

Extends the existing single `fse_settings` option and its
`FSE_Admin::sanitize_settings` allow-list — no new WP options.

New keys in `fse_settings`:
- `ai_enabled` (toggle, same pattern as existing feature toggles)
- `ai_gemini_enabled`, `ai_gemini_key`
- `ai_openrouter_enabled`, `ai_openrouter_key`, `ai_openrouter_model`
- `ai_groq_enabled`, `ai_groq_key`, `ai_groq_model`
- `ai_cache_ttl_hours` (int, default 24, clamp e.g. 1–168)
- `ai_timeout_seconds` (float, default 1.5, clamp e.g. 0.5–5)

`admin/views/page-settings.php` gets a new "AI Search" section: an enable
checkbox, then per-vendor enable + password-type key input (+ model text
input for OpenRouter/Groq), then cache TTL and timeout number inputs.
Visually consistent with the existing toggle rows and with
`herlan-ai-product-tags`' vendor key inputs (password field,
`autocomplete="off"`).

Default model values: reuse `herlan-ai-product-tags`' current defaults
(`meta-llama/llama-3.1-8b-instruct:free` for OpenRouter,
`llama-3.1-8b-instant` for Groq) since they're already known-working free
tiers on this install.

### Wiring into the plugin bootstrap

In `fibosearch-enhancer.php`'s `fse_init()`, require and instantiate the two
new classes alongside the existing modules:

```php
require_once FSE_DIR . 'includes/class-ai-client.php';
require_once FSE_DIR . 'includes/class-ai-query-enhancer.php';
...
new FSE_AIQueryEnhancer();
```

`FSE_AIQueryEnhancer` constructs its own `FSE_AI_Client` internally (or
receives one) — the client itself has no hook registrations, it's a plain
callable service.

## Data flow (single search request)

1. User types a query in the FiboSearch box → ajax request hits FiboSearch's
   search engine.
2. `dgwt/wcas/phrase` fires → `FSE_SynonymSearch::build_map` (existing,
   priority 5) and `FSE_AIQueryEnhancer::build_ai_data` (new, priority 4)
   both run against the same keyword.
3. For each search term, `dgwt/wcas/native/search_query/search_or` fires →
   `FSE_FuzzySearch`, `FSE_SynonymSearch`, and `FSE_AIQueryEnhancer` each
   append their own OR conditions onto the same SQL group.
4. Results come back; `dgwt/wcas/search_results/product/score` fires per
   product → `FSE_ScoreBoost` and `FSE_AIQueryEnhancer` both add their
   boosts.
5. If AI was disabled, unconfigured, or failed/timed out for this query,
   steps 3–4's AI contributions are simply absent — the existing fuzzy and
   synonym modules still run normally.

## Error handling

- No exceptions cross module boundaries; `FSE_AI_Client` catches all cURL
  failures and malformed responses internally and returns `null`.
- No user-visible errors under any AI failure mode — search always falls
  back to FiboSearch's normal behavior plus the existing algorithmic
  modules.
- No logging by default; if useful for debugging, gate any `error_log()`
  calls behind `WP_DEBUG`.

## Testing plan

Manual verification (no existing automated test suite in this plugin):
1. With AI disabled: confirm search behaves exactly as before (regression
   check).
2. With one vendor configured (e.g. Groq) and AI enabled: search a
   misspelled product name, confirm corrected/synonym terms surface
   matching products that plain LIKE search would miss.
3. Confirm a second identical search (within cache TTL) does not trigger a
   new outbound API call (verify via vendor dashboard or temporary logging).
4. Disable network/use an invalid API key: confirm search still returns
   normal (non-AI) results with no errors/warnings shown to the user or in
   the PHP error log.
5. Search a query with an obvious category match (e.g. "waterproof jacket"
   for a store with a "Jackets" category): confirm products in that
   category rank higher than they did before, without other categories
   being suppressed.
