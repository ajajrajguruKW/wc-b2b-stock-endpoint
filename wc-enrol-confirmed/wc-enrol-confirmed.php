<?php
/**
 * Plugin Name:       WC Enrollment Confirmed Status
 * Plugin URI:        https://example.com/wc-enrol-confirmed
 * Description:        Adds an "Enrollment Confirmed" WooCommerce order status that auto-enrols the customer into the LearnDash courses linked to the ordered products.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package WC_Enrol_Confirmed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the custom "Enrollment Confirmed" order status.
 *
 * Registered slug is `wc-enrol-confirmed` (WooCommerce statuses are stored as
 * post statuses prefixed with `wc-`).
 *
 * @return void
 */
function wc_enrol_register_order_status() {
	register_post_status(
		'wc-enrol-confirmed',
		array(
			'label'                     => _x( 'Enrollment Confirmed', 'Order status', 'wc-enrol-confirmed' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders. */
			'label_count'               => _n_noop(
				'Enrollment Confirmed <span class="count">(%s)</span>',
				'Enrollment Confirmed <span class="count">(%s)</span>',
				'wc-enrol-confirmed'
			),
		)
	);
}
add_action( 'init', 'wc_enrol_register_order_status' );

/**
 * Add the custom status to the WooCommerce order-status list so it appears in
 * the admin Order Status dropdown.
 *
 * @param array $order_statuses Existing order statuses.
 * @return array
 */
function wc_enrol_add_order_status( $order_statuses ) {
	$new_statuses = array();

	// Insert "Enrollment Confirmed" right after "Processing" for a sensible order.
	foreach ( $order_statuses as $key => $label ) {
		$new_statuses[ $key ] = $label;

		if ( 'wc-processing' === $key ) {
			$new_statuses['wc-enrol-confirmed'] = _x( 'Enrollment Confirmed', 'Order status', 'wc-enrol-confirmed' );
		}
	}

	// Fallback: if "Processing" was not present, append at the end.
	if ( ! isset( $new_statuses['wc-enrol-confirmed'] ) ) {
		$new_statuses['wc-enrol-confirmed'] = _x( 'Enrollment Confirmed', 'Order status', 'wc-enrol-confirmed' );
	}

	return $new_statuses;
}
add_filter( 'wc_order_statuses', 'wc_enrol_add_order_status' );

/**
 * Auto-enrol the customer into linked LearnDash courses when an order moves to
 * the "Enrollment Confirmed" status.
 *
 * Note: `woocommerce_order_status_changed` passes statuses WITHOUT the `wc-`
 * prefix, so we compare against the bare slug `enrol-confirmed`.
 *
 * @param int      $order_id   Order ID.
 * @param string   $old_status Previous status (no `wc-` prefix).
 * @param string   $new_status New status (no `wc-` prefix).
 * @param WC_Order $order      Order object.
 * @return void
 */
function wc_enrol_handle_status_change( $order_id, $old_status, $new_status, $order ) {
	// Only act when transitioning to our custom status (Yoda condition).
	if ( 'enrol-confirmed' !== $new_status ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// The student to enrol. Guest orders have no user ID and cannot be enrolled.
	$user_id = (int) $order->get_customer_id();

	if ( 0 >= $user_id ) {
		$order->add_order_note( esc_html__( 'Enrollment skipped: order has no registered customer (guest checkout).', 'wc-enrol-confirmed' ) );
		return;
	}

	// LearnDash must be available.
	if ( ! function_exists( 'ld_update_course_access' ) ) {
		$order->add_order_note( esc_html__( 'Enrollment skipped: LearnDash is not active.', 'wc-enrol-confirmed' ) );
		return;
	}

	$enrolled_courses = array();

	// Loop through every line item and enrol into each product's linked course.
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$product_id = $item->get_product_id();

		if ( 0 >= $product_id ) {
			continue;
		}

		// Linked LearnDash course ID stored as product post meta (ACF field).
		$course_id = absint( get_post_meta( $product_id, '_linked_course_id', true ) );

		if ( 0 >= $course_id ) {
			continue;
		}

		// Avoid enrolling the same course twice if multiple products share it.
		if ( in_array( $course_id, $enrolled_courses, true ) ) {
			continue;
		}

		// Enrol the student into the course.
		ld_update_course_access( $user_id, $course_id );

		$enrolled_courses[] = $course_id;
	}

	// Record the outcome as an order note (escaped).
	if ( empty( $enrolled_courses ) ) {
		$order->add_order_note( esc_html__( 'Enrollment Confirmed: no linked LearnDash courses were found on the ordered products.', 'wc-enrol-confirmed' ) );
		return;
	}

	$course_list = implode( ', ', array_map( 'absint', $enrolled_courses ) );

	$order->add_order_note(
		sprintf(
			/* translators: 1: customer user ID, 2: comma-separated list of course IDs. */
			esc_html__( 'Enrollment Confirmed: user #%1$d enrolled into LearnDash course(s): %2$s.', 'wc-enrol-confirmed' ),
			$user_id,
			esc_html( $course_list )
		)
	);
}
add_action( 'woocommerce_order_status_changed', 'wc_enrol_handle_status_change', 10, 4 );
