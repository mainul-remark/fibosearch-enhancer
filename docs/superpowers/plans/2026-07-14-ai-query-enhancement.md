# AI Query Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an AI-assisted query enhancement module (typo correction, synonym expansion, category-intent boosting) to the existing `fibosearch-enhancer` WordPress plugin.

**Architecture:** Two new PHP classes — `FSE_AI_Client` (races Gemini/OpenRouter/Groq in parallel via `curl_multi`, returns normalized data or `null`) and `FSE_AIQueryEnhancer` (caches per-query results in a WP transient, hooks into FiboSearch's `dgwt/wcas/phrase`, `dgwt/wcas/native/search_query/search_or`, and `dgwt/wcas/search_results/product/score` filters). Both follow the existing plugin's conventions: constructor reads `fse_get_option()` directly, no dependency injection, fail-open on any AI error.

**Tech Stack:** PHP 8.4, WordPress hooks/transients API, cURL (`curl_multi_*`), no build step, no package manager, no existing automated test suite (verification is `php -l` syntax checks + manual functional testing per the spec's Testing Plan).

## Global Constraints

- Every new PHP file starts with `if ( ! defined( 'ABSPATH' ) ) exit;` (matches every existing file in this plugin).
- No exceptions may cross module boundaries — AI failures return `null`/fall through silently, never surface an error to the search UI.
- All new settings live in the single `fse_settings` option (via `FSE_Admin::sanitize_settings`), reachable through the existing `fse_get_option( $key, $default )` helper — no new standalone `add_option`/`register_setting` calls.
- Cache transient key format: `'fse_ai_' . md5( strtolower( trim( $keyword ) ) )`.
- Default vendor models: OpenRouter `meta-llama/llama-3.1-8b-instruct:free`, Groq `llama-3.1-8b-instant`, Gemini `gemini-2.5-flash`.
- Default `ai_cache_ttl_hours` = `24` (clamp 1–168), default `ai_timeout_seconds` = `1.5` (clamp 0.5–5.0).
- Category boost constant: `AI_CATEGORY_BOOST = 25`.
- `dgwt/wcas/phrase` priority for the new module: `4` (runs before `FSE_SynonymSearch` at priority `5`).

---

## File Structure

- Create: `includes/class-ai-client.php` — `FSE_AI_Client`, the vendor-calling service (no WordPress hooks registered here).
- Create: `includes/class-ai-query-enhancer.php` — `FSE_AIQueryEnhancer`, caching + FiboSearch hook registrations.
- Modify: `admin/class-admin.php` — extend `sanitize_settings()` to accept and clamp the new AI settings fields.
- Modify: `admin/views/page-settings.php` — add an "AI Search" tab with the vendor/enable/cache/timeout fields.
- Modify: `fibosearch-enhancer.php` — require and instantiate the two new classes inside `fse_init()`.

---

### Task 1: FSE_AI_Client — vendor calling service

**Files:**
- Create: `includes/class-ai-client.php`

**Interfaces:**
- Consumes: `fse_get_option( string $key, $default = '' )` (global function, already defined in `fibosearch-enhancer.php`).
- Produces: `FSE_AI_Client::fetch( string $keyword ): ?array` returning either `null` or `['corrected' => string, 'synonyms' => string[], 'category' => string|null]`. Consumed by Task 2.

- [ ] **Step 1: Create the file with prompt building and cURL handle builders**

```php
<?php
/**
 * Multi-vendor AI client for query understanding.
 *
 * Races all enabled/configured vendors (Gemini, OpenRouter, Groq) in
 * parallel via curl_multi and returns the first vendor's valid, parseable
 * response. Never throws — returns null on any failure so callers can fail
 * open.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AI_Client {

    const GEMINI_MODEL = 'gemini-2.5-flash';

    /**
     * Fetch AI query data for a keyword. Returns null if no vendor is
     * configured, or if every configured vendor fails/times out.
     *
     * @return array{corrected:string, synonyms:string[], category:?string}|null
     */
    public function fetch( string $keyword ): ?array {
        $keyword = trim( $keyword );
        if ( '' === $keyword ) return null;

        $timeout = (float) fse_get_option( 'ai_timeout_seconds', '1.5' );
        if ( $timeout <= 0 ) $timeout = 1.5;

        $prompt  = $this->build_prompt( $keyword );
        $multi   = curl_multi_init();
        $handles = [];

        if ( '1' === fse_get_option( 'ai_gemini_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_gemini_key', '' );
            if ( '' !== $key ) {
                $handles['gemini'] = $this->make_gemini_curl( $prompt, $key, $timeout );
                curl_multi_add_handle( $multi, $handles['gemini'] );
            }
        }

        if ( '1' === fse_get_option( 'ai_openrouter_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_openrouter_key', '' );
            if ( '' !== $key ) {
                $model = (string) fse_get_option( 'ai_openrouter_model', 'meta-llama/llama-3.1-8b-instruct:free' );
                $handles['openrouter'] = $this->make_openai_compat_curl(
                    'https://openrouter.ai/api/v1/chat/completions',
                    $key,
                    $model,
                    $prompt,
                    $timeout
                );
                curl_multi_add_handle( $multi, $handles['openrouter'] );
            }
        }

        if ( '1' === fse_get_option( 'ai_groq_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_groq_key', '' );
            if ( '' !== $key ) {
                $model = (string) fse_get_option( 'ai_groq_model', 'llama-3.1-8b-instant' );
                $handles['groq'] = $this->make_openai_compat_curl(
                    'https://api.groq.com/openai/v1/chat/completions',
                    $key,
                    $model,
                    $prompt,
                    $timeout
                );
                curl_multi_add_handle( $multi, $handles['groq'] );
            }
        }

        if ( empty( $handles ) ) {
            curl_multi_close( $multi );
            return null;
        }

        $result = $this->race( $multi, $handles, $timeout, $keyword );

        foreach ( $handles as $ch ) {
            curl_multi_remove_handle( $multi, $ch );
            curl_close( $ch );
        }
        curl_multi_close( $multi );

        return $result;
    }

    private function build_prompt( string $keyword ): string {
        return
            "You are a WooCommerce store search assistant. A shopper searched for: \"{$keyword}\"\n\n" .
            "Return ONLY strict JSON (no markdown, no code fences, no extra text) with this exact shape:\n" .
            '{"corrected": "typo-corrected version of the query", "synonyms": ["up to 5 related search terms"], "category": "best single-guess product category name, or null if unclear"}' . "\n\n" .
            "Rules: \"corrected\" is a short search phrase, not a sentence. \"synonyms\" are single words or short phrases a shopper might search instead. \"category\" is your single best guess at a product category name for this query, or null if you're not confident.";
    }

    private function ca_bundle(): string {
        return ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    }

    private function make_gemini_curl( string $prompt, string $api_key, float $timeout ) {
        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::GEMINI_MODEL . ':generateContent';
        $body = wp_json_encode( [
            'contents'         => [
                [ 'parts' => [ [ 'text' => $prompt ] ] ],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
            ],
        ] );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int) ( $timeout * 1000 ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $this->ca_bundle(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $api_key,
                'Content-Length: ' . strlen( $body ),
            ],
        ] );

        return $ch;
    }

    private function make_openai_compat_curl( string $endpoint, string $api_key, string $model, string $prompt, float $timeout ) {
        $body = wp_json_encode( [
            'model'       => $model,
            'temperature' => 0.2,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You are a WooCommerce store search assistant. Return ONLY a strict JSON object — no other text, no markdown, no code fences.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ] );

        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int) ( $timeout * 1000 ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $this->ca_bundle(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'Content-Length: ' . strlen( $body ),
            ],
        ] );

        return $ch;
    }
}
```

- [ ] **Step 2: Run `php -l` to verify syntax**

Run: `php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/includes/class-ai-client.php"`
Expected: `No syntax errors detected in ...class-ai-client.php`

- [ ] **Step 3: Commit**

```bash
cd "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer"
git add includes/class-ai-client.php
git commit -m "Add FSE_AI_Client cURL scaffolding for AI vendor calls"
```

---

### Task 2: FSE_AI_Client — response racing, parsing, and normalization

**Files:**
- Modify: `includes/class-ai-client.php` (append the remaining private methods used by `fetch()` from Task 1)

**Interfaces:**
- Consumes: `curl_multi_info_read()`, `curl_getinfo()`, `curl_multi_getcontent()` (PHP cURL API), the `$handles` array built in Task 1 keyed by vendor name (`'gemini' | 'openrouter' | 'groq'`).
- Produces: completes `FSE_AI_Client::fetch()` from Task 1 so it returns real data instead of always racing against empty parsing logic.

- [ ] **Step 1: Append `race()`, `parse_response()`, and `normalize_result()` to the class**

Insert these methods into the `FSE_AI_Client` class body (after `make_openai_compat_curl`, before the closing `}` of the class):

```php
    /**
     * Poll the multi-handle until the first vendor returns a valid,
     * parseable response, or the overall timeout elapses.
     */
    private function race( $multi, array $handles, float $timeout, string $keyword ): ?array {
        $start   = microtime( true );
        $running = null;
        $done    = [];

        do {
            curl_multi_exec( $multi, $running );

            while ( $info = curl_multi_info_read( $multi ) ) {
                $ch     = $info['handle'];
                $vendor = array_search( $ch, $handles, true );
                if ( false === $vendor || isset( $done[ $vendor ] ) ) continue;
                $done[ $vendor ] = true;

                $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                if ( 200 === $http_code ) {
                    $body   = curl_multi_getcontent( $ch );
                    $parsed = $this->parse_response( $vendor, (string) $body, $keyword );
                    if ( null !== $parsed ) {
                        return $parsed;
                    }
                }
            }

            if ( $running ) {
                curl_multi_select( $multi, 0.1 );
            }
        } while ( $running > 0 && ( microtime( true ) - $start ) < $timeout );

        return null;
    }

    /**
     * Extract the model's text output and decode it as the expected JSON
     * shape. Falls back to regex-extracting the first {...} block if the
     * model wrapped the JSON in prose or markdown fences.
     */
    private function parse_response( string $vendor, string $body, string $keyword ): ?array {
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) return null;

        if ( 'gemini' === $vendor ) {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $text = $data['choices'][0]['message']['content'] ?? '';
        }

        $text = trim( (string) $text );
        if ( '' === $text ) return null;

        $parsed = json_decode( $text, true );
        if ( ! is_array( $parsed ) && preg_match( '/\{.*\}/s', $text, $matches ) ) {
            $parsed = json_decode( $matches[0], true );
        }

        if ( ! is_array( $parsed ) ) return null;

        return $this->normalize_result( $parsed, $keyword );
    }

    /**
     * Sanitize and shape the AI's raw JSON into the contract callers rely
     * on. Missing/invalid fields fall back to safe defaults rather than
     * failing the whole result.
     */
    private function normalize_result( array $data, string $keyword ): array {
        $corrected = ( isset( $data['corrected'] ) && is_string( $data['corrected'] ) && '' !== trim( $data['corrected'] ) )
            ? sanitize_text_field( $data['corrected'] )
            : $keyword;

        $synonyms = [];
        if ( isset( $data['synonyms'] ) && is_array( $data['synonyms'] ) ) {
            foreach ( $data['synonyms'] as $synonym ) {
                if ( is_string( $synonym ) && '' !== trim( $synonym ) ) {
                    $synonyms[] = sanitize_text_field( $synonym );
                }
            }
        }
        $synonyms = array_slice( array_values( array_unique( $synonyms ) ), 0, 5 );

        $category = null;
        if ( isset( $data['category'] ) && is_string( $data['category'] ) && '' !== trim( $data['category'] ) ) {
            $category = sanitize_text_field( $data['category'] );
        }

        return [
            'corrected' => $corrected,
            'synonyms'  => $synonyms,
            'category'  => $category,
        ];
    }
```

- [ ] **Step 2: Run `php -l` to verify syntax**

Run: `php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/includes/class-ai-client.php"`
Expected: `No syntax errors detected in ...class-ai-client.php`

- [ ] **Step 3: Manual smoke test of parsing logic in isolation**

Run this standalone script to prove `parse_response`/`normalize_result` behave correctly without needing WordPress loaded (temporarily stub the two WP functions they call):

```bash
php -r '
define("ABSPATH", "/tmp/");
function sanitize_text_field($s) { return trim(strip_tags($s)); }
require "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/includes/class-ai-client.php";

$client = new FSE_AI_Client();
$ref = new ReflectionClass($client);

$parse = $ref->getMethod("parse_response");
$parse->setAccessible(true);

// Gemini-shaped body wrapping JSON in markdown fences
$geminiBody = json_encode([
    "candidates" => [[ "content" => [ "parts" => [[ "text" => "```json\n{\"corrected\":\"running shoes\",\"synonyms\":[\"sneakers\",\"trainers\"],\"category\":\"Footwear\"}\n```" ]] ] ]]
]);
$result = $parse->invoke($client, "gemini", $geminiBody, "runing shoos");
var_dump($result);

