<?php
/**
 * Shared capability checks for WooKapso admin (settings + dashboard).
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Capabilities
 */
final class WooKapso_Capabilities {

	/**
	 * Capability string for register_menu_page / submenu (minimum cap to show in menu).
	 */
	public static function menu_capability(): string {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return 'manage_woocommerce';
		}
		return 'manage_options';
	}

	/**
	 * Whether the current user may configure Kapso (AJAX + page render).
	 */
	public static function can_manage_plugin(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}
}
