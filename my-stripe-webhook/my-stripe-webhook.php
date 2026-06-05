<?php
/**
 * Plugin Name:       My Stripe Webhook Receiver
 * Plugin URI:        https://example.com/my-stripe-webhook
 * Description:        Receives Stripe subscription webhooks, verifies the Stripe-Signature (HMAC-SHA256) against the raw body, enforces idempotency, and reconciles WooCommerce Subscription renewals.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 *
 * @package My_Stripe_Webhook
 *
 * Configuration (wp-config.php):
 *   define( 'MY_STRIPE_WEBHOOK_SECRET', 'whsec_xxxxxxxxxxxxxxxxxxxxxxxx' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe webhook receiver.
 */
final class My_Stripe_Webhook {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'my-stripe/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/webhook';

	/**
	 * Idempotency table (without prefix).
	 */
	const TABLE = 'stripe_processed_events';

	/**
	 * Signature tolerance in seconds (replay-attack protection).
	 */
	const TOLERANCE = 300;

	/**
	 * Prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the idempotency table on activation.
	 */
	public static function activate(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// event_id is the PRIMARY KEY so the database itself rejects duplicates.
		$sql = "CREATE TABLE {$table} (
			event_id varchar(255) NOT NULL,
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
				/*
				 * Public route: Stripe cannot authenticate with WordPress
				 * cookies/nonces. Authenticity is enforced by the signature
				 * check in handle(), so __return_true is the correct callback.
				 */
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
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
		// Read the raw, unparsed body. The HMAC is computed over these exact
		// bytes — any reformatting would break verification.
		$raw_body = file_get_contents( 'php://input' );
		if ( '' === $raw_body || false === $raw_body ) {
			$raw_body = $request->get_body();
		}

		// Fail closed on misconfiguration; never disclose the secret.
		if ( ! defined( 'MY_STRIPE_WEBHOOK_SECRET' ) || '' === (string) MY_STRIPE_WEBHOOK_SECRET ) {
			return $this->json( array( 'message' => 'Webhook not configured.' ), 500 );
		}

		$signature = (string) $request->get_header( 'stripe-signature' );

		if ( '' === $signature || ! $this->verify_signature( $raw_body, $signature, MY_STRIPE_WEBHOOK_SECRET ) ) {
			return $this->json( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$event = json_decode( $raw_body, true );
		if ( null === $event || ! is_array( $event ) ) {
			return $this->json( array( 'message' => 'Malformed JSON payload.' ), 400 );
		}

		$type = isset( $event['type'] ) ? sanitize_text_field( (string) $event['type'] ) : '';

		// Only invoice.payment_succeeded is reconciled; everything else is acked.
		if ( 'invoice.payment_succeeded' !== $type ) {
			return $this->json( array( 'status' => 'ignored' ), 200 );
		}

		$event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		if ( '' === $event_id ) {
			return $this->json( array( 'message' => 'Missing event id.' ), 400 );
		}

		// Idempotency: skip business logic if we've seen this event before.
		if ( $this->already_processed( $event_id ) ) {
			return $this->json( array( 'status' => 'already_processed' ), 200 );
		}

		try {
			$result = $this->reconcile_renewal( $event );

			// Some outcomes are terminal but not "success" (e.g. 404). Only
			// record the event when we actually processed it.
			if ( isset( $result['status'] ) && 'ok' === $result['status'] ) {
				$this->mark_processed( $event_id );
			}

			return $this->json( $result['body'], $result['code'] );
		} catch ( Exception $e ) {
			error_log( 'Stripe webhook error (event ' . $event_id . '): ' . $e->getMessage() );
			return $this->json( array( 'message' => 'Internal error processing webhook.' ), 500 );
		}
	}

	/**
	 * Reconcile a WooCommerce Subscription renewal from the invoice event.
	 *
	 * @param array $event Decoded Stripe event.
	 * @return array { status, code, body }
	 * @throws Exception On unexpected failures.
	 */
	private function reconcile_renewal( array $event ): array {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return array( 'status' => 'error', 'code' => 500, 'body' => array( 'message' => 'WooCommerce Subscriptions is not active.' ) );
		}

		$invoice         = isset( $event['data']['object'] ) ? (array) $event['data']['object'] : array();
		$stripe_sub_id   = isset( $invoice['subscription'] ) ? sanitize_text_field( (string) $invoice['subscription'] ) : '';
		$stripe_inv_id   = isset( $invoice['id'] ) ? sanitize_text_field( (string) $invoice['id'] ) : '';

		if ( '' === $stripe_sub_id ) {
			return array( 'status' => 'error', 'code' => 400, 'body' => array( 'message' => 'Event is missing a subscription id.' ) );
		}

		$subscription_id = $this->find_subscription_id( $stripe_sub_id );
		if ( ! $subscription_id ) {
			return array( 'status' => 'error', 'code' => 404, 'body' => array( 'message' => 'No matching WooCommerce Subscription found.' ) );
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return array( 'status' => 'error', 'code' => 404, 'body' => array( 'message' => 'Subscription could not be loaded.' ) );
		}

		// Advance the next payment date to the end of the billed period.
		$period_end = isset( $invoice['lines']['data'][0]['period']['end'] )
			? absint( $invoice['lines']['data'][0]['period']['end'] )
			: 0;

		if ( $period_end > 0 ) {
			// update_dates() expects UTC MySQL datetime strings.
			$subscription->update_dates(
				array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $period_end ) )
			);
		}