// OpenAI-compatible body, clean JSON
$orBody = json_encode([
    "choices" => [[ "message" => [ "content" => "{\"corrected\":\"lip balm\",\"synonyms\":[\"chapstick\"],\"category\":null}" ] ]]
]);
$result2 = $parse->invoke($client, "openrouter", $orBody, "lip blam");
var_dump($result2);

// Garbage body
$result3 = $parse->invoke($client, "groq", "not json at all", "x");
var_dump($result3);
'
```

Expected output: first two `var_dump` calls show arrays with `corrected`/`synonyms`/`category` keys populated as expected (note: the Gemini example is a negative case for the markdown-fence fallback — `json_decode` on the raw fenced text fails, then the regex fallback extracts the `{...}` block successfully). Third call outputs `NULL`.

- [ ] **Step 4: Commit**

```bash
cd "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer"
git add includes/class-ai-client.php
git commit -m "Add response racing, parsing, and normalization to FSE_AI_Client"
```

---

### Task 3: FSE_AIQueryEnhancer — caching and FiboSearch hooks

**Files:**
- Create: `includes/class-ai-query-enhancer.php`

**Interfaces:**
- Consumes: `FSE_AI_Client::fetch( string $keyword ): ?array` (Task 1+2), `FSE_Helpers::term_from_like( $like )` (existing, in `includes/class-helpers.php`), `fse_get_option()` (existing global).
- Produces: `FSE_AIQueryEnhancer` class with a no-arg constructor that self-registers WordPress filters when `ai_enabled` is on. Instantiated by Task 5's bootstrap wiring — no other task calls its methods directly.

- [ ] **Step 1: Create the file**

```php
<?php
/**
 * AI-assisted query understanding: typo correction, synonym expansion, and
 * category-intent boosting, layered on top of FiboSearch's existing SQL
 * search and score-boost hooks.
 *
 * Results are cached per normalized keyword in a WP transient. Any AI
 * failure (no vendor configured, timeout, malformed response) is treated as
 * "no AI data available" for that request — search falls back to
 * FiboSearch's normal behavior plus the plugin's other algorithmic modules.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AIQueryEnhancer {

    /** Minimum keyword length to attempt an AI call */
    const MIN_LENGTH = 3;

    /** Score boost applied when a product belongs to the AI-detected category */
    const AI_CATEGORY_BOOST = 25;

    /** @var FSE_AI_Client */
    private $client;

    /**
     * Per-request cache of resolved AI data, keyed by normalized keyword.
     * Value is an array (see FSE_AI_Client::normalize_result shape, plus
     * 'category_term_id') or null when no AI data is available.
     *
     * @var array<string, array|null>
     */
    private $data_by_keyword = [];

    public function __construct() {
        if ( '1' !== fse_get_option( 'ai_enabled', '0' ) ) return;

        $this->client = new FSE_AI_Client();

        add_filter( 'dgwt/wcas/phrase',                        [ $this, 'build_ai_data' ], 4 );
        add_filter( 'dgwt/wcas/native/search_query/search_or', [ $this, 'add_conditions' ], 10, 3 );
        add_filter( 'dgwt/wcas/search_results/product/score',  [ $this, 'boost' ],          10, 4 );
    }

    /**
     * Resolve (from cache or a live AI call) the corrected/synonym/category
     * data for the current search phrase. Does not mutate the phrase.
     */
    public function build_ai_data( $keyword ) {
        if ( empty( $keyword ) ) return $keyword;

        $normalized = strtolower( trim( $keyword ) );
        if ( strlen( $normalized ) < self::MIN_LENGTH ) return $keyword;

        $cache_key = 'fse_ai_' . md5( $normalized );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->data_by_keyword[ $normalized ] = $cached;
            return $keyword;
        }

        $raw = $this->client->fetch( $normalized );

        if ( null === $raw ) {
            $this->data_by_keyword[ $normalized ] = null;
            return $keyword;
        }

        $resolved = [
            'corrected'        => $raw['corrected'],
            'synonyms'         => $raw['synonyms'],
            'category_term_id' => $this->resolve_category( $raw['category'] ),
        ];

        $ttl_hours = (int) fse_get_option( 'ai_cache_ttl_hours', '24' );
        if ( $ttl_hours <= 0 ) $ttl_hours = 24;

        set_transient( $cache_key, $resolved, $ttl_hours * HOUR_IN_SECONDS );
        $this->data_by_keyword[ $normalized ] = $resolved;

        return $keyword;
    }

    /**
     * Add OR conditions for the AI-corrected term and each synonym, same
     * SQL-building style as FSE_SynonymSearch::add_conditions.
     *
     * @param string $search  Accumulated SQL for the current term's OR group.
     * @param string $like    The LIKE pattern for the current term, e.g. '%runing%'.
     * @param object $engine  FiboSearch Search engine instance.
     */
    public function add_conditions( $search, $like, $engine ) {
        $term = FSE_Helpers::term_from_like( $like );
        $data = $this->data_by_keyword[ $term ] ?? null;

        if ( empty( $data ) ) return $search;

        global $wpdb;

        $extra_terms = [];
        if ( ! empty( $data['corrected'] ) && strtolower( $data['corrected'] ) !== $term ) {
            $extra_terms[] = $data['corrected'];
        }
        foreach ( (array) $data['synonyms'] as $synonym ) {
            $extra_terms[] = $synonym;
        }

        foreach ( array_unique( $extra_terms ) as $extra_term ) {
            $like_pattern = '%' . $wpdb->esc_like( $extra_term ) . '%';

            $condition = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
                $like_pattern, $like_pattern, $like_pattern
            );

            $search .= " OR {$condition}";
        }

        return $search;
    }

    /**
     * Boost products belonging to the AI-detected category for this query.
     *
     * @param float    $score    Current relevance score (higher = better).
     * @param string   $keyword  The search phrase.
     * @param int      $post_id  Product post ID.
     * @param \WP_Post $post     Product post object.
     */
    public function boost( $score, $keyword, $post_id, $post ) {
        $normalized = strtolower( trim( (string) $keyword ) );
        $data       = $this->data_by_keyword[ $normalized ] ?? null;

        if ( empty( $data ) || empty( $data['category_term_id'] ) ) return $score;

        if ( has_term( (int) $data['category_term_id'], 'product_cat', $post_id ) ) {
            $score += self::AI_CATEGORY_BOOST;
        }

        return $score;
    }

    /**
     * Resolve an AI-guessed category name to a real product_cat term ID.
     * Returns null if there's no matching real category, so a hallucinated
     * guess can never affect ranking.
     */
    private function resolve_category( ?string $name ): ?int {
        if ( empty( $name ) ) return null;

        $term = get_term_by( 'name', $name, 'product_cat' );
        if ( ! $term ) {
            $term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
        }

        return $term ? (int) $term->term_id : null;
    }
}
```

- [ ] **Step 2: Run `php -l` to verify syntax**

Run: `php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/includes/class-ai-query-enhancer.php"`
Expected: `No syntax errors detected in ...class-ai-query-enhancer.php`

