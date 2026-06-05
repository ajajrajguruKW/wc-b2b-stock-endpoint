# WooCommerce REST Plugins

Two self-contained WooCommerce plugins.

| Plugin | Folder | What it does |
|--------|--------|--------------|
| Razorpay Webhook Receiver | `razorpay-webhook-receiver/` | Verifies Razorpay webhooks (HMAC-SHA256) and transitions orders. |
| Customer Order Summary Endpoint | `customer-order-summary/` | Authenticated per-customer order summary, cached. |

---

## 1. Razorpay Webhook Receiver

```
POST /wp-json/mypay/v1/razorpay-webhook
```

### Configuration

Add the webhook secret to `wp-config.php`:

```php
define( 'RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here' );
```

The secret is **never** echoed or logged.

### Flow

1. **Public route** — `permission_callback` is `__return_true` (documented in code): Razorpay can't send WP credentials, so authenticity is enforced by signature, not auth.
2. Raw body is read from `php://input` **before** further parsing.
3. `X-Razorpay-Signature` is validated with `hash_hmac('sha256', $body, RAZORPAY_WEBHOOK_SECRET)` compared via `hash_equals()` (**timing-safe**).
4. Invalid signature → **HTTP 401** JSON error.
5. Valid → JSON decoded, order ID read from `payload.payment.entity.notes.wc_order_id`:
   - `payment.captured` → order to **processing**
   - `payment.failed` → order to **failed**
6. Any other event → **HTTP 200** `{"status":"ignored"}`.
7. **Idempotency** — if the order is already in the target status, or in a terminal state (`completed` / `refunded`), no transition occurs → `{"status":"already_processed"}`.
8. All meta writes / transitions are wrapped in `try/catch` → **HTTP 500** on unexpected exceptions.

### Test request

```bash
BODY='{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_123","notes":{"wc_order_id":"456"}}}}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "your_webhook_secret_here" | sed 's/^.* //')
curl -X POST https://example.com/wp-json/mypay/v1/razorpay-webhook \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $SIG" \
  --data "$BODY"
```

---

## 2. Customer Order Summary Endpoint

```
GET /wp-json/mystore/v1/customers/{customer_id}/order-summary
```

### Response

```json
{
  "customer_id": 42,
  "total_orders": 17,
  "total_spent": "3420.00",
  "statuses": { "completed": 12, "processing": 3, "cancelled": 2 },
  "last_order_date": "2024-11-15T09:22:00+05:30"
}
```

- Counts are integers, `total_spent` is a decimal string, `last_order_date` is ISO-8601 in the site timezone (`wp_timezone()`), or `null` if no orders.

### Authorisation

- Requires a logged-in user (else **HTTP 401**).
- Allowed if the user has `manage_woocommerce` **or** is requesting their own `customer_id`; otherwise **HTTP 403**.

### Implementation notes

- Order data comes from `wc_get_orders()` (no direct `$wpdb`); aggregation is done in PHP.
- `customer_id` is validated as a **positive integer** in the route `args`.
- The route registers a **`schema`** callback describing the response.
- Results are cached in a transient (`mystore_order_summary_<id>`) with a **5-minute TTL**, invalidated on `woocommerce_order_status_changed` for the affected customer.

### Test request

```bash
curl https://example.com/wp-json/mystore/v1/customers/42/order-summary \
  --cookie "$(cat wp-cookies.txt)"
```

---

## Installation

Copy either plugin folder into `wp-content/plugins/` and activate it. WooCommerce must be active.
