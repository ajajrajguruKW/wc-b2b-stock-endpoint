<?php
/**
 * Plugin Name:       Customer Order Summary Endpoint
 * Plugin URI:        https://example.com/customer-order-summary
 * Description:        Authenticated REST endpoint returning a per-customer order summary (order count, total spent, status breakdown, last order date). Cached in a 5-minute transient and invalidated on order status changes.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package Customer_Order_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves GET /mystore/v1/customers/<id>/order-summary.
 */
final class Customer_Order_Summary {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mystore/v1';

	/**
	 * Transient key prefix.
	 */
	const TRANSIENT_PREFIX = 'mystore_order_summary_';

	/**
	 * Cache TTL (5 minutes).
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Wire up hooks. Called from the bootstrap below — never at global scope.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Invalidate the cache whenever an order changes status.
		add_action( 'woocommerce_order_status_changed', array( $this, 'flush_cache' ), 10, 4 );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/customers/(?P<customer_id>[\d]+)/order-summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'customer_id' => array(
							'description'       => __( 'WooCommerce customer (user) ID.', 'customer-order-summary' ),
							'type'              => 'integer',
							'required'          => true,
							// Validate as a positive integer.
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
							'sanitize_callback' => 'absint',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Permission gate.
	 *
	 *  - Not logged in                       -> 401.
	 *  - Logged in, not own id & no manage_woocommerce -> 403.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access this resource.', 'customer-order-summary' ),
				array( 'status' => 401 )
			);
		}

		$customer_id = absint( $request['customer_id'] );

		// Managers can read anyone; everyone else only their own summary.
		if ( current_user_can( 'manage_woocommerce' ) || get_current_user_id() === $customer_id ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to view this customer order summary.', 'customer-order-summary' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return the order summary (cached).
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$customer_id = absint( $request['customer_id'] );

		$transient_key = self::TRANSIENT_PREFIX . $customer_id;
		$cached        = get_transient( $transient_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$summary = $this->build_summary( $customer_id );

		set_transient( $transient_key, $summary, self::CACHE_TTL );

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * Aggregate the customer's orders in PHP via wc_get_orders().
	 *
	 * @param int $customer_id Customer/user ID.
	 * @return array
	 */
	private function build_summary( int $customer_id ): array {
		// Most-recent first so the first row is the latest order.
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => -1,
				'type'        => 'shop_order',
				'status'      => array_keys( wc_get_order_statuses() ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$total_orders    = 0;
		$total_spent     = 0.0;
		$statuses        = array();
		$last_order_date = null;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$total_orders;
			$total_spent += (float) $order->get_total();

			$status              = $order->get_status(); // No "wc-" prefix.
			$statuses[ $status ] = isset( $statuses[ $status ] ) ? $statuses[ $status ] + 1 : 1;

			// First iteration is the most recent order (DESC order).
			if ( null === $last_order_date ) {
				$date_created = $order->get_date_created();
				if ( $date_created instanceof WC_DateTime ) {
					// Express in the site's configured timezone, ISO-8601.
					$date_created->setTimezone( wp_timezone() );
					$last_order_date = $date_created->format( 'c' );
				}
			}
		}

		// Cast status counts to int.
		$statuses = array_map( 'intval', $statuses );

		return array(
			'customer_id'     => (int) $customer_id,
			'total_orders'    => (int) $total_orders,
			'total_spent'     => number_format( $total_spent, wc_get_price_decimals(), '.', '' ),
			'statuses'        => (object) $statuses,
			'last_order_date' => $last_order_date, // ISO-8601 string or null.
		);
	}

	/**
	 * Invalidate a customer's cached summary when one of their orders changes.
	 *
	 * @param int      $order_id    Order ID.
	 * @param string   $from_status Previous status.
	 * @param string   $to_status   New status.
	 * @param WC_Order $order       Order object.
	 */
	public function flush_cache( $order_id, $from_status, $to_status, $order ): void {
		$customer_id = ( $order instanceof WC_Order ) ? (int) $order->get_customer_id() : 0;
		if ( $customer_id > 0 ) {
			delete_transient( self::TRANSIENT_PREFIX . $customer_id );
		}
	}

	/**
	 * Response schema.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'customer_order_summary',
			'type'       => 'object',
			'properties' => array(
				'customer_id'     => array(
					'description' => __( 'Customer (user) ID.', 'customer-order-summary' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_orders'    => array(
					'description' => __( 'Total number of orders placed by the customer.', 'customer-order-summary' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_spent'     => array(
					'description' => __( 'Total amount spent, as a decimal string.', 'customer-order-summary' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'statuses'        => array(
					'description'          => __( 'Count of orders per status.', 'customer-order-summary' ),
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
					'context'              => array( 'view' ),
					'readonly'             => true,
				),
				'last_order_date' => array(
					'description' => __( 'ISO-8601 date of the most recent order (site timezone), or null.', 'customer-order-summary' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
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
						. esc_html__( 'Customer Order Summary Endpoint requires WooCommerce to be active.', 'customer-order-summary' )
						. '</p></div>';
				}
			);
			return;
		}

		( new Customer_Order_Summary() )->init();
	}
);
