# WooCommerce Subscriptions Plugins

Two self-contained WooCommerce plugins.

| Plugin | Path | What it does |
|--------|------|--------------|
| My Stripe Webhook Receiver | `my-stripe-webhook/my-stripe-webhook.php` | Signed, idempotent Stripe webhook → subscription renewal reconciliation. |
| My Dunning Plugin | `my-dunning-plugin/` | Three-strike dunning for failed subscription renewals. |

---

## 1. My Stripe Webhook Receiver

```
POST /wp-json/my-stripe/v1/webhook
```

### Configuration

```php
// wp-config.php
define( 'MY_STRIPE_WEBHOOK_SECRET', 'whsec_xxxxxxxxxxxxxxxxxxxxxxxx' );
```

### Behaviour

1. **Signature** — reads the **raw** `php://input` body and verifies the `Stripe-Signature` header. Stripe signs `"{t}.{body}"`; the handler parses `t=`/`v1=`, recomputes `hash_hmac('sha256', "{t}.{body}", secret)`, compares with constant-time `hash_equals()`, and rejects timestamps outside a 300s window (replay protection). Invalid/missing → **HTTP 401**.
2. **Idempotency** — `invoice.payment_succeeded` event IDs are stored in `{prefix}stripe_processed_events` (`event_id VARCHAR(255)` PK, `processed_at DATETIME`), created on activation via `dbDelta()`. A known event → **HTTP 200** `{"status":"already_processed"}`, no business logic re-run. The event is recorded **after** successful reconciliation, so a mid-processing failure (500) lets Stripe retry.
3. **Renewal logic** — finds the subscription whose `_stripe_subscription_id` meta matches `data.object.subscription`, advances `next_payment` to `data.object.lines.data[0].period.end` via `$subscription->update_dates()`, and adds an order note with the Stripe invoice ID. Uses `wcs_get_subscription()`.
4. **Security** — public route (`__return_true`, documented: Stripe can't send WP auth); raw body read before parsing; the subscription lookup uses `$wpdb->prepare()` and the write uses `$wpdb->insert()`; no unescaped user data echoed.
5. **Errors** — `400` malformed JSON / missing IDs, `404` subscription not found, `500` unexpected, each with a `message` key. Non-renewal events → `200 {"status":"ignored"}`.

### Test request

```bash
SECRET="whsec_test"
BODY='{"id":"evt_1","type":"invoice.payment_succeeded","data":{"object":{"id":"in_1","subscription":"sub_123","lines":{"data":[{"period":{"start":1700000000,"end":1702592000}}]}}}}'
T=$(date +%s)
SIG=$(printf '%s' "$T.$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -X POST https://example.com/wp-json/my-stripe/v1/webhook \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: t=$T,v1=$SIG" \
  --data "$BODY"
```

---

## 2. My Dunning Plugin

```
my-dunning-plugin/
├── my-dunning-plugin.php            # header + bootstrap (My_Dunning_Plugin)
└── includes/class-dunning-manager.php  # Dunning_Manager
```

A **three-strike** sequence for failed subscription renewals.

1. **Strike tracking** — on `woocommerce_subscription_payment_failed`, increment `_renewal_failure_count` on the subscription.
2. **Scheduled retries** — after strike 1 and strike 2, schedule a one-off Action Scheduler action `my_dunning_retry_renewal` in **3 days** / **5 days**, passing the subscription ID (and the expected strike count).
3. **Retry callback** — loads the subscription; if still `on-hold`, fetches the last renewal order and calls `WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order )`. If no longer `on-hold`, it skips and logs a notice.
4. **Strike 3 — cancel** — no retry; `$subscription->update_status('cancelled')` (which fires `woocommerce_subscription_status_cancelled` automatically — not double-fired), plus an order note recording the final count.
5. **Race guard** — the retry compares the current `_renewal_failure_count` against the value captured at schedule time; if it changed, the retry is skipped (prevents double-charging when another process already advanced the strike).
6. **Code quality** — OOP (`My_Dunning_Plugin` + `Dunning_Manager`), escaped output, `WP_DEBUG`-gated logging via `wc_get_logger()`.

---

## Installation

Copy either plugin into `wp-content/plugins/` and activate it.
The Stripe receiver needs WooCommerce Subscriptions for renewal reconciliation; the dunning plugin requires WooCommerce Subscriptions.