- [ ] **Step 3: Commit**

```bash
cd "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer"
git add includes/class-ai-query-enhancer.php
git commit -m "Add FSE_AIQueryEnhancer with caching and FiboSearch hook wiring"
```

---

### Task 4: Admin settings — sanitize new AI fields and add the AI Search settings tab

**Files:**
- Modify: `admin/class-admin.php:30-50` (the `sanitize_settings` method)
- Modify: `admin/views/page-settings.php` (add nav button + tab content)

**Interfaces:**
- Consumes: nothing new — reads/writes the existing `fse_settings` option structure.
- Produces: `fse_get_option( 'ai_enabled' )`, `fse_get_option( 'ai_gemini_enabled'|'ai_gemini_key' )`, `fse_get_option( 'ai_openrouter_enabled'|'ai_openrouter_key'|'ai_openrouter_model' )`, `fse_get_option( 'ai_groq_enabled'|'ai_groq_key'|'ai_groq_model' )`, `fse_get_option( 'ai_cache_ttl_hours' )`, `fse_get_option( 'ai_timeout_seconds' )` — all consumed by Task 1–3's classes (already written to expect exactly these keys/defaults).

- [ ] **Step 1: Extend `sanitize_settings()` in `admin/class-admin.php`**

Replace the existing method (currently `admin/class-admin.php:30-50`):

