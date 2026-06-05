<?php
/**
 * Plugin Name:       Razorpay Webhook Receiver
 * Plugin URI:        https://example.com/razorpay-webhook-receiver
 * Description:        Secure Razorpay webhook receiver. Validates the X-Razorpay-Signature header with HMAC-SHA256 (timing-safe) and transitions WooCommerce orders on payment.captured / payment.failed.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package Razorpay_Webhook_Receiver
 *
 * Configuration (wp-config.php):
 *   define( 'RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret_here' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves POST /mypay/v1/razorpay-webhook.
 */
final class Razorpay_Webhook_Receiver {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mypay/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/razorpay-webhook';

	/**
	 * Event -> target WooCommerce status map.
	 *
	 * @var array<string,string>
	 */
	private $event_map = array(
		'payment.captured' => 'processing',
		'payment.failed'   => 'failed',
	);

	/**
	 * Order statuses we never transition away from (already final).
	 *
	 * @var string[]
	 */
	private $terminal_statuses = array( 'completed', 'refunded' );

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
				 * Public by design: Razorpay cannot present WordPress
				 * credentials. Authenticity is enforced instead by validating
				 * the HMAC-SHA256 signature of the raw body against the shared
				 * webhook secret (see handle()). Hence __return_true is safe.
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
		// Read the raw body straight from the input stream, before any further
		// parsing. For application/json payloads php://input is re-readable;
		// fall back to the body the REST server already buffered if empty.
		$raw_body = file_get_contents( 'php://input' );
		if ( '' === $raw_body || false === $raw_body ) {
			$raw_body = $request->get_body();
		}

		// Misconfiguration guard — never leak whether/what the secret is.
		if ( ! defined( 'RAZORPAY_WEBHOOK_SECRET' ) || '' === (string) RAZORPAY_WEBHOOK_SECRET ) {
			return $this->respond( array( 'status' => 'error', 'message' => 'Webhook is not configured.' ), 500 );
		}

		$signature = (string) $request->get_header( 'x-razorpay-signature' );

		if ( '' === $signature || ! $this->is_valid_signature( $raw_body, $signature ) ) {
			return $this->respond( array( 'status' => 'error', 'message' => 'Invalid signature.' ), 401 );
		}

		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) || empty( $data['event'] ) ) {
			return $this->respond( array( 'status' => 'error', 'message' => 'Malformed payload.' ), 400 );
		}

		$event = sanitize_text_field( (string) $data['event'] );

		// Unhandled event types are acknowledged so Razorpay stops retrying.
		if ( ! isset( $this->event_map[ $event ] ) ) {
			return $this->respond( array( 'status' => 'ignored' ), 200 );
		}

		$target_status = $this->event_map[ $event ];

		$order_id = isset( $data['payload']['payment']['entity']['notes']['wc_order_id'] )
			? absint( $data['payload']['payment']['entity']['notes']['wc_order_id'] )
			: 0;

		if ( ! $order_id ) {
			return $this->respond( array( 'status' => 'error', 'message' => 'Missing wc_order_id in payload notes.' ), 422 );
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return $this->respond( array( 'status' => 'error', 'message' => 'Order not found.' ), 404 );
			}

			$current_status = $order->get_status(); // No "wc-" prefix.

			// Idempotency: already at target, or in a terminal state.
			if ( $current_status === $target_status
				|| in_array( $current_status, $this->terminal_statuses, true )
			) {
				return $this->respond( array( 'status' => 'already_processed' ), 200 );
			}

			// Record the Razorpay payment id for traceability (no secret here).
			$payment_id = isset( $data['payload']['payment']['entity']['id'] )
				? sanitize_text_field( (string) $data['payload']['payment']['entity']['id'] )
				: '';
			if ( '' !== $payment_id ) {
				$order->update_meta_data( '_razorpay_payment_id', $payment_id );
			}

			/* translators: %s: Razorpay event name. */
			$note = sprintf( __( 'Status updated via Razorpay webhook (%s).', 'razorpay-webhook-receiver' ), $event );
			$order->update_status( $target_status, $note );
			$order->save();

			return $this->respond(
				array(
					'status'     => 'ok',
					'order_id'   => (int) $order_id,
					'new_status' => $target_status,
				),
				200
			);
		} catch ( Exception $e ) {
			// Log the message (never the secret) and fail closed.
			error_log( 'Razorpay webhook processing error for order ' . $order_id . ': ' . $e->getMessage() );
			return $this->respond( array( 'status' => 'error', 'message' => 'Internal error while processing webhook.' ), 500 );
		}
	}

	/**
	 * Timing-safe HMAC-SHA256 signature validation.
	 *
	 * @param string $raw_body  Raw request body.
	 * @param string $signature Signature from the X-Razorpay-Signature header.
	 * @return bool
	 */
	private function is_valid_signature( string $raw_body, string $signature ): bool {
		$expected = hash_hmac( 'sha256', $raw_body, RAZORPAY_WEBHOOK_SECRET );

		// hash_equals performs a constant-time comparison.
		return hash_equals( $expected, $signature );
	}

	/**
	 * Build a JSON REST response with an explicit status code.
	 *
	 * @param array $body   Response body.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function respond( array $body, int $status ): WP_REST_Response {
		return new WP_REST_Response( $body, $status );
	}
}

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
						. esc_html__( 'Razorpay Webhook Receiver requires WooCommerce to be active.', 'razorpay-webhook-receiver' )
						. '</p></div>';
				}
			);
			return;
		}

		( new Razorpay_Webhook_Receiver() )->init();
	}
);
