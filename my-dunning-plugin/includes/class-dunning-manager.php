<?php
/**
 * Dunning manager.
 *
 * @package My_Dunning_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the three-strike dunning sequence.
 */
class Dunning_Manager {

	/**
	 * Subscription meta key holding the failure count.
	 */
	const META_COUNT = '_renewal_failure_count';

	/**
	 * Action Scheduler hook used for retries.
	 */
	const RETRY_HOOK = 'my_dunning_retry_renewal';

	/**
	 * Action Scheduler group.
	 */
	const AS_GROUP = 'my-dunning';

	/**
	 * Logger source.
	 */
	const LOG_SOURCE = 'my-dunning';

	/**
	 * Strike at which the subscription is cancelled.
	 */
	const MAX_STRIKES = 3;

	/**
	 * Retry delays (in seconds) keyed by strike number.
	 *
	 * @return array<int,int>
	 */
	private function retry_delays(): array {
		return array(
			1 => 3 * DAY_IN_SECONDS, // After strike 1, retry in 3 days.
			2 => 5 * DAY_IN_SECONDS, // After strike 2, retry in 5 days.
		);
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_subscription_payment_failed', array( $this, 'on_payment_failed' ), 10, 1 );
		add_action( self::RETRY_HOOK, array( $this, 'retry_renewal' ), 10, 2 );
	}

	/**
	 * Handle a failed renewal payment: increment the strike count and either
	 * schedule a retry or cancel on the third strike.
	 *
	 * @param WC_Subscription|int $subscription Subscription (object or ID).
	 */
	public function on_payment_failed( $subscription ): void {
		$subscription = $this->resolve_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}

		// Increment the strike count (HPOS-safe CRUD meta).
		$count = (int) $subscription->get_meta( self::META_COUNT ) + 1;
		$subscription->update_meta_data( self::META_COUNT, $count );
		$subscription->save();

		$subscription_id = $subscription->get_id();
		$this->log( sprintf( 'Subscription #%d renewal failure — strike %d.', $subscription_id, $count ) );

		// Strike 3: cancel. The status change fires
		// woocommerce_subscription_status_cancelled automatically — we must
		// NOT call do_action() for it ourselves (that would double-fire).
		if ( $count >= self::MAX_STRIKES ) {
			$subscription->add_order_note(
				sprintf(
					/* translators: %d: total failure count. */
					esc_html__( 'Dunning: renewal failed %d times. Cancelling subscription.', 'my-dunning-plugin' ),
					$count
				)
			);
			$subscription->update_status( 'cancelled' );
			$this->log( sprintf( 'Subscription #%d cancelled after %d strikes.', $subscription_id, $count ), 'warning' );
			return;
		}

		// Strikes 1 & 2: schedule a one-off retry. The expected count is passed
		// so the retry can detect concurrent changes (see retry_renewal()).
		$delays = $this->retry_delays();
		if ( isset( $delays[ $count ] ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delays[ $count ],
				self::RETRY_HOOK,
				array( $subscription_id, $count ),
				self::AS_GROUP
			);
			$this->log( sprintf( 'Scheduled retry for subscription #%d in %d seconds (strike %d).', $subscription_id, $delays[ $count ], $count ) );
		}
	}

	/**
	 * Scheduled retry callback.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $expected_count  Strike count at schedule time.
	 */
	public function retry_renewal( $subscription_id, $expected_count = 0 ): void {
		$subscription_id = absint( $subscription_id );
		$expected_count  = absint( $expected_count );

		$subscription = $this->resolve_subscription( $subscription_id );
		if ( ! $subscription ) {
			$this->log( sprintf( 'Retry skipped: subscription #%d not found.', $subscription_id ), 'warning' );
			return;
		}

		// Race-condition guard: if the count moved since scheduling, another
		// process (a later failure, manual action, or duplicate run) has
		// already acted — skip to avoid double-charging.
		$current_count = (int) $subscription->get_meta( self::META_COUNT );
		if ( $current_count !== $expected_count ) {
			$this->log(
				sprintf( 'Retry skipped for #%d: failure count changed (expected %d, now %d).', $subscription_id, $expected_count, $current_count ),
				'notice'
			);
			return;
		}

		// Only retry while the subscription is still on-hold. If it was
		// reactivated manually it is no longer our concern.
		if ( 'on-hold' !== $subscription->get_status() ) {
			$this->log(
				sprintf( 'Retry skipped for #%d: status is "%s", not on-hold.', $subscription_id, $subscription->get_status() ),
				'notice'
			);
			return;
		}

		// Trigger the gateway to re-attempt the renewal on the last renewal order.
		$renewal_order = $subscription->get_last_order( 'all', 'renewal' );
		if ( ! $renewal_order instanceof WC_Order ) {
			$this->log( sprintf( 'Retry skipped for #%d: no renewal order found.', $subscription_id ), 'warning' );
			return;
		}

		if ( class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
			WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );
			$this->log( sprintf( 'Triggered gateway renewal retry for subscription #%d (order #%d).', $subscription_id, $renewal_order->get_id() ) );
		} else {
			$this->log( sprintf( 'Retry skipped for #%d: WC_Subscriptions_Payment_Gateways unavailable.', $subscription_id ), 'warning' );
		}
	}

	/**
	 * Normalise a subscription argument to an object.
	 *
	 * @param WC_Subscription|int $subscription Subscription or ID.
	 * @return WC_Subscription|false
	 */
	private function resolve_subscription( $subscription ) {
		if ( $subscription instanceof WC_Subscription ) {
			return $subscription;
		}

		if ( is_numeric( $subscription ) && function_exists( 'wcs_get_subscription' ) ) {
			$loaded = wcs_get_subscription( absint( $subscription ) );
			return $loaded ? $loaded : false;
		}

		return false;
	}

	/**
	 * WP_DEBUG-gated logging via the WooCommerce logger.
	 *
	 * @param string $message Message.
	 * @param string $level   Log level (info|notice|warning|error).
	 */
	private function log( string $message, string $level = 'info' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