```php
    public function sanitize_settings( $input ) {
        $toggles = [
            'variation_sku_enabled',
            'attribute_search_enabled',
            'tag_search_enabled',
            'custom_fields_enabled',
            'synonyms_enabled',
            'fuzzy_enabled',
            'score_boost_enabled',
            'ai_enabled',
            'ai_gemini_enabled',
            'ai_openrouter_enabled',
            'ai_groq_enabled',
        ];

        $clean = [];
        foreach ( $toggles as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? '1' : '0';
        }

        $text_fields = [
            'ai_gemini_key',
            'ai_openrouter_key',
            'ai_openrouter_model',
            'ai_groq_key',
            'ai_groq_model',
        ];
        foreach ( $text_fields as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
        }

        $ttl = isset( $input['ai_cache_ttl_hours'] ) ? (int) $input['ai_cache_ttl_hours'] : 24;
        $clean['ai_cache_ttl_hours'] = (string) max( 1, min( 168, $ttl ) );

        $timeout = isset( $input['ai_timeout_seconds'] ) ? (float) $input['ai_timeout_seconds'] : 1.5;
        $clean['ai_timeout_seconds'] = (string) max( 0.5, min( 5.0, $timeout ) );

        // Manual custom field keys — commented out (now auto-detects all public meta fields)
        // $clean['custom_field_keys'] = sanitize_textarea_field( $input['custom_field_keys'] ?? '' );

        return $clean;
    }
```

