<?php
/**
 * Plugin Name:       WooCommerce to Gotify Notifications
 * Description:       Sends a push notification to a Gotify server whenever a new WooCommerce order is placed.
 * Version:           1.1.0
 * Author:            Tu Nguyen
 * Author URI:        https://github.com/0tunguyen0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-gotify-notify
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WC_GOTIFY_VERSION', '1.1.0' );
define( 'WC_GOTIFY_OPTION', 'wc_gotify_settings' );
define( 'WC_GOTIFY_FILE', __FILE__ );
define( 'WC_GOTIFY_BASENAME', plugin_basename( __FILE__ ) );
define( 'WC_GOTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_GOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_GOTIFY_ASYNC_HOOK', 'wc_gotify_send_async' );

// Declare HPOS (Custom Order Tables) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_GOTIFY_FILE, true );
	}
} );

// Load textdomain early on init.
add_action( 'init', function () {
	load_plugin_textdomain( 'wc-gotify-notify', false, dirname( WC_GOTIFY_BASENAME ) . '/languages' );
} );

// Include runtime classes.
require_once WC_GOTIFY_DIR . 'includes/class-wc-gotify-core.php';
require_once WC_GOTIFY_DIR . 'includes/class-wc-gotify-sidebar.php';

// Include admin-only classes.
if ( is_admin() ) {
	require_once WC_GOTIFY_DIR . 'includes/class-wc-gotify-admin.php';
}

/**
 * Bootstrap the plugin once all plugins have loaded.
 */
function wc_gotify_notify_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'WooCommerce to Gotify Notifications requires WooCommerce to be installed and active.', 'wc-gotify-notify' ) .
					'</p></div>';
			} );
		}
		return;
	}

	// Initialize core notification sender.
	WC_Gotify_Core::instance();

	// Initialize admin interface.
	if ( is_admin() ) {
		WC_Gotify_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'wc_gotify_notify_init', 20 );
