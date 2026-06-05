<?php
/**
 * Plugin Name:       WC B2B Velocity Endpoint
 * Plugin URI:        https://example.com/wc-b2b-velocity-endpoint
 * Description:        Secure custom REST endpoint exposing per-product 30-day sales velocity (units sold and gross revenue) to authenticated B2B managers holding the view_b2b_velocity capability.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package WC_B2B_Velocity_Endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves GET /wc-b2b/v1/products/<id>/velocity.
 */
final class WC_B2B_Velocity_Endpoint {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'wc-b2b/v1';

	/**
	 * Custom capability required to read velocity data.
	 */
	const CAPABILITY = 'view_b2b_velocity';

	/**
	 * Reporting window in days.
	 */
	const WINDOW_DAYS = 30;

	/**
	 * Wire up hooks. Called from the bootstrap below — never at global scope.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Our namespace is not `wc/`, so opt it in to WooCommerce's API-key
		// authentication layer so consumer key/secret credentials are honoured.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'enable_wc_auth_for_route' ) );
	}

	/**
	 * Grant the custom capability to privileged roles on activation.
	 */
	public static function activate(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Tell WooCommerce to run its API-key authentication for our namespace.
	 *
	 * @param bool $is_request Whether WC already treats this as a REST request.
	 * @return bool
	 */
	public function enable_wc_auth_for_route( $is_request ): bool {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return (bool) $is_request;
		}

		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		if ( false !== strpos( $request_uri, $rest_prefix . self::NAMESPACE ) ) {
			return true;
		}

		return (bool) $is_request;
	}

	/**
	 * Register the velocity route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>[\d]+)/velocity',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_velocity' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'WooCommerce product ID.', 'wc-b2b-velocity-endpoint' ),
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => static function ( $param ) {
								return is_numeric( $param ) && (int) $param > 0;
							},
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission gate.
	 *
	 *  - Unauthenticated  -> 401 rest_forbidden.
	 *  - Authenticated but missing capability -> 403 rest_unauthorized.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication is required to access this resource.', 'wc-b2b-velocity-endpoint' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'rest_unauthorized',
				__( 'You do not have permission to view B2B velocity data.', 'wc-b2b-velocity-endpoint' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Build the velocity response.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_velocity( WP_REST_Request $request ) {
		global $wpdb;

		$product_id = absint( $request['id'] );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'rest_product_invalid',
				/* translators: %d: product ID. */
				sprintf( __( 'No valid WooCommerce product found for ID %d.', 'wc-b2b-velocity-endpoint' ), $product_id ),
				array( 'status' => 404 )
			);
		}

		$table = $wpdb->prefix . 'wc_order_product_lookup';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::WINDOW_DAYS * DAY_IN_SECONDS ) );

		// Table name is a trusted internal identifier (not user input); all
		// user-supplied values are bound via prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(product_qty) AS units_sold, SUM(product_gross_revenue) AS gross_revenue
				 FROM `{$table}`
				 WHERE product_id = %d AND date_created >= %s",
				$product_id,
				$since
			)
		);

		$units_sold    = ( $row && null !== $row->units_sold ) ? (int) $row->units_sold : 0;
		$gross_revenue = ( $row && null !== $row->gross_revenue ) ? (float) $row->gross_revenue : 0.0;

		$response = array(
			'product_id'        => (int) $product->get_id(),
			'product_name'      => (string) $product->get_name(),
			'units_sold_30d'    => $units_sold,
			'gross_revenue_30d' => $gross_revenue,
			'currency'          => (string) get_woocommerce_currency(),
		);

		/**
		 * Filter the velocity response before it is returned.
		 *
		 * @param array           $response   The response payload.
		 * @param WC_Product      $product    The product object.
		 * @param WP_REST_Request $request    The current request.
		 */
		$response = apply_filters( 'wc_b2b_velocity_response', $response, $product, $request );

		return new WP_REST_Response( $response, 200 );
	}
}

/**
 * Grant the capability on activation.
 */
register_activation_hook( __FILE__, array( 'WC_B2B_Velocity_Endpoint', 'activate' ) );

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
						. esc_html__( 'WC B2B Velocity Endpoint requires WooCommerce to be active.', 'wc-b2b-velocity-endpoint' )
						. '</p></div>';
				}
			);
			return;
		}

		( new WC_B2B_Velocity_Endpoint() )->init();
	}
);