		/* translators: %s: Stripe invoice ID. */
		$subscription->add_order_note( sprintf( __( 'Stripe renewal reconciled. Invoice: %s', 'my-stripe-webhook' ), $stripe_inv_id ) );

		return array(
			'status' => 'ok',
			'code'   => 200,
			'body'   => array(
				'status'          => 'ok',
				'subscription_id' => (int) $subscription_id,
			),
		);
	}

	/**
	 * Find the subscription whose _stripe_subscription_id matches.
	 *
	 * Prepared read against postmeta. (Swap for an HPOS-aware lookup if/when
	 * Subscriptions stores data in custom order tables.)
	 *
	 * @param string $stripe_sub_id Stripe subscription id.
	 * @return int
	 */
	private function find_subscription_id( string $stripe_sub_id ): int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_stripe_subscription_id',
				$stripe_sub_id
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Verify a Stripe-Signature header.
	 *
	 * Stripe signs `"{timestamp}.{raw_body}"`, exposing the timestamp as `t=`
	 * and one or more HMAC-SHA256 signatures as `v1=`. We recompute and compare
	 * with the constant-time hash_equals(), and reject stale timestamps.
	 *
	 * @param string $raw_body  Raw request body.
	 * @param string $header    Stripe-Signature header.
	 * @param string $secret    Webhook signing secret.
	 * @return bool
	 */
	private function verify_signature( string $raw_body, string $header, string $secret ): bool {
		$timestamp  = '';
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( '' === $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Replay protection: reject signatures outside the tolerance window.
		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );

		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Has this event id already been recorded?
	 *
	 * @param string $event_id Stripe event id.
	 * @return bool
	 */
	private function already_processed( string $event_id ): bool {
		global $wpdb;
		$table = self::table_name();

		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT event_id FROM `{$table}` WHERE event_id = %s", $event_id )
		);

		return null !== $found;
	}

	/**
	 * Record an event id as processed via $wpdb->insert() (auto-prepared).
	 *
	 * @param string $event_id Stripe event id.
	 */
	private function mark_processed( string $event_id ): void {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'event_id'     => $event_id,
				'processed_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Build a JSON REST response.
	 *
	 * @param array $body   Response body.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	private function json( array $body, int $status ): WP_REST_Response {
		return new WP_REST_Response( $body, $status );
	}
}

/**
 * Activation: create the idempotency table.
 */
register_activation_hook( __FILE__, array( 'My_Stripe_Webhook', 'activate' ) );

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	static function () {
		( new My_Stripe_Webhook() )->init();
	}
);
