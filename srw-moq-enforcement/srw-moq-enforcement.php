<?php
/**
 * Plugin Name:       SpiceRoute Wholesale — MOQ Enforcement
 * Plugin URI:        https://example.com/srw-moq-enforcement
 * Description:        Enforces a per-product Minimum Order Quantity (MOQ) for a B2B WooCommerce store. Reads the ACF field `moq_quantity` and blocks add-to-cart and checkout when a line item is below its MOQ.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package SRW_MOQ_Enforcement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the Minimum Order Quantity for a product.
 *
 * Reads the ACF field `moq_quantity` via get_field(). An empty/unset value (or
 * ACF not being active) means "no restriction", represented as an MOQ of 1.
 *
 * @param int $product_id Product (post) ID.
 * @return int MOQ, always >= 1.
 */
function srw_moq_get_for_product( $product_id ) {
	$product_id = absint( $product_id );
	$moq        = 1;

	if ( $product_id && function_exists( 'get_field' ) ) {
		$value = get_field( 'moq_quantity', $product_id );

		// Treat empty string / null / unset as no restriction.
		if ( '' !== $value && null !== $value ) {
			$moq = absint( $value );
		}
	}

	// Never allow an MOQ below 1.
	return max( 1, $moq );
}

/**
 * Block adding a product to the cart when the quantity is below its MOQ.
 *
 * Hooked to `woocommerce_add_to_cart_validation`. Returning false aborts the
 * add-to-cart operation.
 *
 * @param bool  $passed       Whether validation has passed so far.
 * @param int   $product_id   Product ID being added.
 * @param int   $quantity     Quantity being added.
 * @param int   $variation_id Variation ID (0 for simple products).
 * @param array $variations   Variation attributes.
 * @param array $cart_item_data Extra cart item data.
 * @return bool
 */
function srw_moq_validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
	$moq      = srw_moq_get_for_product( $product_id );
	$quantity = (int) $quantity;

	if ( $quantity < $moq ) {
		$product = wc_get_product( $product_id );
		$name    = ( $product instanceof WC_Product ) ? $product->get_name() : __( 'This product', 'srw-moq-enforcement' );

		wc_add_notice(
			sprintf(
				/* translators: 1: product name, 2: minimum order quantity. */
				esc_html__( '%1$s has a minimum order quantity of %2$d. Please add at least %2$d to your cart.', 'srw-moq-enforcement' ),
				esc_html( $name ),
				$moq
			),
			'error'
		);

		return false;
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'srw_moq_validate_add_to_cart', 10, 6 );

/**
 * Validate every cart line item against its MOQ at cart/checkout.
 *
 * Hooked to `woocommerce_check_cart_items`, which runs on both the cart and
 * checkout pages; adding an error notice here prevents the order from
 * proceeding. This catches quantities lowered after add-to-cart, or items
 * added before this plugin was active.
 *
 * @return void
 */
function srw_moq_validate_cart_items() {
	// Don't run in the admin (except AJAX), and only when a cart exists.
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product_id = ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;

		if ( 0 === $product_id ) {
			continue;
		}

		$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		$moq      = srw_moq_get_for_product( $product_id );

		if ( $quantity < $moq ) {
			$product = ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product )
				? $cart_item['data']
				: wc_get_product( $product_id );

			$name = ( $product instanceof WC_Product ) ? $product->get_name() : __( 'A product', 'srw-moq-enforcement' );

			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: minimum order quantity, 3: current quantity. */
					esc_html__( '%1$s requires a minimum order quantity of %2$d (your cart has %3$d). Please increase the quantity to continue.', 'srw-moq-enforcement' ),
					esc_html( $name ),
					$moq,
					$quantity
				),
				'error'
			);
		}
	}
}
add_action( 'woocommerce_check_cart_items', 'srw_moq_validate_cart_items', 10 );
