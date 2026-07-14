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
                        <p class="description">e.g. searching "Red" finds all products with the attribute value "Red" even if the title says "T-Shirt".</p>
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
                    <th scope="row">Relevance Score Boost</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[score_boost_enabled]" value="1" <?php checked( fse_opt( 'score_boost_enabled' ) ); ?>>
                            <strong>Boost ranking</strong> for exact title matches, SKU matches, and variation SKU matches
                        </label>
                        <p class="description">Products with exact or SKU matches appear higher in the dropdown.</p>
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
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </div>
    </form>

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