- [ ] **Step 2: Add the "AI Search" tab button in `admin/views/page-settings.php`**

Find this block (`admin/views/page-settings.php:11-19`):

```php
    <nav class="fse-tabs" aria-label="Settings sections">
        <button class="fse-tab active" data-tab="features">Features</button>
```

Replace it with:

```php
    <nav class="fse-tabs" aria-label="Settings sections">
        <button class="fse-tab active" data-tab="features">Features</button>
        <button class="fse-tab" data-tab="ai-search">AI Search</button>
```

- [ ] **Step 3: Add the AI Search tab content**

Find the closing `</form>` tag that ends the Features tab form (`admin/views/page-settings.php`, right after the `<?php /* ── CUSTOM FIELDS TAB` comment block and before `</form>`). Insert a new tab content `<div>` immediately before `</form>`, inside the same `<form>` so it submits with the same `fse_settings_group`:

```php
        <!-- ── AI SEARCH TAB ── -->
        <div class="fse-tab-content" id="tab-ai-search">
            <table class="form-table fse-table">
                <tr>
                    <th scope="row">Enable AI Query Enhancement</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ai_enabled]" value="1" <?php checked( fse_opt( 'ai_enabled', '0' ) ); ?>>
                            Use AI to correct typos, expand synonyms, and detect category intent on each search
                        </label>
                        <p class="description">Requires at least one vendor below to be enabled with a valid API key. If the AI call fails or times out, search silently falls back to the Features above.</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><h2 style="margin:16px 0 4px;padding-bottom:4px;border-bottom:1px solid #c3c4c7;">Google Gemini</h2></th>
                </tr>
                <tr>
                    <th scope="row">Active</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ai_gemini_enabled]" value="1" <?php checked( fse_opt( 'ai_gemini_enabled', '0' ) ); ?>>
                            Enable Gemini
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_gemini_key">API Key</label></th>
                    <td>
                        <input type="password" id="fse_ai_gemini_key" name="fse_settings[ai_gemini_key]" value="<?php echo esc_attr( fse_get_option( 'ai_gemini_key', '' ) ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Model used: gemini-2.5-flash</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><h2 style="margin:16px 0 4px;padding-bottom:4px;border-bottom:1px solid #c3c4c7;">OpenRouter</h2></th>
                </tr>
                <tr>
                    <th scope="row">Active</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ai_openrouter_enabled]" value="1" <?php checked( fse_opt( 'ai_openrouter_enabled', '0' ) ); ?>>
                            Enable OpenRouter
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_openrouter_key">API Key</label></th>
                    <td>
                        <input type="password" id="fse_ai_openrouter_key" name="fse_settings[ai_openrouter_key]" value="<?php echo esc_attr( fse_get_option( 'ai_openrouter_key', '' ) ); ?>" class="regular-text" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_openrouter_model">Model</label></th>
                    <td>
                        <input type="text" id="fse_ai_openrouter_model" name="fse_settings[ai_openrouter_model]" value="<?php echo esc_attr( fse_get_option( 'ai_openrouter_model', 'meta-llama/llama-3.1-8b-instruct:free' ) ); ?>" class="regular-text">
                        <p class="description">Default: meta-llama/llama-3.1-8b-instruct:free</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><h2 style="margin:16px 0 4px;padding-bottom:4px;border-bottom:1px solid #c3c4c7;">Groq</h2></th>
                </tr>
                <tr>
                    <th scope="row">Active</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ai_groq_enabled]" value="1" <?php checked( fse_opt( 'ai_groq_enabled', '0' ) ); ?>>
                            Enable Groq
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_groq_key">API Key</label></th>
                    <td>
                        <input type="password" id="fse_ai_groq_key" name="fse_settings[ai_groq_key]" value="<?php echo esc_attr( fse_get_option( 'ai_groq_key', '' ) ); ?>" class="regular-text" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_groq_model">Model</label></th>
                    <td>
                        <input type="text" id="fse_ai_groq_model" name="fse_settings[ai_groq_model]" value="<?php echo esc_attr( fse_get_option( 'ai_groq_model', 'llama-3.1-8b-instant' ) ); ?>" class="regular-text">
                        <p class="description">Default: llama-3.1-8b-instant</p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2"><h2 style="margin:16px 0 4px;padding-bottom:4px;border-bottom:1px solid #c3c4c7;">Behavior</h2></th>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_cache_ttl_hours">Cache duration (hours)</label></th>
                    <td>
                        <input type="number" id="fse_ai_cache_ttl_hours" name="fse_settings[ai_cache_ttl_hours]" value="<?php echo esc_attr( fse_get_option( 'ai_cache_ttl_hours', '24' ) ); ?>" min="1" max="168" style="width:90px;">
                        <p class="description">How long to cache AI results per search term (1–168 hours).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fse_ai_timeout_seconds">Timeout (seconds)</label></th>
                    <td>
                        <input type="number" step="0.1" id="fse_ai_timeout_seconds" name="fse_settings[ai_timeout_seconds]" value="<?php echo esc_attr( fse_get_option( 'ai_timeout_seconds', '1.5' ) ); ?>" min="0.5" max="5" style="width:90px;">
                        <p class="description">Max time to wait for an AI response before falling back to normal search (0.5–5 seconds).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </div>

```

