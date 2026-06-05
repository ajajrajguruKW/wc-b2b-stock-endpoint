<?php
/**
 * Plugin Name:       Razorpay Webhook Handler
 * Plugin URI:        https://example.com/razorpay-webhook-handler
 * Description:        Receives Razorpay payment webhooks, verifies the HMAC-SHA256 signature, enforces idempotency via a dedicated table, and transitions WooCommerce orders (HPOS-compatible).
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package Razorpay_Webhook_Handler
 *
 * Configuration (wp-config.php):
 *   define( 'RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Razorpay webhook handler.
 */
final class Razorpay_Webhook_Handler {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'myplugin/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/razorpay-webhook';

	/**
	 * Idempotency table (without prefix).
	 */
	const TABLE = 'razorpay_processed_events';

	/**
	 * Fully-qualified, prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the idempotency table on activation.
	 *
	 * dbDelta requires the very specific formatting below (two spaces after
	 * PRIMARY KEY, lowercase types, etc.).
	 */
	public static function activate(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// event_id is the PRIMARY KEY: the DB itself guarantees we can never
		// store the same event twice (defence-in-depth against races).
		$sql = "CREATE TABLE {$table} (
			event_id varchar(64) NOT NULL,
			payload longtext NOT NULL,
			processed_at datetime NOT NULL,
			PRIMARY KEY  (event_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Wire up hooks. Called from the bootstrap below — never at global scope.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST.
				'callback'            => array( $this, 'handle' ),
				/*
				 * Public by design: Razorpay cannot authenticate with WordPress
				 * credentials. Request authenticity is instead proven by the
				 * HMAC-SHA256 signature check inside handle(), so __return_true
				 * is the correct permission callback here.
				 */
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// Read the raw, unparsed body — the signature is computed over the
		// exact bytes Razorpay sent, so we must not let anything reformat it.
		$raw_body = file_get_contents( 'php://input' );
		if ( '' === $raw_body || false === $raw_body ) {
			$raw_body = $request->get_body();
		}

		// Misconfiguration: fail closed, never reveal the secret's presence.
		if ( ! defined( 'RAZORPAY_WEBHOOK_SECRET' ) || '' === (string) RAZORPAY_WEBHOOK_SECRET ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Webhook not configured.' ), 500 );
		}

		$signature = (string) $request->get_header( 'x-razorpay-signature' );

		// Missing or invalid signature -> 401. hash_equals is constant-time,
		// preventing signature-guessing via timing side-channels.
		if ( '' === $signature || ! $this->is_valid_signature( $raw_body, $signature ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Invalid signature.' ), 401 );
		}

		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Malformed payload.' ), 400 );
		}

		// event_id is required for idempotency.
		$event_id = isset( $data['event_id'] ) ? sanitize_text_field( (string) $data['event_id'] ) : '';
		if ( '' === $event_id ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Missing event_id.' ), 400 );
		}

		// Idempotency check: if we've already recorded this event_id, return
		// 200 immediately without re-processing side effects.
		if ( $this->already_processed( $event_id ) ) {
			return new WP_REST_Response( array( 'status' => 'already_processed' ), 200 );
		}

		try {
			$event  = isset( $data['event'] ) ? sanitize_text_field( (string) $data['event'] ) : '';
			$result = array( 'status' => 'ignored' );

			if ( 'payment.captured' === $event ) {
				$result = $this->handle_payment_captured( $data );
			}

			// Persist the event id AFTER handling so a failure mid-processing
			// (which throws below) does NOT mark the event done — Razorpay can
			// safely retry. INSERT IGNORE absorbs a duplicate from a race.
			$this->mark_processed( $event_id, $raw_body );

			return new WP_REST_Response( $result, 200 );
		} catch ( Exception $e ) {
			// Log the message only — never the webhook secret.
			error_log( 'Razorpay webhook error (event_id ' . $event_id . '): ' . $e->getMessage() );
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Internal error processing webhook.' ), 500 );
		}
	}

	/**
	 * Process a payment.captured event.
	 *
	 * @param array $data Decoded payload.
	 * @return array Response body.
	 * @throws Exception On unexpected order errors.
	 */
	private function handle_payment_captured( array $data ): array {
		$order_id = isset( $data['payload']['payment']['entity']['notes']['wc_order_id'] )
			? absint( $data['payload']['payment']['entity']['notes']['wc_order_id'] )
			: 0;

		if ( ! $order_id ) {
			return array( 'status' => 'error', 'message' => 'Missing wc_order_id.' );
		}

		// HPOS-compatible lookup.
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'status' => 'error', 'message' => 'Order not found.' );
		}

		// Skip if already there (status-level idempotency on top of event-level).
		if ( 'processing' === $order->get_status() ) {
			return array( 'status' => 'already_processed', 'order_id' => $order_id );
		}

		$order->update_status( 'processing', __( 'Payment captured via Razorpay webhook.', 'razorpay-webhook-handler' ) );
		$order->save();

		return array( 'status' => 'ok', 'order_id' => $order_id, 'new_status' => 'processing' );
	}

	/**
	 * Timing-safe HMAC-SHA256 signature validation.
	 *
	 * @param string $raw_body  Raw request body.
	 * @param string $signature Provided signature.
	 * @return bool
	 */
	private function is_valid_signature( string $raw_body, string $signature ): bool {
		$expected = hash_hmac( 'sha256', $raw_body, RAZORPAY_WEBHOOK_SECRET );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Has this event_id already been recorded?
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	private function already_processed( string $event_id ): bool {
		global $wpdb;
		$table = self::table_name();

		// Prepared statement — no interpolation of the user-supplied value.
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT event_id FROM `{$table}` WHERE event_id = %s", $event_id )
		);

		return null !== $found;
	}

	/**
	 * Record an event_id as processed.
	 *
	 * @param string $event_id Event ID.
	 * @param string $payload  Raw payload.
	 */
	private function mark_processed( string $event_id, string $payload ): void {
		global $wpdb;
		$table = self::table_name();

		// Prepared write. INSERT IGNORE so a concurrent duplicate is a no-op
		// rather than a primary-key error.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (event_id, payload, processed_at) VALUES (%s, %s, %s)",
				$event_id,
				$payload,
				current_time( 'mysql' )
			)
		);
	}
}

/**
 * Activation: create the idempotency table.
 */
register_activation_hook( __FILE__, array( 'Razorpay_Webhook_Handler', 'activate' ) );

/**
 * Bootstrap once WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'Razorpay Webhook Handler requires WooCommerce to be active.', 'razorpay-webhook-handler' )
						. '</p></div>';
				}
			);
			return;
		}

		( new Razorpay_Webhook_Handler() )->init();
	}
);
