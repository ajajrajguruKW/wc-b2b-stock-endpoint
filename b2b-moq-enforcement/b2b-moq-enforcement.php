<?php
/**
 * Plugin Name:       B2B MOQ Enforcement
 * Plugin URI:        https://example.com/b2b-moq-enforcement
 * Description:        Enforces a per-product Minimum Order Quantity (MOQ) for wholesale (b2b_customer) users. Adds a "B2B Minimum Order Quantity" field to the product General tab and validates the cart against it.
 * Version:           1.0.0
 * Author:            Kilowott
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * Text Domain:       b2b-moq
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package B2B_MOQ_Enforcement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the plugin text domain for translations.
 *
 * @return void
 */
function b2b_moq_load_textdomain() {
	load_plugin_textdomain( 'b2b-moq', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'b2b_moq_load_textdomain' );

/**
 * Add the "B2B Minimum Order Quantity" field to the product General tab.
 *
 * woocommerce_wp_text_input automatically renders the stored value for the
 * product currently being edited.
 *
 * @return void
 */
function b2b_moq_add_product_field() {
	woocommerce_wp_text_input(
		array(
			'id'                => '_b2b_moq',
			'label'             => __( 'B2B Minimum Order Quantity', 'b2b-moq' ),
			'description'       => __( 'Minimum quantity B2B customers must order. Leave empty or 0 for no minimum.', 'b2b-moq' ),
			'desc_tip'          => true,
			'type'              => 'number',
			'custom_attributes' => array(
				'step' => '1',
				'min'  => '0',
			),
		)
	);
}
add_action( 'woocommerce_product_options_general_product_data', 'b2b_moq_add_product_field' );

/**
 * Save the `_b2b_moq` product meta when the product is saved.
 *
 * WooCommerce verifies its product-data nonce before this hook fires; we
 * sanitise the value to a non-negative integer and persist it via the CRUD API.
 *
 * @param int $post_id Product ID.
 * @return void
 */
function b2b_moq_save_product_field( $post_id ) {
	$product = wc_get_product( $post_id );

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	// Sanitise to a non-negative integer.
	$moq = isset( $_POST['_b2b_moq'] ) ? absint( wp_unslash( $_POST['_b2b_moq'] ) ) : 0;

	$product->update_meta_data( '_b2b_moq', $moq );
	$product->save();
}
add_action( 'woocommerce_process_product_meta', 'b2b_moq_save_product_field' );

/**
 * Is the current user a B2B customer?
 *
 * @return bool
 */
function b2b_moq_is_b2b_customer() {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user = wp_get_current_user();

	return in_array( 'b2b_customer', (array) $user->roles, true );
}

/**
 * Validate cart line items against each product's B2B MOQ.
 *
 * MOQ rules apply ONLY to users with the `b2b_customer` role; everyone else is
 * silently skipped. An error notice blocks cart/checkout from proceeding.
 *
 * @return void
 */
function b2b_moq_validate_cart_items() {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	// Silently skip MOQ enforcement for non-B2B users.
	if ( ! b2b_moq_is_b2b_customer() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product )
			? $cart_item['data']
			: null;

		if ( null === $product ) {
			continue;
		}

		$moq = absint( $product->get_meta( '_b2b_moq' ) );

		// Only enforce when an MOQ greater than zero is set.
		if ( $moq <= 0 ) {
			continue;
		}

		$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

		if ( $quantity < $moq ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: required minimum quantity, 3: current cart quantity. */
					esc_html__( '%1$s has a B2B minimum order quantity of %2$d. You currently have %3$d in your cart.', 'b2b-moq' ),
					esc_html( $product->get_name() ),
					$moq,
					$quantity
				),
				'error'
			);
		}
	}
}
add_action( 'woocommerce_check_cart_items', 'b2b_moq_validate_cart_items' );