Note: the `fse_opt()` helper defined at the top of this file (`admin/views/page-settings.php:4-6`) already accepts a default as its second argument — pass `'0'` for the new AI toggles since they must default to off (unlike the existing Features toggles, which default to on).

- [ ] **Step 4: Run `php -l` on both modified files**

Run:
```bash
php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/admin/class-admin.php"
php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/admin/views/page-settings.php"
```
Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Commit**

```bash
cd "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer"
git add admin/class-admin.php admin/views/page-settings.php
git commit -m "Add AI Search settings tab and sanitize new AI settings fields"
```

---

### Task 5: Wire modules into the plugin bootstrap and verify end-to-end

**Files:**
- Modify: `fibosearch-enhancer.php:25-40` (the `fse_init()` function)

**Interfaces:**
- Consumes: `FSE_AI_Client` (Task 1+2), `FSE_AIQueryEnhancer` (Task 3) — both already fully self-contained.
- Produces: nothing further downstream; this is the final integration point.

- [ ] **Step 1: Add the require and instantiate lines**

In `fibosearch-enhancer.php`, find:

```php
    require_once FSE_DIR . 'includes/class-helpers.php';
    require_once FSE_DIR . 'includes/class-variation-sku-search.php';
    require_once FSE_DIR . 'includes/class-attribute-search.php';
    require_once FSE_DIR . 'includes/class-tag-search.php';
    require_once FSE_DIR . 'includes/class-custom-field-search.php';
    require_once FSE_DIR . 'includes/class-synonym-search.php';
    require_once FSE_DIR . 'includes/class-fuzzy-search.php';
    require_once FSE_DIR . 'includes/class-score-boost.php';

    new FSE_VariationSkuSearch();
    new FSE_AttributeSearch();
    new FSE_TagSearch();
    new FSE_CustomFieldSearch();
    new FSE_SynonymSearch();
    new FSE_FuzzySearch();
    new FSE_ScoreBoost();
```

