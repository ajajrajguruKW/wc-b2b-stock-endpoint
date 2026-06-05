# WooCommerce B2B Plugins

Two self-contained WooCommerce plugins.

| Plugin | Folder | What it does |
|--------|--------|--------------|
| Role-Based Tiered Pricing Engine | `role-based-tiered-pricing/` | Per-product, role- and quantity-based pricing applied at the cart. |
| WC B2B Velocity Endpoint | `wc-b2b-velocity-endpoint/` | Secure REST endpoint exposing 30-day sales velocity per product. |

---

## 1. Role-Based Tiered Pricing Engine

Define pricing tiers per WooCommerce simple product. Each tier targets a customer **role** and a **minimum quantity**. At cart-calculation time the plugin applies the **lowest matching tier price** for the logged-in user's role and the item quantity. If no tier matches, the product's normal price is left untouched.

### Data model

Tiers are stored as a JSON array in the `_tiered_pricing_rules` product meta field:

```json
[
  { "role": "wholesale_customer", "min_qty": 10, "price": "8.50" },
  { "role": "wholesale_customer", "min_qty": 50, "price": "7.25" }
]
```

### Admin UI

A **Role-Based Tiered Pricing** meta box appears on the product edit screen with a plain PHP/HTML repeater (no React): pick a role, set a minimum quantity, set a price, add/remove rows.

### Security

- **Nonce** (`rbtp_save_rules`) verified as the first operation on save.
- **Capability** check: `current_user_can( 'edit_post', $post_id )`.
- **Sanitisation on save**: `role` whitelisted against `wp_roles()->get_names()`, `min_qty` via `absint()`, `price` via `floatval()` (then normalised to the store's decimal precision).
- **Output escaping**: every admin field uses `esc_attr` / `esc_html`; the JS row template is passed through `wp_json_encode`.

### Matching logic (`woocommerce_before_calculate_totals`)

For each cart item: collect tiers whose `role` is one of the current user's roles **and** whose `min_qty` ≤ item quantity; apply the lowest such price via `WC_Product::set_price()`. Guarded against admin context and repeat firings of the hook.

---

## 2. WC B2B Velocity Endpoint

```
GET /wp-json/wc-b2b/v1/products/<id>/velocity
```

Returns 30-day sales velocity for a product.

### Response

```json
{
  "product_id": 123,
  "product_name": "B2B Widget",
  "units_sold_30d": 420,
  "gross_revenue_30d": 3570.00,
  "currency": "USD"
}
```

`units_sold_30d` is an integer, `gross_revenue_30d` a float — both type-cast before returning.

### Authentication & authorisation

| Condition | Result |
|-----------|--------|
| Not authenticated | `WP_Error` code `rest_forbidden`, **HTTP 401** |
| Authenticated, missing `view_b2b_velocity` capability | `WP_Error` code `rest_unauthorized`, **HTTP 403** |
| Authenticated with `view_b2b_velocity` | `200 OK` |
| Product missing / not a WC product | `WP_Error` code `rest_product_invalid`, **HTTP 404** |

The custom `view_b2b_velocity` capability is granted to `administrator` and `shop_manager` on activation. The `wc-b2b/v1` namespace is opted in to WooCommerce's API-key authentication via the `woocommerce_rest_is_request_to_rest_api` filter, so consumer key/secret credentials work.

### Data source

Units sold and gross revenue are summed from the WooCommerce analytics lookup table `{$wpdb->prefix}wc_order_product_lookup` over the last 30 days. **All user-supplied values are bound with `$wpdb->prepare`** — no raw interpolation into SQL.

### Filter hook

```php
add_filter( 'wc_b2b_velocity_response', function ( array $response, WC_Product $product, WP_REST_Request $request ) {
	$response['avg_units_per_day'] = round( $response['units_sold_30d'] / 30, 2 );
	return $response;
}, 10, 3 );
```

### Example request

```bash
curl https://example.com/wp-json/wc-b2b/v1/products/123/velocity \
  -u ck_xxxxxxxxxxxxxxxxxxxxxxxx:cs_xxxxxxxxxxxxxxxxxxxxxxxx
```

> Call over **HTTPS** — API-key credentials travel with the request.

---

## Installation

Copy either plugin folder into `wp-content/plugins/` and activate it. WooCommerce must be active.
