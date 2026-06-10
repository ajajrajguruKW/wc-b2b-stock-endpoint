# WC Enrollment Confirmed Status

A single-file WordPress plugin for WooCommerce + LearnDash stores. It adds a custom order status **"Enrollment Confirmed"** that, when applied to an order, automatically enrols the purchasing customer into every LearnDash course linked to the ordered products.

File: `wc-enrol-confirmed/wc-enrol-confirmed.php`

## What it does

1. **Registers** a custom order status with slug `wc-enrol-confirmed` and label **"Enrollment Confirmed"** (`register_post_status` on `init`).
2. **Adds** it to the WooCommerce order-status list (`wc_order_statuses` filter) so it shows in the admin **Order actions / status** dropdown — inserted right after *Processing*.
3. **Enrols on transition** — hooked to `woocommerce_order_status_changed` (signature `$order_id, $old_status, $new_status, $order`). When an order moves **to** `enrol-confirmed`, it:
   - loops every line item,
   - reads each product's linked course from post meta `_linked_course_id` (ACF / custom field),
   - enrols the order's customer (by user ID) via `ld_update_course_access( $user_id, $course_id )`,
   - adds an order note listing the enrolled course IDs.

> **Prefix note:** `woocommerce_order_status_changed` passes statuses **without** the `wc-` prefix, so the handler compares against the bare slug `enrol-confirmed` (the status is *registered* as `wc-enrol-confirmed`).

## Assumptions

- Each WooCommerce product that grants course access stores the LearnDash course ID in post meta `_linked_course_id`.
- LearnDash is active (provides `ld_update_course_access()`).

## Safety / standards

- **No `global $woocommerce`** — uses `$order` / `wc_get_order()` helpers only.
- Guest orders (no customer user ID) are skipped with an explanatory order note (no fatal).
- If LearnDash is inactive, enrolment is skipped with a note (no fatal).
- Course IDs are deduped across line items.
- All IDs run through `absint()`; the order note text is escaped with `esc_html__()` / `esc_html()`.
- WordPress Coding Standards: snake_case `wc_enrol_`-prefixed functions, tab indentation, Yoda conditions.

## How to test

1. Activate the plugin (WooCommerce + LearnDash active).
2. On a product, set the post meta / ACF field `_linked_course_id` to a valid LearnDash course ID.
3. Place an order for that product as a **registered** customer.
4. In the admin, change the order status to **Enrollment Confirmed** and save.
5. Confirm:
   - the customer now has access to the LearnDash course, and
   - the order shows a note like *"Enrollment Confirmed: user #42 enrolled into LearnDash course(s): 101, 205."*

## Installation

Copy the `wc-enrol-confirmed/` folder into `wp-content/plugins/` and activate it.