Replace it with:

```php
    require_once FSE_DIR . 'includes/class-helpers.php';
    require_once FSE_DIR . 'includes/class-variation-sku-search.php';
    require_once FSE_DIR . 'includes/class-attribute-search.php';
    require_once FSE_DIR . 'includes/class-tag-search.php';
    require_once FSE_DIR . 'includes/class-custom-field-search.php';
    require_once FSE_DIR . 'includes/class-synonym-search.php';
    require_once FSE_DIR . 'includes/class-fuzzy-search.php';
    require_once FSE_DIR . 'includes/class-score-boost.php';
    require_once FSE_DIR . 'includes/class-ai-client.php';
    require_once FSE_DIR . 'includes/class-ai-query-enhancer.php';

    new FSE_VariationSkuSearch();
    new FSE_AttributeSearch();
    new FSE_TagSearch();
    new FSE_CustomFieldSearch();
    new FSE_SynonymSearch();
    new FSE_FuzzySearch();
    new FSE_ScoreBoost();
    new FSE_AIQueryEnhancer();
```

- [ ] **Step 2: Run `php -l` on the bootstrap file**

Run: `php -l "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer/fibosearch-enhancer.php"`
Expected: `No syntax errors detected in ...fibosearch-enhancer.php`

- [ ] **Step 3: Commit**

