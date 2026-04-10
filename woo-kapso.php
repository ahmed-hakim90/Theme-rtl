<?php
/**
 * Plugin Name: WooKapso - WhatsApp Notifications
 * Plugin URI:  https://github.com/ahmed/woo-kapso
 * Description: Send WhatsApp notifications via Kapso for WooCommerce orders
 * Version:     1.1.0
 * Author:      Ahmed
 * Text Domain: woo-kapso
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WOOKAPSO_VERSION', '1.1.0' );
define( 'WOOKAPSO_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOKAPSO_URL',  plugin_dir_url( __FILE__ ) );

require_once WOOKAPSO_PATH . 'includes/class-capabilities.php';
require_once WOOKAPSO_PATH . 'includes/class-kapso-api.php';
require_once WOOKAPSO_PATH . 'includes/class-logger.php';
require_once WOOKAPSO_PATH . 'includes/class-bosta.php';
require_once WOOKAPSO_PATH . 'includes/shipment-handler.php';
require_once WOOKAPSO_PATH . 'includes/class-message-templates.php';
require_once WOOKAPSO_PATH . 'includes/class-order-hooks.php';
require_once WOOKAPSO_PATH . 'includes/class-order-metrics.php';
require_once WOOKAPSO_PATH . 'includes/class-dashboard-data.php';
require_once WOOKAPSO_PATH . 'includes/class-cron.php';
require_once WOOKAPSO_PATH . 'includes/class-template-manager.php';
require_once WOOKAPSO_PATH . 'includes/class-settings.php';
require_once WOOKAPSO_PATH . 'includes/admin-settings.php';
require_once WOOKAPSO_PATH . 'includes/dashboard.php';

register_activation_hook( __FILE__, 'wookapso_activate' );
function wookapso_activate() {
    WooKapso_Logger::create_table();
}

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>
                <strong>WooKapso:</strong> يتطلب تثبيت وتفعيل WooCommerce أولاً.
            </p></div>';
        } );
        return;
    }
    new WooKapso_Settings();
    new WooKapso_Dashboard();
    new WooKapso_Order_Hooks();
    new WooKapso_Bosta_Admin();
    new WooKapso_Cron();
} );
