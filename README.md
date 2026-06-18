# WC Shipping Info Tab

A single-file WordPress plugin that adds a **"Shipping Info"** tab to every WooCommerce single product page, driven by per-product content stored in the `_custom_shipping_info` meta.

File: `wc-shipping-info-tab/wc-shipping-info-tab.php`

## What it does

1. **Front-end tab** — registers a "Shipping Info" tab via `woocommerce_product_tabs` (priority 25, between Description and Reviews).
2. **Content + fallback** — shows the product's `_custom_shipping_info` meta. If empty/unset, displays the fallback **"No shipping information available."**
3. **Admin editor** — adds a "Shipping Info" tab to the product data metabox (`woocommerce_product_data_tabs` + `woocommerce_product_data_panels`) with a textarea to enter/save the value.
4. **Escaping** — front-end output is escaped with `esc_html()` (then `nl2br()` for line breaks) and `esc_html__()`.
5. **Secure save** — `woocommerce_process_product_meta` handler verifies a dedicated nonce (`wc_csi_save_meta` / `wc_csi_nonce`) **first**, checks `edit_post` capability, sanitises with `sanitize_textarea_field()`, and persists via the WooCommerce CRUD API.

## Standards & safety

- Unique function prefix `wc_csi_`.
- Internationalised with text domain **`wc-csi`**.
- Complete plugin header (Plugin Name, Description, Version, Author, Text Domain).
- Self-contained, no external libraries.

## How to test

1. Activate the plugin (WooCommerce active).
2. Edit a product → **Product data** metabox → **Shipping Info** tab → enter text and update.
3. View the product page → the **Shipping Info** tab shows your text.
4. Clear the field and update → the tab shows *"No shipping information available."*

## Installation

Copy the `wc-shipping-info-tab/` folder into `wp-content/plugins/` and activate it.
