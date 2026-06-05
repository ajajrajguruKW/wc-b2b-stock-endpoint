# WooCommerce Plugins

Two self-contained WooCommerce plugins.

| Plugin | Folder | What it does |
|--------|--------|--------------|
| Razorpay Webhook Handler | `razorpay-webhook-handler/` | HMAC-verified, idempotent Razorpay webhook receiver. |
| Multi-Vendor Order-Splitting Engine | `order-splitting-engine/` | Splits a multi-vendor order into per-vendor sub-orders. |

---

## 1. Razorpay Webhook Handler

```
POST /wp-json/myplugin/v1/razorpay-webhook
```

### Configuration

```php
// wp-config.php
define( 'RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here' );
```

### Behaviour

1. **Public route** — `permission_callback` is `__return_true` (documented in code): Razorpay can't send WP credentials; authenticity is the signature.
2. **Signature** — raw `php://input` body verified with `hash_hmac('sha256', $body, RAZORPAY_WEBHOOK_SECRET)`, compared via constant-time `hash_equals()`. Missing/invalid → **HTTP 401**.
3. **Idempotency** — each event's `event_id` is stored in `{prefix}razorpay_processed_events` (`event_id` VARCHAR(64) PK, `payload` LONGTEXT, `processed_at` DATETIME), created on activation with `dbDelta()`. A known `event_id` → **HTTP 200** `{"status":"already_processed"}` with no side effects. The event id is recorded **after** successful handling, so a mid-processing failure lets Razorpay safely retry.
4. **payment.captured** → order (from `payload.payload.payment.entity.notes.wc_order_id`) transitioned to `processing` via HPOS-compatible `wc_get_order()`.
5. **All DB access uses `$wpdb->prepare()`** — the SELECT and the `INSERT IGNORE` are both prepared; no raw interpolation of values (only the trusted table prefix).
6. Every path returns a JSON body (`ok` / `ignored` / `already_processed` / `error`).

### Test request

```bash
BODY='{"event_id":"evt_001","event":"payment.captured","payload":{"payment":{"entity":{"notes":{"wc_order_id":"456"}}}}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "your_webhook_secret_here" | sed 's/^.* //')
curl -X POST https://example.com/wp-json/myplugin/v1/razorpay-webhook \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $SIG" \
  --data "$BODY"
```

> Note: the documented payload path `payload.payload.payment.entity.notes.wc_order_id`
> is read relative to the decoded JSON root as `payload.payment.entity.notes.wc_order_id`
> (the outer key in the JSON body is itself named `payload`).

---

## 2. Multi-Vendor Order-Splitting Engine

Products carry their vendor in product meta `_vendor_id` (a WP user ID). When an order spans multiple vendors, it is split into one **sub-order per vendor**.

### Behaviour

1. **Hook** — runs on `woocommerce_order_status_pending` and `woocommerce_order_status_on-hold` (order created, payment pending/on-hold).
2. **Grouping** — line items grouped by each product's `_vendor_id` (read via `WC_Product::get_meta()`). Unassigned products stay on the parent. Splits only when **≥ 2 vendors** are present.
3. **Sub-orders** — created with `wc_create_order()` (never `wp_insert_post()`). Each copies:
   - billing & shipping addresses,
   - only that vendor's line items (pricing/tax preserved),
   - a **proportional** shipping share (weighted by line-item value),
   - `_parent_order_id` meta referencing the parent.
4. Each sub-order starts as **`pending`**.
5. An **order note** on the parent lists the created sub-order IDs.
6. **Double-split guard** — `_sub_orders_created` meta on the parent; if set, the function does nothing. Sub-orders carry `_parent_order_id`, so they never re-trigger the split.
7. **HPOS-compatible throughout** — `wc_get_order()`, `wc_create_order()`, `$order->get_meta()`, `update_meta_data()`, `save()`, order items API. No direct `$wpdb` or `update_post_meta()`.

---

## Installation

Copy either plugin folder into `wp-content/plugins/` and activate it. WooCommerce must be active.
