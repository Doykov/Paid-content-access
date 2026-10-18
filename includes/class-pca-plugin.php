<?php
/**
 * Plugin bootstrap.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin after dependencies are loaded.
 */
class PCA_Plugin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		load_plugin_textdomain( 'paid-content-access', false, dirname( plugin_basename( PCA_FILE ) ) . '/languages' );

		PCA_Install::maybe_upgrade();
		PCA_Access::hooks();
		PCA_Checkout::hooks();
		PCA_Mail::hooks();
		PCA_Admin::hooks();
	}
}
