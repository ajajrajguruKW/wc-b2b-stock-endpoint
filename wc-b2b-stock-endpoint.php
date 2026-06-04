<?php
/**
 * Plugin Name:       WC B2B Stock Endpoint
 * Plugin URI:        https://example.com/wc-b2b-stock-endpoint
 * Description:        Registers a secure custom WooCommerce REST endpoint (GET /wc-b2b/v1/stock/<product_id>) returning live stock data for authenticated B2B clients.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package WC_B2B_Stock_Endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the B2B stock REST endpoint.
 *
 * Route: GET /wp-json/wc-b2b/v1/stock/<product_id>
 *
 * Access is granted to:
 *  - Logged-in users holding the `view_woocommerce_reports` capability, OR
 *  - Requests authenticated with a valid WooCommerce REST API key
 *    (consumer_key / consumer_secret).
 *
 * Everything else receives a WP_Error with HTTP 401.
 */
final class WC_B2B_Stock_Endpoint {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'wc-b2b/v1';

	/**
	 * REST base for the stock resource.
	 */
	const REST_BASE = 'stock';

	/**
	 * Wire up WordPress / WooCommerce hooks.
	 *
	 * Called once from the `plugins_loaded` bootstrap below — never at
	 * global scope.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Our namespace is not `wc/`, so WooCommerce's authentication layer
		// would normally ignore it. Opt this route in so consumer key/secret
		// authentication is processed for it.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'enable_wc_auth_for_route' ) );
	}

	/**
	 * Tell WooCommerce to run its API-key authentication for our namespace.
	 *
	 * @param bool $is_request Whether WC already considers this a REST API request.
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
	 * Register the REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stock' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'WooCommerce product ID.', 'wc-b2b-stock-endpoint' ),
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
	 * Permission gate for the endpoint.
	 *
	 * Returning a WP_Error (rather than `false`) lets us control the HTTP
	 * status code — here, 401 for unauthenticated callers.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( current_user_can( 'view_woocommerce_reports' ) ) {
			return true;
		}

		if ( $this->authenticated_via_api_key( $request ) ) {
			return true;
		}

		return new WP_Error(
			'wc_b2b_unauthorized',
			__( 'Authentication required. Provide a WooCommerce API key or log in as a user with the view_woocommerce_reports capability.', 'wc-b2b-stock-endpoint' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Detect whether the request was authenticated with a WooCommerce API key.
	 *
	 * WooCommerce's authentication layer maps a valid consumer_key /
	 * consumer_secret pair to a WP user during `determine_current_user`. So if
	 * a key was supplied AND a user is now authenticated, the key was valid.
	 * An invalid key leaves the request anonymous, which falls through to 401.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	private function authenticated_via_api_key( WP_REST_Request $request ): bool {
		$key_in_query = (bool) $request->get_param( 'consumer_key' );
		$key_in_basic = isset( $_SERVER['PHP_AUTH_USER'] )
			&& 0 === strpos( sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ), 'ck_' );

		return ( $key_in_query || $key_in_basic ) && is_user_logged_in();
	}

	/**
	 * Build and return the stock response.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stock( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'wc_b2b_product_not_found',
				/* translators: %d: product ID. */
				sprintf( __( 'No WooCommerce product found for ID %d.', 'wc-b2b-stock-endpoint' ), $product_id ),
				array( 'status' => 404 )
			);
		}

		$stock_quantity = $product->get_stock_quantity();

		$response = array(
			'product_id'     => (int) $product->get_id(),
			'sku'            => (string) $product->get_sku(),
			'stock_quantity' => ( null === $stock_quantity ) ? null : (int) $stock_quantity,
			'stock_status'   => (string) $product->get_stock_status(),
			'timestamp'      => gmdate( 'c' ), // ISO 8601, UTC.
		);

		/**
		 * Filter the stock response before it is returned.
		 *
		 * Third-party code can add or override fields here.
		 *
		 * @param array           $response The response payload.
		 * @param WC_Product      $product  The product object.
		 * @param WP_REST_Request $request  The current request.
		 */
		$response = apply_filters( 'wc_b2b_stock_response', $response, $product, $request );

		return rest_ensure_response( $response );
	}
}

/**
 * Bootstrap the plugin once all plugins (incl. WooCommerce) are loaded.
 *
 * Instantiation happens here — not at global scope — so the class is only
 * built when WooCommerce is present.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'WC B2B Stock Endpoint requires WooCommerce to be active.', 'wc-b2b-stock-endpoint' )
						. '</p></div>';
				}
			);
			return;
		}

		( new WC_B2B_Stock_Endpoint() )->init();
	}
);
