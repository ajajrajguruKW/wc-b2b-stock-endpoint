<?php
/**
 * Plugin Name:       Role-Based Tiered Pricing Engine
 * Plugin URI:        https://example.com/role-based-tiered-pricing
 * Description:        Role- and quantity-based tiered pricing for WooCommerce simple products. Admins define tiers per product; the lowest matching tier price is applied to the cart for the logged-in user's role.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 6.0
 *
 * @package Role_Based_Tiered_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiered pricing engine.
 *
 * Stores a JSON array of pricing tiers in the `_tiered_pricing_rules` product
 * meta field. Each tier: { "role": "wholesale_customer", "min_qty": 10, "price": "8.50" }.
 */
final class Role_Based_Tiered_Pricing {

	/**
	 * Meta key holding the JSON tier array.
	 */
	const META_KEY = '_tiered_pricing_rules';

	/**
	 * Nonce action / field name.
	 */
	const NONCE_ACTION = 'rbtp_save_rules';
	const NONCE_FIELD  = 'rbtp_nonce';

	/**
	 * Wire up hooks. Called from the bootstrap below — never at global scope.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_rules' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_tiered_pricing' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Admin: meta box
	 * ------------------------------------------------------------------- */

	/**
	 * Register the standalone meta box on the product edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'rbtp_tiered_pricing',
			__( 'Role-Based Tiered Pricing', 'role-based-tiered-pricing' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the repeater UI.
	 *
	 * @param WP_Post $post Current product post.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$rules = json_decode( (string) get_post_meta( $post->ID, self::META_KEY, true ), true );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$roles = wp_roles()->get_names();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Empty-row template handed to JS for "Add tier".
		$template = $this->render_row( '', 0, '', $roles );
		?>
		<p><?php echo esc_html__( 'Define quantity- and role-based prices for this product. When several tiers match a customer, the lowest price wins.', 'role-based-tiered-pricing' ); ?></p>

		<table class="widefat rbtp-table" style="margin-bottom:10px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Customer Role', 'role-based-tiered-pricing' ); ?></th>
					<th><?php echo esc_html__( 'Minimum Qty', 'role-based-tiered-pricing' ); ?></th>
					<th><?php echo esc_html__( 'Price', 'role-based-tiered-pricing' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody class="rbtp-rows">
				<?php
				if ( empty( $rules ) ) {
					echo $this->render_row( '', 0, '', $roles ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_row().
				} else {
					foreach ( $rules as $tier ) {
						$role    = isset( $tier['role'] ) ? (string) $tier['role'] : '';
						$min_qty = isset( $tier['min_qty'] ) ? (int) $tier['min_qty'] : 0;
						$price   = isset( $tier['price'] ) ? (string) $tier['price'] : '';
						echo $this->render_row( $role, $min_qty, $price, $roles ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_row().
					}
				}
				?>
			</tbody>
		</table>

		<button type="button" class="button rbtp-add"><?php echo esc_html__( '+ Add tier', 'role-based-tiered-pricing' ); ?></button>

		<script>
		( function () {
			var tpl = <?php echo wp_json_encode( $template ); ?>;
			var box = document.getElementById( 'rbtp_tiered_pricing' );
			if ( ! box ) { return; }
			box.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.classList.contains( 'rbtp-add' ) ) {
					e.preventDefault();
					var tbody = box.querySelector( '.rbtp-rows' );
					var wrap  = document.createElement( 'tbody' );
					wrap.innerHTML = tpl;
					tbody.appendChild( wrap.firstElementChild );
				}
				if ( e.target && e.target.classList.contains( 'rbtp-remove' ) ) {
					e.preventDefault();
					var row = e.target.closest( 'tr' );
					if ( row ) { row.parentNode.removeChild( row ); }
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render a single repeater row (escaped).
	 *
	 * @param string $role    Selected role key.
	 * @param int    $min_qty Minimum quantity.
	 * @param string $price   Price string.
	 * @param array  $roles   Map of role key => label.
	 * @return string
	 */
	private function render_row( string $role, int $min_qty, string $price, array $roles ): string {
		ob_start();
		?>
		<tr class="rbtp-row">
			<td>
				<select name="rbtp_role[]">
					<?php foreach ( $roles as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $role, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="number" min="0" step="1" name="rbtp_min_qty[]" value="<?php echo esc_attr( (string) $min_qty ); ?>" />
			</td>
			<td>
				<input type="text" inputmode="decimal" name="rbtp_price[]" value="<?php echo esc_attr( $price ); ?>" />
			</td>
			<td>
				<button type="button" class="button rbtp-remove" aria-label="<?php echo esc_attr__( 'Remove tier', 'role-based-tiered-pricing' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Admin: save
	 * ------------------------------------------------------------------- */

	/**
	 * Sanitise and persist the tier rules.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_rules( int $post_id ): void {
		// Nonce verification — first operation.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Nothing submitted — clear the meta.
		if ( empty( $_POST['rbtp_role'] ) || ! is_array( $_POST['rbtp_role'] ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$roles    = array_keys( wp_roles()->get_names() ); // Whitelist of valid role keys.
		$in_roles = array_map( 'sanitize_text_field', wp_unslash( $_POST['rbtp_role'] ) );
		$in_qty   = isset( $_POST['rbtp_min_qty'] ) ? (array) wp_unslash( $_POST['rbtp_min_qty'] ) : array();
		$in_price = isset( $_POST['rbtp_price'] ) ? (array) wp_unslash( $_POST['rbtp_price'] ) : array();

		$tiers = array();

		foreach ( $in_roles as $i => $role ) {
			// Role must be a whitelisted value.
			if ( ! in_array( $role, $roles, true ) ) {
				continue;
			}

			$min_qty = isset( $in_qty[ $i ] ) ? absint( $in_qty[ $i ] ) : 0;
			$price   = isset( $in_price[ $i ] ) ? floatval( $in_price[ $i ] ) : 0.0;

			// Skip incomplete / meaningless rows.
			if ( $price <= 0 ) {
				continue;
			}

			$tiers[] = array(
				'role'    => $role,
				'min_qty' => $min_qty,
				'price'   => number_format( $price, wc_get_price_decimals(), '.', '' ),
			);
		}

		if ( empty( $tiers ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $tiers ) );
	}

	/* ---------------------------------------------------------------------
	 * Front-end: apply pricing
	 * ------------------------------------------------------------------- */

	/**
	 * Apply the lowest matching tier price to each cart item.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function apply_tiered_pricing( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// WooCommerce can fire this hook multiple times per request.
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return;
		}
		$user_roles = (array) $user->roles;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$product    = $cart_item['data'];
			$product_id = $product->get_id();
			$quantity   = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

			$rules = json_decode( (string) get_post_meta( $product_id, self::META_KEY, true ), true );
			if ( ! is_array( $rules ) || empty( $rules ) ) {
				continue;
			}

			$best_price = null;

			foreach ( $rules as $tier ) {
				if ( ! isset( $tier['role'], $tier['min_qty'], $tier['price'] ) ) {
					continue;
				}
				if ( ! in_array( $tier['role'], $user_roles, true ) ) {
					continue;
				}
				if ( $quantity < (int) $tier['min_qty'] ) {
					continue;
				}

				$price = (float) $tier['price'];
				if ( null === $best_price || $price < $best_price ) {
					$best_price = $price;
				}
			}

			// No matching tier — leave price unchanged.
			if ( null !== $best_price ) {
				$product->set_price( $best_price );
			}
		}
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
						. esc_html__( 'Role-Based Tiered Pricing Engine requires WooCommerce to be active.', 'role-based-tiered-pricing' )
						. '</p></div>';
				}
			);
			return;
		}

		( new Role_Based_Tiered_Pricing() )->init();
	}
);
