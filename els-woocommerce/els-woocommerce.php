<?php
/**
 * Plugin Name: ELS for WooCommerce
 * Plugin URI:  https://nikolamanov.com
 * Description: Creates ELS postal shipments from WooCommerce orders through the configurable ELS API.
 * Version:     1.6.0
 * Author:      Nikola Manov
 * Author URI:  https://nikolamanov.com
 * Text Domain: els-woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'ELS_WC_VERSION', '1.6.0' );
define( 'ELS_WC_FILE', __FILE__ );
define( 'ELS_WC_PATH', plugin_dir_path( __FILE__ ) );

require_once ELS_WC_PATH . 'includes/class-els-wc-settings.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-api-client.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-places.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-order-exporter.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-customer-tracking.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-status-sync.php';
require_once ELS_WC_PATH . 'includes/class-els-wc-admin.php';

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( ELS_WC_Settings::OPTION_NAME, false ) ) {
			add_option( ELS_WC_Settings::OPTION_NAME, ELS_WC_Settings::defaults(), '', false );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'els_wc_sync_statuses' );
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		ELS_WC_Settings::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'ELS for WooCommerce requires WooCommerce to be installed and active.', 'els-woocommerce' ) . '</p></div>';
					}
				}
			);
			return;
		}

		$exporter = new ELS_WC_Order_Exporter();
		ELS_WC_Places::init();
		ELS_WC_Customer_Tracking::init();
		ELS_WC_Status_Sync::init();
		new ELS_WC_Admin( $exporter );
	}
);