```bash
cd "E:/laragon/www/herlanlive5/wp-content/plugins/fibosearch-enhancer"
git add fibosearch-enhancer.php
git commit -m "Wire FSE_AIQueryEnhancer into the plugin bootstrap"
```

- [ ] **Step 4: Manual functional verification on the live site**

This plugin has no automated test suite; verify against the actual WordPress/WooCommerce install at `E:/laragon/www/herlanlive5`. Walk through the scenarios from the spec's Testing Plan (`docs/superpowers/specs/2026-07-14-ai-query-enhancement-design.md`):

1. Visit **WooCommerce → Search Enhancer → AI Search** tab in wp-admin. Confirm the new tab renders with all vendor sections, and "Enable AI Query Enhancement" is unchecked by default.
2. With AI disabled: perform a FiboSearch product search on the frontend. Confirm results are unchanged from before this feature was added (regression check).
3. Enable AI, enable Groq, paste in a valid Groq API key, save. Search a deliberately misspelled product name that exists in the catalog. Confirm the corrected/synonym expansion surfaces matches a plain LIKE search would miss.
4. Repeat the exact same search immediately. Confirm it returns instantly (transient cache hit) — check via `wp transient get <key>` in WP-CLI or by inspecting the `wp_options` table for a `_transient_fse_ai_*` row, rather than by re-observing network timing.
5. Temporarily set the Groq API key to an invalid value and search again. Confirm results still return normally (fallback to Features-tab modules) with no PHP warnings/errors in the site's debug log.
6. If the store has product categories, search a query with an obvious category match (e.g. a category name or close synonym). Confirm products in that category rank at or near the top, without other relevant categories disappearing from results.

Report back which of these passed/failed.

---

## Self-Review Notes

- **Spec coverage:** `FSE_AI_Client` (spec's client section) → Tasks 1–2. `FSE_AIQueryEnhancer` caching/hooks/category-resolution (spec's enhancer + caching sections) → Task 3. Admin settings + UI (spec's admin section) → Task 4. Bootstrap wiring (spec's wiring section) → Task 5. Testing plan → Task 5 Step 4. All spec sections have a corresponding task.
- **Placeholder scan:** no TBD/TODO; every step has literal, runnable code or exact commands.
- **Type consistency:** `FSE_AI_Client::fetch()` returns `['corrected'=>string,'synonyms'=>string[],'category'=>string|null]` in Task 2, and Task 3's `build_ai_data()` reads exactly those three keys off the same array before adding `category_term_id`. `add_conditions()` and `boost()` both read from `$this->data_by_keyword`, keyed identically (`strtolower(trim($keyword))`) in `build_ai_data()`, `add_conditions()` (via `FSE_Helpers::term_from_like`, which already lowercases/trims), and `boost()`. Settings keys (`ai_enabled`, `ai_gemini_enabled`/`ai_gemini_key`, `ai_openrouter_enabled`/`ai_openrouter_key`/`ai_openrouter_model`, `ai_groq_enabled`/`ai_groq_key`/`ai_groq_model`, `ai_cache_ttl_hours`, `ai_timeout_seconds`) match exactly between Task 4's sanitize/UI code and Tasks 1–3's `fse_get_option()` calls.
