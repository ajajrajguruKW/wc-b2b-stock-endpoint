<?php
/**
 * Plugin Name:       WC Shipping Info Tab
 * Plugin URI:        https://example.com/wc-shipping-info-tab
 * Description:        Adds a "Shipping Info" tab to single product pages, showing per-product content from the _custom_shipping_info meta, with an editor field in the product data metabox.
 * Version:           1.0.0
 * Author:            Kilowott
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * Text Domain:       wc-csi
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package WC_Shipping_Info_Tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the plugin text domain.
 *
 * @return void
 */
function wc_csi_load_textdomain() {
	load_plugin_textdomain( 'wc-csi', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wc_csi_load_textdomain' );

/* -------------------------------------------------------------------------
 * Front-end: the "Shipping Info" product tab
 * ---------------------------------------------------------------------- */

/**
 * Register the "Shipping Info" tab in the single-product tab list.
 *
 * @param array $tabs Existing product tabs.
 * @return array
 */
function wc_csi_add_product_tab( $tabs ) {
	$tabs['wc_csi_shipping_info'] = array(
		'title'    => __( 'Shipping Info', 'wc-csi' ),
		'priority' => 25, // After Description (10) / before Reviews (30).
		'callback' => 'wc_csi_render_tab_content',
	);

	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'wc_csi_add_product_tab' );

/**
 * Render the tab content. All output is escaped.
 *
 * @return void
 */
function wc_csi_render_tab_content() {
	global $product;

	$info = '';
	if ( $product instanceof WC_Product ) {
		$info = (string) $product->get_meta( '_custom_shipping_info' );
	}

	// Fallback when no shipping info has been entered.
	if ( '' === trim( $info ) ) {
		$info = __( 'No shipping information available.', 'wc-csi' );
	}

	echo '<h2>' . esc_html__( 'Shipping Info', 'wc-csi' ) . '</h2>';

	// Escape first, then convert newlines to <br> for readable multi-line text.
	echo '<div class="wc-csi-shipping-info">' . nl2br( esc_html( $info ) ) . '</div>';
}

/* -------------------------------------------------------------------------
 * Admin: product data tab + field
 * ---------------------------------------------------------------------- */

/**
 * Add a "Shipping Info" tab to the product data metabox.
 *
 * @param array $tabs Existing product-data tabs.
 * @return array
 */
function wc_csi_add_product_data_tab( $tabs ) {
	$tabs['wc_csi_shipping'] = array(
		'label'    => __( 'Shipping Info', 'wc-csi' ),
		'target'   => 'wc_csi_shipping_data',
		'priority' => 80,
		'class'    => array(),
	);

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', 'wc_csi_add_product_data_tab' );

/**
 * Output the editor panel for the shipping info field (with a nonce).
 *
 * @return void
 */
function wc_csi_add_product_data_panel() {
	global $post;

	echo '<div id="wc_csi_shipping_data" class="panel woocommerce_options_panel">';

	// Nonce verified on save.
	wp_nonce_field( 'wc_csi_save_meta', 'wc_csi_nonce' );

	woocommerce_wp_textarea_input(
		array(
			'id'          => '_custom_shipping_info',
			'label'       => __( 'Shipping Information', 'wc-csi' ),
			'description' => __( 'Displayed in the "Shipping Info" tab on the product page.', 'wc-csi' ),
			'desc_tip'    => true,
			'rows'        => 5,
			'value'       => get_post_meta( $post->ID, '_custom_shipping_info', true ),
		)
	);

	echo '</div>';
}
add_action( 'woocommerce_product_data_panels', 'wc_csi_add_product_data_panel' );

/**
 * Save the `_custom_shipping_info` meta. Verifies the nonce and sanitises input.
 *
 * @param int $post_id Product ID.
 * @return void
 */
function wc_csi_save_product_meta( $post_id ) {
	// Verify our nonce before doing anything else.
	if ( ! isset( $_POST['wc_csi_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_csi_nonce'] ) ), 'wc_csi_save_meta' )
	) {
		return;
	}

	// Capability check.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$value = isset( $_POST['_custom_shipping_info'] )
		? sanitize_textarea_field( wp_unslash( $_POST['_custom_shipping_info'] ) )
		: '';

	$product = wc_get_product( $post_id );
	if ( $product instanceof WC_Product ) {
		$product->update_meta_data( '_custom_shipping_info', $value );
		$product->save();
	}
}
add_action( 'woocommerce_process_product_meta', 'wc_csi_save_product_meta' );
