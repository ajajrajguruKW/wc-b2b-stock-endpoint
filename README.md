# B2B MOQ Enforcement

A single-file WordPress plugin that enforces a per-product **Minimum Order Quantity (MOQ)** for wholesale (B2B) customers, using native WooCommerce hooks only — no external libraries.

File: `b2b-moq-enforcement/b2b-moq-enforcement.php`

## What it does

1. **Product field** — adds a number input **"B2B Minimum Order Quantity"** to the product **General** tab (`woocommerce_product_options_general_product_data`), stored in the `_b2b_moq` meta. Saved on `woocommerce_process_product_meta`, sanitised with `absint()` and persisted via the WooCommerce CRUD API (`$product->update_meta_data()` / `save()`).
2. **B2B gating** — MOQ rules apply **only** to users with the `b2b_customer` role. Any other user (including guests) is silently skipped.
3. **Cart validation** — on `woocommerce_check_cart_items`, every cart line item whose product has `_b2b_moq > 0` is checked. If the cart quantity is below the MOQ, an error notice via `wc_add_notice()` names the product and the required minimum, blocking checkout.

## Standards & safety

- Unique function prefix `b2b_moq_`.
- All user-facing strings internationalised with text domain **`b2b-moq`** (loaded via `load_plugin_textdomain`).
- Dynamic values escaped (`esc_html()`, `esc_html__()`).
- Save sanitised to a non-negative integer; WooCommerce verifies its product-data nonce before the save hook fires.
- Complete plugin header (Plugin Name, Description, Version, Author, Text Domain).
- Pure WordPress/WooCommerce — no external dependencies.

## How to test

1. Ensure a `b2b_customer` role exists and assign it to a test user.
2. Activate the plugin (WooCommerce active).
3. Edit a product → **General** tab → set **B2B Minimum Order Quantity** to e.g. `12` and update.
4. As the `b2b_customer` user, add **5** of that product and go to the cart → blocked with: *"<Product> has a B2B minimum order quantity of 12. You currently have 5 in your cart."*
5. As a normal customer (or guest), the same cart proceeds with no MOQ error.

## Installation

Copy the `b2b-moq-enforcement/` folder into `wp-content/plugins/` and activate it.
