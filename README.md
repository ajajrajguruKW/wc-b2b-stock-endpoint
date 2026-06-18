# SpiceRoute Wholesale — MOQ Enforcement

A single-file WordPress plugin that enforces a per-product **Minimum Order Quantity (MOQ)** for a B2B WooCommerce store. MOQ data comes entirely from the ACF field `moq_quantity` — there is no settings UI and nothing is hardcoded.

File: `srw-moq-enforcement/srw-moq-enforcement.php`

## What it does

1. **Add-to-cart guard** — `woocommerce_add_to_cart_validation` (priority 10): if the quantity being added is below the product's MOQ, an error notice is shown via `wc_add_notice()` and the add is blocked.
2. **Cart / checkout guard** — `woocommerce_check_cart_items` (priority 10): every line item is re-validated. If any item's quantity is below its MOQ, an error naming the product and its required MOQ is added, which blocks checkout. This also catches quantities edited on the cart page or items added before the plugin was active.

## MOQ source

- Read with **`get_field( 'moq_quantity', $product_id )`** (ACF).
- If the field is empty, unset, or ACF is not active, the MOQ is treated as **1** (no restriction).
- The value is `absint()`-ed and floored to a minimum of 1.

## Standards & safety

- Unique function prefix `srw_moq_`.
- No direct DB calls — MOQ comes from ACF; cart data from `WC()->cart`.
- Uses `WC()` helpers (no `global $woocommerce`).
- All dynamic values escaped: product names via `esc_html()`, notice strings via `esc_html__()`.
- Correct hook priorities and the full `woocommerce_add_to_cart_validation` signature (6 args).

## How to test

1. Activate the plugin (WooCommerce + ACF active).
2. On a product, set the ACF field `moq_quantity` to e.g. `10`.
3. Try adding **5** of that product → blocked with: *"<Product> has a minimum order quantity of 10…"*.
4. Add **10** → succeeds. Then on the cart page lower it to **3** and go to checkout → checkout is blocked with a per-item error.
5. A product with no `moq_quantity` set behaves normally (MOQ = 1).

## Installation

Copy the `srw-moq-enforcement/` folder into `wp-content/plugins/` and activate it.
