<?php
/**
 * Plugin Name:       Multi-Vendor Order-Splitting Engine
 * Plugin URI:        https://example.com/order-splitting-engine
 * Description:        Splits a multi-vendor WooCommerce order into one child sub-order per vendor (HPOS-compatible), copying addresses, vendor line items, and a proportional shipping share.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package Order_Splitting_Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-splitting engine.
 *
 * Vendor ownership lives in product meta `_vendor_id` (a WP user ID).
 */
final class Order_Splitting_Engine {

	/**
	 * Product meta key holding the vendor user ID.
	 */
	const VENDOR_META = '_vendor_id';

	/**
	 * Parent-order meta: guard flag once sub-orders exist.
	 */
	const FLAG_META = '_sub_orders_created';

	/**
	 * Sub-order meta: reference back to the parent order.
	 */
	const PARENT_META = '_parent_order_id';

	/**
	 * Wire up hooks. Called from the bootstrap below — never at global scope.
	 */
	public function init(): void {
		// Fires once an order has been created and entered a pending / on-hold
		// state (i.e. awaiting or holding payment) — the right moment to split.
		add_action( 'woocommerce_order_status_pending', array( $this, 'maybe_split_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'maybe_split_order' ), 20, 1 );
	}

	/**
	 * Split the order into per-vendor sub-orders, if appropriate.
	 *
	 * @param int $order_id Parent order ID.
	 */
	public function maybe_split_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Never split a sub-order (prevents infinite recursion: sub-orders are
		// created as 'pending' which would re-trigger this very hook).
		if ( $order->get_meta( self::PARENT_META ) ) {
			return;
		}

		// Double-split guard: bail if we've already created sub-orders.
		if ( $order->get_meta( self::FLAG_META ) ) {
			return;
		}

		// Group line items by vendor.
		$items_by_vendor = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// Read vendor via the product CRUD getter (HPOS-safe, no get_post_meta).
			$vendor_id = (int) $product->get_meta( self::VENDOR_META );
			if ( $vendor_id <= 0 ) {
				continue; // Unassigned products are left on the parent order.
			}

			$items_by_vendor[ $vendor_id ][] = $item;
		}

		// Need at least two vendors to justify a split.
		if ( count( $items_by_vendor ) < 2 ) {
			return;
		}

		// Pre-compute totals for proportional shipping allocation.
		$parent_shipping_total = (float) $order->get_shipping_total();
		$all_items_total       = $this->sum_items_total( $order->get_items() );

		$created_ids = array();

		foreach ( $items_by_vendor as $vendor_id => $vendor_items ) {
			$sub_order = $this->create_sub_order( $order, (int) $vendor_id, $vendor_items, $parent_shipping_total, $all_items_total );
			if ( $sub_order instanceof WC_Order ) {
				$created_ids[] = $sub_order->get_id();
			}
		}

		if ( empty( $created_ids ) ) {
			return;
		}

		// Mark the parent so we never split it again, and note the children.
		$order->update_meta_data( self::FLAG_META, current_time( 'mysql' ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: comma-separated sub-order IDs. */
				__( 'Order split into vendor sub-orders: %s', 'order-splitting-engine' ),
				implode( ', ', array_map( 'absint', $created_ids ) )
			)
		);
		$order->save();
	}

	/**
	 * Create a single vendor sub-order.
	 *
	 * @param WC_Order $parent          Parent order.
	 * @param int      $vendor_id       Vendor user ID.
	 * @param array    $vendor_items    Line items belonging to this vendor.
	 * @param float    $parent_shipping Parent shipping total.
	 * @param float    $all_items_total Sum of all parent line-item totals.
	 * @return WC_Order|null
	 */
	private function create_sub_order( WC_Order $parent, int $vendor_id, array $vendor_items, float $parent_shipping, float $all_items_total ): ?WC_Order {
		// Programmatic WooCommerce order API — NOT wp_insert_post().
		$sub_order = wc_create_order( array( 'customer_id' => $parent->get_customer_id() ) );
		if ( is_wp_error( $sub_order ) || ! $sub_order instanceof WC_Order ) {
			return null;
		}

		// Copy billing & shipping addresses.
		$sub_order->set_address( $parent->get_address( 'billing' ), 'billing' );
		$sub_order->set_address( $parent->get_address( 'shipping' ), 'shipping' );

		// Copy only this vendor's line items, preserving pricing.
		$vendor_items_total = 0.0;
		foreach ( $vendor_items as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$vendor_items_total += (float) $item->get_total();

			$sub_order->add_product(
				$product,
				$item->get_quantity(),
				array(
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
					'subtotal_tax' => $item->get_subtotal_tax(),
					'total_tax'    => $item->get_total_tax(),
				)
			);
		}

		// Proportional shipping: this vendor's share of the parent shipping,
		// weighted by line-item value (falls back to an even split).
		if ( $parent_shipping > 0 ) {
			$share = ( $all_items_total > 0 )
				? ( $vendor_items_total / $all_items_total )
				: 1;
			$vendor_shipping = round( $parent_shipping * $share, wc_get_price_decimals() );

			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( __( 'Shipping', 'order-splitting-engine' ) );
			$shipping_item->set_total( (string) $vendor_shipping );
			$sub_order->add_item( $shipping_item );
		}

		// Reference back to the parent (HPOS-safe meta write).
		$sub_order->update_meta_data( self::PARENT_META, $parent->get_id() );
		$sub_order->update_meta_data( '_vendor_id', $vendor_id );

		// Recalculate then set the initial status to pending.
		$sub_order->calculate_totals();
		$sub_order->set_status( 'pending', __( 'Vendor sub-order created from parent order.', 'order-splitting-engine' ) );
		$sub_order->save();

		return $sub_order;
	}

	/**
	 * Sum the `total` of a set of order line items.
	 *
	 * @param array $items Order items.
	 * @return float
	 */
	private function sum_items_total( array $items ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) $item->get_total();
		}
		return $total;
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
						. esc_html__( 'Multi-Vendor Order-Splitting Engine requires WooCommerce to be active.', 'order-splitting-engine' )
						. '</p></div>';
				}
			);
			return;
		}

		( new Order_Splitting_Engine() )->init();
	}
);
