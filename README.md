# MyStore Payment Webhook

A standalone WordPress plugin that receives payment-gateway webhooks, validates their HMAC-SHA256 signature, enforces idempotency, and updates the matching WooCommerce order.

```
POST /wp-json/mystore/v1/payment-webhook
```

File: `mystore-payment-webhook/mystore-payment-webhook.php`

## Configuration

Store the shared secret in the `mystore_webhook_secret` option:

```php
update_option( 'mystore_webhook_secret', 'your_shared_secret_here' );
```

The secret is never echoed or logged.

## Behaviour

1. **Signature validation** — reads the **raw** `php://input` body and verifies the `X-Webhook-Signature` header, which must equal the hex-encoded `hash_hmac('sha256', $body, $secret)`. Comparison uses constant-time `hash_equals()`. Missing/invalid → **HTTP 401**.
2. **Idempotency** — the JSON body carries `{ "event_id", "order_id", "status" }`. Processed `event_id`s are stored in `{prefix}mystore_processed_events` (`event_id VARCHAR(128)` PK, `processed_at DATETIME`), created on activation via `dbDelta()`. A known event → **HTTP 200** `{"result":"already_processed"}` with no reprocessing.
3. **Order update** — when `status` is `paid`, the WooCommerce order is moved to `processing` and an order note records the `event_id`.
4. **Success** — returns **HTTP 200** `{"result":"ok"}`.

### Error responses

| Case | Status | Body |
|------|--------|------|
| Invalid / missing signature | 401 | `{"result":"error", ...}` |
| Malformed JSON / bad event_id | 400 | `{"result":"error", ...}` |
| Order not found (paid event) | 404 | `{"result":"error", ...}` |
| Secret not configured / unexpected | 500 | `{"result":"error", ...}` |

## Security & code quality

- `hash_equals()` for signature comparison (timing-safe).
- All inputs sanitized (`sanitize_text_field`, `absint`, `sanitize_key`) and validated before use.
- Custom-table reads and writes use `$wpdb->prepare()`; the insert is `INSERT IGNORE` to survive races.
- Public route via `register_rest_route()` with `__return_true` (documented: signature replaces auth).
- WordPress Coding Standards: snake_case prefixed functions, proper activation/REST hooks.
- The event is recorded **after** the order side effects succeed, so a failure lets the gateway safely retry.

## Test request

```bash
SECRET="your_shared_secret_here"
BODY='{"event_id":"evt_001","order_id":123,"status":"paid"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -X POST https://example.com/wp-json/mystore/v1/payment-webhook \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: $SIG" \
  --data "$BODY"
```

## Installation

Copy the `mystore-payment-webhook/` folder into `wp-content/plugins/` and activate it. WooCommerce must be active for order updates.
