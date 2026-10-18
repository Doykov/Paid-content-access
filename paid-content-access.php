<?php
/**
 * Plugin Name: Платено съдържание (Simple Membership)
 * Description: Допълнение към Simple Membership. Маркирайте публикации като допълнително съдържание и продавайте достъп през Stripe само на вече регистрирани членове.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Paid Content Access
 * Text Domain: paid-content-access
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCA_VERSION', '1.0.1' );
define( 'PCA_DB_VERSION', '1' );
define( 'PCA_FILE', __FILE__ );
define( 'PCA_PATH', plugin_dir_path( __FILE__ ) );
define( 'PCA_URL', plugin_dir_url( __FILE__ ) );

require_once PCA_PATH . 'includes/functions.php';
require_once PCA_PATH . 'includes/class-pca-install.php';
require_once PCA_PATH . 'includes/class-pca-settings.php';
require_once PCA_PATH . 'includes/class-pca-member.php';
require_once PCA_PATH . 'includes/class-pca-purchases.php';
require_once PCA_PATH . 'includes/class-pca-stripe.php';
require_once PCA_PATH . 'includes/class-pca-access.php';
require_once PCA_PATH . 'includes/class-pca-checkout.php';
require_once PCA_PATH . 'includes/class-pca-mail.php';
require_once PCA_PATH . 'includes/class-pca-admin.php';
require_once PCA_PATH . 'includes/class-pca-plugin.php';

register_activation_hook( __FILE__, array( 'PCA_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'PCA_Plugin', 'init' ) );
