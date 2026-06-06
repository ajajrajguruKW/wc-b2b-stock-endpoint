<?php
/**
 * Plugin Name:       MyStore Payment Webhook
 * Plugin URI:        https://example.com/mystore-payment-webhook
 * Description:        Secure payment-gateway webhook receiver: HMAC-SHA256 signature validation, idempotency via a custom table, and WooCommerce order updates.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package MyStore_Payment_Webhook
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name holding the shared webhook secret.
 */
define( 'MYSTORE_WEBHOOK_SECRET_OPTION', 'mystore_webhook_secret' );

/**
 * Custom table (without prefix) tracking processed event IDs.
 */
define( 'MYSTORE_PROCESSED_EVENTS_TABLE', 'mystore_processed_events' );

/**
 * Return the prefixed processed-events table name.
 *
 * @return string
 */
function mystore_processed_events_table() {
	global $wpdb;
	return $wpdb->prefix . MYSTORE_PROCESSED_EVENTS_TABLE;
}

/**
 * Create the idempotency table on activation.
 *
 * dbDelta is intentionally strict about formatting (two spaces after
 * PRIMARY KEY, lowercase column types).
 *
 * @return void
 */
function mystore_webhook_activate() {
	global $wpdb;

	$table           = mystore_processed_events_table();
	$charset_collate = $wpdb->get_charset_collate();

	// event_id is the PRIMARY KEY, so the database guarantees each event can be
	// recorded only once — defence in depth against concurrent deliveries.
	$sql = "CREATE TABLE {$table} (
		event_id varchar(128) NOT NULL,
		processed_at datetime NOT NULL,
		PRIMARY KEY  (event_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'mystore_webhook_activate' );

/**
 * Register the webhook REST route.
 *
 * @return void
 */
function mystore_register_webhook_route() {
	register_rest_route(
		'mystore/v1',
		'/payment-webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE, // POST.
			'callback'            => 'mystore_handle_payment_webhook',
			/*
			 * Webhooks originate from the payment gateway, which cannot present
			 * WordPress credentials. Authentication is therefore replaced by the
			 * HMAC-SHA256 signature check inside the callback, so it is correct
			 * for the permission callback to allow the request through.
			 */
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'mystore_register_webhook_route' );

/**
 * Handle an incoming payment webhook.
 *
 * @param WP_REST_Request $request Current request.
 * @return WP_REST_Response
 */
function mystore_handle_payment_webhook( WP_REST_Request $request ) {
	// Read the raw, unparsed body — the signature is computed over these exact
	// bytes, so it must not be reformatted by any parser.
	$raw_body = file_get_contents( 'php://input' );
	if ( '' === $raw_body || false === $raw_body ) {
		$raw_body = $request->get_body();
	}

	$secret = (string) get_option( MYSTORE_WEBHOOK_SECRET_OPTION, '' );

	// Fail closed if no secret is configured. Never disclose the secret.
	if ( '' === $secret ) {
		return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Webhook secret not configured.' ), 500 );
	}

	$signature = (string) $request->get_header( 'x-webhook-signature' );

	// Reject missing or invalid signatures.
	if ( '' === $signature || ! mystore_verify_signature( $raw_body, $signature, $secret ) ) {
		return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Invalid signature.' ), 401 );
	}

	$data = json_decode( $raw_body, true );
	if ( null === $data || ! is_array( $data ) ) {
		return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Malformed JSON body.' ), 400 );
	}

	// Sanitize and validate every input before use.
	$event_id = isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '';
	$order_id = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
	$status   = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';

	if ( '' === $event_id || strlen( $event_id ) > 128 ) {
		return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Missing or invalid event_id.' ), 400 );
	}

	// Idempotency: never reprocess an event we have already handled.
	if ( mystore_is_event_processed( $event_id ) ) {
		return new WP_REST_Response( array( 'result' => 'already_processed' ), 200 );
	}

	try {
		// Apply the order update only when the gateway reports a paid status.
		if ( 'paid' === $status ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return new WP_REST_Response( array( 'result' => 'error', 'message' => 'WooCommerce is not active.' ), 500 );
			}

			if ( ! $order_id ) {
				return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Missing order_id.' ), 400 );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Order not found.' ), 404 );
			}

			// Update status only if not already there, and record the event id.
			if ( 'processing' !== $order->get_status() ) {
				$order->update_status(
					'processing',
					sprintf(
						/* translators: %s: webhook event ID. */
						__( 'Payment confirmed via webhook (event %s).', 'mystore-payment-webhook' ),
						$event_id
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: webhook event ID. */
						__( 'Payment webhook received for already-processing order (event %s).', 'mystore-payment-webhook' ),
						$event_id
					)
				);
			}
			$order->save();
		}

		// Mark the event processed AFTER the side effects succeed, so a failure
		// above lets the gateway safely retry.
		mystore_mark_event_processed( $event_id );

		return new WP_REST_Response( array( 'result' => 'ok' ), 200 );
	} catch ( Exception $e ) {
		error_log( 'MyStore webhook error (event ' . $event_id . '): ' . $e->getMessage() );
		return new WP_REST_Response( array( 'result' => 'error', 'message' => 'Internal error processing webhook.' ), 500 );
	}
}

/**
 * Constant-time HMAC-SHA256 signature validation.
 *
 * @param string $raw_body  Raw request body.
 * @param string $signature Hex-encoded signature from X-Webhook-Signature.
 * @param string $secret    Shared secret.
 * @return bool
 */
function mystore_verify_signature( $raw_body, $signature, $secret ) {
	$expected = hash_hmac( 'sha256', $raw_body, $secret );

	// hash_equals performs a constant-time comparison, preventing timing attacks.
	return hash_equals( $expected, $signature );
}

/**
 * Has this event_id already been recorded?
 *
 * @param string $event_id Event ID.
 * @return bool
 */
function mystore_is_event_processed( $event_id ) {
	global $wpdb;
	$table = mystore_processed_events_table();

	// Prepared read — no interpolation of the user-supplied value.
	$found = $wpdb->get_var(
		$wpdb->prepare( "SELECT event_id FROM `{$table}` WHERE event_id = %s", $event_id )
	);

	return null !== $found;
}

/**
 * Record an event_id as processed.
 *
 * @param string $event_id Event ID.
 * @return void
 */
function mystore_mark_event_processed( $event_id ) {
	global $wpdb;
	$table = mystore_processed_events_table();

	// Prepared write. INSERT IGNORE makes a duplicate from a race a no-op
	// instead of a primary-key error.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO `{$table}` (event_id, processed_at) VALUES (%s, %s)",
			$event_id,
			current_time( 'mysql' )
		)
	);
}
