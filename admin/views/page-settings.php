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
        <button class="fse-tab" data-tab="custom-fields">Custom Fields</button>
        <button class="fse-tab" data-tab="synonyms">Synonyms / Related Words</button>
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
                    <th scope="row">Custom Field Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[custom_fields_enabled]" value="1" <?php checked( fse_opt( 'custom_fields_enabled' ) ); ?>>
                            Search inside configured <strong>custom meta fields</strong>
                        </label>
                        <p class="description">Configure which meta keys to search in the <em>Custom Fields</em> tab.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Synonym / Related Word Search</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fse_settings[synonyms_enabled]" value="1" <?php checked( fse_opt( 'synonyms_enabled' ) ); ?>>
                            Expand search to cover <strong>synonym groups</strong>
                        </label>
                        <p class="description">e.g. searching "tv" also returns products containing "television" or "monitor". Configure groups in the <em>Synonyms</em> tab.</p>
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

        <!-- ── CUSTOM FIELDS TAB ── -->
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
    </form>

    <!-- ── SYNONYMS TAB (AJAX-managed, separate from WP settings form) ── -->
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
</div>
