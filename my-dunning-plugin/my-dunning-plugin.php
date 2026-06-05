<?php
/**
 * Plugin Name:       My Dunning Plugin
 * Plugin URI:        https://example.com/my-dunning-plugin
 * Description:        Three-strike dunning sequence for failed WooCommerce Subscription renewals: retry after 3 and 5 days, cancel on the third failure.
 * Version:           1.0.0
 * Author:            Kilowott
 * License:           GPL-2.0-or-later
 * Requires PHP:      7.4
 * Requires at least: 5.8
 *
 * @package My_Dunning_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MY_DUNNING_PLUGIN_FILE', __FILE__ );
define( 'MY_DUNNING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Main plugin class — bootstraps the dunning manager.
 */
final class My_Dunning_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var My_Dunning_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Dunning manager.
	 *
	 * @var Dunning_Manager|null
	 */
	private $manager = null;

	/**
	 * Get / create the singleton.
	 *
	 * @return My_Dunning_Plugin
	 */
	public static function instance(): My_Dunning_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		require_once MY_DUNNING_PLUGIN_DIR . 'includes/class-dunning-manager.php';
	}

	/**
	 * Initialise — only when WooCommerce Subscriptions is available.
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'My Dunning Plugin requires WooCommerce Subscriptions to be active.', 'my-dunning-plugin' )
						. '</p></div>';
				}
			);
			return;
		}

		$this->manager = new Dunning_Manager();
		$this->manager->register();
	}
}

/**
 * Bootstrap.
 */
add_action(
	'plugins_loaded',
	static function () {
		My_Dunning_Plugin::instance()->init();
	}
);
