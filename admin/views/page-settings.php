<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function fse_opt( $key, $default = '1' ) {
    return fse_get_option( $key, $default ) === '1';
}
?>
<div class="wrap fse-wrap">
    <h1>FiboSearch Enhancer <span class="fse-version">v<?php echo esc_html( FSE_VERSION ); ?></span></h1>

    <nav class="fse-tabs" aria-label="Settings sections">
        <button class="fse-tab active" data-tab="features">Features</button>
        <button class="fse-tab" data-tab="ai-search">AI Search</button>
        <button class="fse-tab" data-tab="insights">Insights</button>
        <button class="fse-tab" data-tab="competitor-fallback">Competitor Fallback</button>
        <button class="fse-tab" data-tab="priority-mapping">Priority Mapping</button>
        <button class="fse-tab" data-tab="ai-log">AI Connection Log</button>
        <?php /* Custom Fields tab — commented out (auto-searches all public meta fields, no manual config needed)
        <button class="fse-tab" data-tab="custom-fields">Custom Fields</button>
        */ ?>
        <?php /* Synonyms tab — commented out (synonym groups managed programmatically via DB, not via UI)
        <button class="fse-tab" data-tab="synonyms">Synonyms / Related Words</button>
        */ ?>
    </nav>

    <form method="post" action="options.php" id="fse-settings-form">
        <?php settings_fields( 'fse_settings_group' ); ?>

        <!-- ── FEATURES TAB ── -->
        <div class="fse-tab-content active" id="tab-features">
            <table class="form-table fse-table">
                <tr>
                    <th scope="row">Popular Searches Panel</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[popular_searches_enabled]" value="1" <?php checked( fse_opt( 'popular_searches_enabled' ) ); ?>>
                            Show <strong>popular search suggestions</strong> when the search box is focused
                        </label>
                        <p class="description">Displays the top searched keywords (from FiboSearch Analytics) as clickable chips above the recent-searches list. Requires FiboSearch Analytics to be enabled. Chips update hourly.</p>
                        <br>
                        <label><strong>Manual keywords</strong></label>
                        <textarea name="fse_settings[popular_searches_manual]" rows="3" style="width:100%;max-width:500px;margin-top:4px;font-size:13px;" placeholder="lipstick, foundation, serum"><?php echo esc_textarea( fse_get_option( 'popular_searches_manual', '' ) ); ?></textarea>
                        <p class="description">Comma-separated keywords. When filled, <strong>only these keywords</strong> are shown — analytics-based suggestions are skipped. Leave empty to use auto-detected popular searches from FiboSearch Analytics instead.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Variation SKU Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[variation_sku_enabled]" value="1" <?php checked( fse_opt( 'variation_sku_enabled' ) ); ?>>
                            Search parent products by matching their <strong>variation SKUs</strong>
                        </label>
                        <p class="description">e.g. searching "SKU-RED-L" finds the parent "T-Shirt" even if that word isn't in the title.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Attribute Value Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[attribute_search_enabled]" value="1" <?php checked( fse_opt( 'attribute_search_enabled' ) ); ?>>
                            Search products by their <strong>WooCommerce attribute values</strong> (Color, Size, Material, etc.)
                        </label>
                        <p class="description">e.g. searching "Red" finds all products with the attribute value "Red" even if the title says "T-Shirt". Also matches undertone words already present in shade names (e.g. "warm" finds "Toast 4W Warm").</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Category Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[category_search_enabled]" value="1" <?php checked( fse_opt( 'category_search_enabled' ) ); ?>>
                            Find products by their <strong>product category</strong>
                        </label>
                        <p class="description">e.g. searching "mens care" returns every product in the <em>Mens Care</em> category, even if that phrase isn't in any product's title or description. FiboSearch natively only shows categories as archive links — this surfaces the actual products. Also matches squashed-together spellings ("menscare").</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Product Tag Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[tag_search_enabled]" value="1" <?php checked( fse_opt( 'tag_search_enabled' ) ); ?>>
                            Find products by their <strong>product tags</strong>
                        </label>
                        <p class="description">e.g. searching "moisturizer" returns all products tagged <em>moisturizer</em>, even if the word isn't in the product title or description. FiboSearch natively only shows tags as archive links — this surfaces the actual products.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Custom Field Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[custom_fields_enabled]" value="1" <?php checked( fse_opt( 'custom_fields_enabled' ) ); ?>>
                            Search inside <strong>all product custom meta fields</strong> automatically
                        </label>
                        <p class="description">Searches all public meta fields (non-internal) without any manual configuration.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ingredient Field Ranking Boost</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ingredient_field_boost_enabled]" value="1" <?php checked( fse_opt( 'ingredient_field_boost_enabled' ) ); ?>>
                            Rank products higher when the search matches their <strong>ingredient list, benefits, or directions</strong>
                        </label>
                        <p class="description">e.g. searching "niacinamide" or "salicylic acid" ranks products whose actual ingredient list contains it above products that only match elsewhere — these are precision searches with strong purchase intent.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Custom Taxonomy Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[custom_taxonomy_search_enabled]" value="1" <?php checked( fse_opt( 'custom_taxonomy_search_enabled' ) ); ?>>
                            Search products by <strong>brand, age range, skin type, and keyword</strong> taxonomy terms
                        </label>
                        <p class="description">e.g. searching "sensitive" finds products tagged with the Skin Type term "Sensitive", even if that word isn't in the title. Only taxonomies that actually exist on this site are searched.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Synonym / Related Word Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[synonyms_enabled]" value="1" <?php checked( fse_opt( 'synonyms_enabled' ) ); ?>>
                            Expand search to cover <strong>synonym &amp; related word groups</strong>
                        </label>
                        <p class="description">e.g. searching "lip balm" also returns products matching "chapstick", "lip butter", "lip care".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Fuzzy / Typo-Tolerant Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[fuzzy_enabled]" value="1" <?php checked( fse_opt( 'fuzzy_enabled' ) ); ?>>
                            Match <strong>similar-sounding words</strong> (SOUNDEX) and handle minor trailing-character typos
                        </label>
                        <p class="description">e.g. "nikey" matches "Nike". Activates for keywords ≥ 4 characters.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Singular / Plural Matching</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[stemming_enabled]" value="1" <?php checked( fse_opt( 'stemming_enabled' ) ); ?>>
                            Match <strong>singular/plural word forms</strong> automatically
                        </label>
                        <p class="description">e.g. searching "serum" also matches "serums", and "moisturizers" also matches "moisturizer".</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Relevance Score Boost</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[score_boost_enabled]" value="1" <?php checked( fse_opt( 'score_boost_enabled' ) ); ?>>
                            <strong>Boost ranking</strong> for exact title matches, SKU matches, and variation SKU matches
                        </label>
                        <p class="description">Products with exact or SKU matches appear higher in the dropdown.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Field-Weighted Scoring</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[field_weight_score_enabled]" value="1" <?php checked( fse_opt( 'field_weight_score_enabled' ) ); ?>>
                            Rank products found via <strong>variation SKU / attribute / taxonomy / tag</strong> matches above unrelated matches
                        </label>
                        <p class="description">Without this, products found only through those secondary signals (not the title) sort in arbitrary order relative to each other. Always ranks below actual title/SKU matches.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Zero-Result Search Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[zero_result_logging_enabled]" value="1" <?php checked( fse_opt( 'zero_result_logging_enabled' ) ); ?>>
                            Log search terms that <strong>return no results</strong>
                        </label>
                        <p class="description">View logged terms under the Insights tab — use them to decide what synonyms or taxonomy terms to add.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Search Behavior Tracking</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[behavior_tracking_enabled]" value="1" <?php checked( fse_opt( 'behavior_tracking_enabled' ) ); ?>>
                            Enable search behavior tracking
                        </label>
                        <p class="description">Collects search impressions, clicks, cart-adds, and purchases to power future ranking improvements. Required for any of the upcoming ranking/analytics phases.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Typo Correction</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[typo_correction_enabled]" value="1" <?php checked( fse_opt( 'typo_correction_enabled' ) ); ?>>
                            Rewrite known <strong>misspellings of common beauty terms</strong> before searching
                        </label>
                        <p class="description">e.g. "sirum" → "serum", "mostorizer" → "moisturizer", "liliy"/"lilly" → "lily". Curated from real zero-result search logs — edit the list in <code>class-typo-correction.php</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Bangla Search Translation</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[bangla_translation_enabled]" value="1" <?php checked( fse_opt( 'bangla_translation_enabled' ) ); ?>>
                            Translate <strong>Bangla-script product terms</strong> to English before searching
                        </label>
                        <p class="description">e.g. "সিরাম" → "serum", "শ্যাম্পু" → "shampoo", "চুলের শ্যাম্পু" → "hair shampoo". Product data on this store is English, so Bangla queries otherwise never match — curated from real Bangla zero-result searches. Edit the list in <code>class-bangla-translation.php</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Filler Word Stripping</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[filler_word_strip_enabled]" value="1" <?php checked( fse_opt( 'filler_word_strip_enabled' ) ); ?>>
                            Drop generic words like <strong>"items"/"products"/"stuff"</strong> from the search before matching
                        </label>
                        <p class="description">Search matches ALL words in a query — "baby products" only matches if the literal word "products" appears somewhere too, which it usually doesn't. Confirmed in analytics: "baby products", "discount products", and "girls products" all returned zero results for exactly this reason.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Unstocked Brand / Product Fallback</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[competitor_fallback_enabled]" value="1" <?php checked( fse_opt( 'competitor_fallback_enabled' ) ); ?>>
                            Show <strong>similar-category products</strong> when a search matches a brand or product type this store doesn't carry
                        </label>
                        <p class="description">e.g. searching "cosrx" (unstocked brand) shows best-selling Skin Care products; searching "highlighter" (unstocked product type) shows best-selling Face products. Edit the mapping in the Competitor Fallback tab.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Discount Intent Fallback</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[discount_intent_fallback_enabled]" value="1" <?php checked( fse_opt( 'discount_intent_fallback_enabled' ) ); ?>>
                            Show <strong>actual on-sale products</strong> when a search signals discount/sale intent
                        </label>
                        <p class="description">e.g. "50% discount products", "sale", "50% off" aren't text searches — there's nothing in product titles/descriptions to match. Instead this shows real on-sale products (via WooCommerce sale price data), ranked by biggest discount first.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Priority Mapping (Search Redirect)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[priority_mapping_enabled]" value="1" <?php checked( fse_opt( 'priority_mapping_enabled' ) ); ?>>
                            Re-rank results for mapped keywords (competitor brands, product types, concerns) into a fixed priority order
                        </label>
                        <p class="description">e.g. searching "Sunsilk" shows Lily Silkore Shampoo first, then other Lily shampoos, then all shampoos, then conditioner/hair oil/hair serum/hair mask, then the rest of Hair Care, then everything else. Configure mappings in the Priority Mapping tab.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </div>

        <?php /* ── CUSTOM FIELDS TAB — commented out (auto-searches all public meta fields, no manual input needed) ──
        <div class="fse-tab-content" id="tab-custom-fields">
            <p>Enter one <strong>meta key</strong> per line. The search will also look inside these fields.</p>
            <p class="description">
                Common examples: <code>_barcode</code>, <code>_isbn</code>, <code>_model_number</code>, <code>_part_number</code>, <code>_gtin</code><br>
                <strong>Note:</strong> WooCommerce attribute values (Color, Size) are stored as taxonomy terms — use the <em>Attribute Value Search</em> feature above for those.
            </p>
            <textarea
                name="fse_settings[custom_field_keys]"
                rows="10"
                class="large-text code"
                placeholder="_barcode&#10;_isbn&#10;_model_number"
            ><?php echo esc_textarea( fse_get_option( 'custom_field_keys', '' ) ); ?></textarea>

            <?php submit_button( 'Save Settings' ); ?>
        </div>
        */ ?>

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
                        <input type="text" id="fse_ai_groq_model" name="fse_settings[ai_groq_model]" value="<?php echo esc_attr( fse_get_option( 'ai_groq_model', 'llama-3.3-70b-versatile' ) ); ?>" class="regular-text">
                        <p class="description">Default: llama-3.3-70b-versatile (stronger than the 8b-instant model, still fast on Groq's infra)</p>
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
                <tr>
                    <th scope="row">Log Failed AI Connections</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[ai_failure_logging_enabled]" value="1" <?php checked( fse_opt( 'ai_failure_logging_enabled' ) ); ?>>
                            Log AI vendor failures (timeouts, network errors, bad responses)
                        </label>
                        <p class="description">The AI layer fails silently by design (search just falls back to the rest of the plugin) — this makes those failures visible without changing that behavior. View logged failures under the AI Connection Log tab.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </div>
    </form>

    <!-- ── INSIGHTS TAB ── -->
    <div class="fse-tab-content" id="tab-insights">
        <?php $fse_zero_result_entries = class_exists( 'FSE_SearchLogger' ) ? FSE_SearchLogger::get_entries() : []; ?>
        <p>Search terms that returned <strong>zero results</strong>, most frequent first. Use these to spot missing synonyms, tags, or taxonomy terms.</p>

        <?php if ( empty( $fse_zero_result_entries ) ): ?>
            <p><em>No zero-result searches logged yet.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:640px;">
                <thead>
                    <tr>
                        <th>Search term</th>
                        <th>Times searched</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $fse_zero_result_entries as $entry ): ?>
                        <tr>
                            <td><?php echo esc_html( $entry['term'] ); ?></td>
                            <td><?php echo esc_html( $entry['count'] ); ?></td>
                            <td><?php echo esc_html( human_time_diff( $entry['last_seen'] ) . ' ago' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;">
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fse_clear_zero_result_log' ), 'fse_clear_zero_result_log' ) ); ?>"
                   class="button"
                   onclick="return confirm('Clear all logged zero-result search terms?');">
                    Clear Log
                </a>
            </p>
        <?php endif; ?>
    </div>

    <!-- ── COMPETITOR FALLBACK TAB ── -->
    <div class="fse-tab-content" id="tab-competitor-fallback">
        <form method="post" action="options.php">
            <?php settings_fields( 'fse_competitor_map_group' ); ?>
            <p>One mapping per line: <code>brand keyword => category slug</code>. When a search matches a brand keyword here and nothing else found any results, best-selling products from that category are shown instead.</p>
            <p class="description">
                Category slugs on this store include: <code>makeup</code>, <code>skin-care</code>, <code>face-care</code>, <code>body-care</code>, <code>bath</code>, <code>hair</code>, <code>baby-care</code>, <code>fragrance</code>, <code>mens-care</code>.
            </p>
            <?php
            $fse_competitor_map = get_option( 'fse_competitor_brand_map', [] );
            $fse_competitor_map = ( is_array( $fse_competitor_map ) && ! empty( $fse_competitor_map ) ) ? $fse_competitor_map : FSE_CompetitorFallback::DEFAULT_MAP;
            $fse_competitor_lines = [];
            foreach ( $fse_competitor_map as $fse_brand => $fse_slug ) {
                $fse_competitor_lines[] = "{$fse_brand} => {$fse_slug}";
            }
            ?>
            <textarea
                name="fse_competitor_brand_map"
                rows="14"
                class="large-text code"
            ><?php echo esc_textarea( implode( "\n", $fse_competitor_lines ) ); ?></textarea>

            <?php submit_button( 'Save Mapping' ); ?>
        </form>
    </div>

    <!-- ── PRIORITY MAPPING TAB ── -->
    <div class="fse-tab-content" id="tab-priority-mapping">
        <p>Map a searched keyword (competitor brand, product type, concern, or campaign keyword) to a specific product and a chain of categories. When matched, results are reordered into a fixed priority: the mapped product &rarr; other products from the same brand + main sub-category &rarr; the rest of the main sub-category &rarr; up to 4 relevant sub-categories, in order &rarr; the rest of the parent category &rarr; everything else.</p>
        <p class="description">"Product" accepts an exact product title or a numeric product ID. Every field is validated on save — invalid rows are rejected and listed below rather than silently saved wrong.</p>

        <div class="fse-priority-mapping-table-wrap" style="overflow-x:auto;">
            <table class="widefat fse-priority-mapping-table">
                <thead>
                    <tr>
                        <th style="min-width:140px;">Search Keyword</th>
                        <th style="min-width:200px;">Mapped Product</th>
                        <th style="min-width:150px;">Main Sub-category</th>
                        <th style="min-width:150px;">Relevant 1</th>
                        <th style="min-width:150px;">Relevant 2</th>
                        <th style="min-width:150px;">Relevant 3</th>
                        <th style="min-width:150px;">Relevant 4</th>
                        <th style="min-width:150px;">Parent Category</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="fse-priority-mapping-rows"></tbody>
            </table>
        </div>

        <p class="fse-priority-mapping-actions" style="margin-top:12px;">
            <button type="button" id="fse-add-priority-row" class="button">+ Add Row</button>
            <button type="button" id="fse-save-priority-mapping" class="button button-primary">Save All Rows</button>
            <span id="fse-priority-mapping-status" role="status" aria-live="polite"></span>
        </p>

        <div id="fse-priority-mapping-errors" style="display:none;margin-top:12px;padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638;"></div>
    </div>

    <!-- ── AI CONNECTION LOG TAB ── -->
    <div class="fse-tab-content" id="tab-ai-log">
        <?php $fse_ai_failures = class_exists( 'FSE_AIFailureLogger' ) ? FSE_AIFailureLogger::get_entries() : []; ?>
        <p>Failed AI vendor calls — timeouts, network errors, non-200 responses, or unparseable output. The AI layer fails open (search always falls back to the rest of the plugin), so these never affect what a shopper sees; this is purely to catch a vendor quietly failing on every request before it goes unnoticed.</p>

        <?php if ( empty( $fse_ai_failures ) ): ?>
            <p><em>No AI connection failures logged.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Vendor</th>
                        <th>Reason</th>
                        <th>HTTP Code</th>
                        <th>Search Term</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $fse_ai_failures as $fse_failure ): ?>
                        <tr>
                            <td><?php echo esc_html( human_time_diff( $fse_failure['time'] ) . ' ago' ); ?></td>
                            <td><?php echo esc_html( ucfirst( $fse_failure['vendor'] ) ); ?></td>
                            <td><?php echo esc_html( $fse_failure['reason'] ); ?></td>
                            <td><?php echo esc_html( $fse_failure['http_code'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $fse_failure['keyword'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;">
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fse_clear_ai_failure_log' ), 'fse_clear_ai_failure_log' ) ); ?>"
                   class="button"
                   onclick="return confirm('Clear all logged AI connection failures?');">
                    Clear Log
                </a>
            </p>
        <?php endif; ?>
    </div>

    <?php /* ── SYNONYMS TAB — commented out (synonym groups managed programmatically via DB, functionality still active) ──
    <div class="fse-tab-content" id="tab-synonyms">
        <p>
            Each row is a <strong>synonym group</strong>. Enter terms separated by commas.<br>
            Searching for any term in a group will also return products matching the other terms.
        </p>
        <p class="description">
            Example groups: <code>tv, television, screen, monitor</code> | <code>mobile, phone, smartphone, handphone</code>
        </p>

        <div id="fse-synonyms-list" class="fse-synonyms-list" aria-label="Synonym groups"></div>

        <div class="fse-synonyms-actions">
            <button type="button" id="fse-add-synonym" class="button">+ Add Synonym Group</button>
            <button type="button" id="fse-save-synonyms" class="button button-primary">Save All Groups</button>
            <span id="fse-synonym-status" role="status" aria-live="polite"></span>
        </div>
    </div>
    */ ?>
</div>
