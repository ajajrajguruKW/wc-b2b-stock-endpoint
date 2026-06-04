# WC B2B Stock Endpoint

A small standalone WordPress plugin that registers a secure, read-only WooCommerce REST endpoint for B2B clients to query live stock data.

```
GET /wp-json/wc-b2b/v1/stock/<product_id>
```

## Installation

1. Copy the `wc-b2b-stock-endpoint` folder into `wp-content/plugins/`.
2. Activate **WC B2B Stock Endpoint** in **Plugins**. (WooCommerce must be active.)

## Response

On success the endpoint returns a JSON object:

```json
{
  "product_id": 123,
  "sku": "B2B-WIDGET-01",
  "stock_quantity": 42,
  "stock_status": "instock",
  "timestamp": "2026-06-04T10:15:30+00:00"
}
```

| Field            | Type            | Notes                                         |
|------------------|-----------------|-----------------------------------------------|
| `product_id`     | integer         | The product ID.                               |
| `sku`            | string          | Product SKU (empty string if none).           |
| `stock_quantity` | integer \| null | `null` when stock management is disabled.     |
| `stock_status`   | string          | e.g. `instock`, `outofstock`, `onbackorder`.  |
| `timestamp`      | string          | Generation time, ISO 8601 in **UTC**.         |

All values are cast to their correct types before being returned — no raw `get_post_meta` output is sent to the client. Data is read through WooCommerce CRUD getters (`wc_get_product()` / `WC_Product`).

## Authentication

The endpoint is **not** public. A request is authorised if **either** of these is true:

1. **Logged-in capability** — the current user holds the WooCommerce
   `view_woocommerce_reports` capability (Shop Managers and Administrators do by
   default). Useful for same-site/cookie-authenticated requests.

2. **WooCommerce REST API key** — the request supplies a valid
   `consumer_key` / `consumer_secret` pair generated under
   **WooCommerce → Settings → Advanced → REST API**. A **Read** permission key
   is sufficient.

   The custom namespace (`wc-b2b/v1`) is explicitly opted in to WooCommerce's
   key-authentication layer via the `woocommerce_rest_is_request_to_rest_api`
   filter, so consumer keys are honoured exactly as they are on the core
   `wc/v3` routes.

Any request that satisfies neither condition receives a `WP_Error` with
**HTTP 401**:

```json
{
  "code": "wc_b2b_unauthorized",
  "message": "Authentication required. Provide a WooCommerce API key or log in as a user with the view_woocommerce_reports capability.",
  "data": { "status": 401 }
}
```

A request for a non-existent / non-WooCommerce product returns **HTTP 404**
with code `wc_b2b_product_not_found`.

## Filter hook

Third-party code can extend the response payload before it is returned using
the `wc_b2b_stock_response` filter:

```php
add_filter( 'wc_b2b_stock_response', function ( array $response, WC_Product $product, WP_REST_Request $request ) {
	$response['backorders_allowed'] = $product->backorders_allowed();
	$response['price']              = (float) $product->get_price();
	return $response;
}, 10, 3 );
```

| Argument    | Type              | Description                       |
|-------------|-------------------|-----------------------------------|
| `$response` | `array`           | The response payload (modify it). |
| `$product`  | `WC_Product`      | The resolved product object.      |
| `$request`  | `WP_REST_Request` | The current request.              |

## Example request

Using HTTP Basic auth (recommended over HTTPS) with a WooCommerce API key:

```bash
curl https://example.com/wp-json/wc-b2b/v1/stock/123 \
  -u ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx:cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Equivalent using query-string credentials:

```bash
curl "https://example.com/wp-json/wc-b2b/v1/stock/123?consumer_key=ck_xxxx&consumer_secret=cs_xxxx"
```

> Always call the endpoint over **HTTPS** — key/secret authentication transmits
> credentials with the request.
