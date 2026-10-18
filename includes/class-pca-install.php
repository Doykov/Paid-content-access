<?php
/**
 * Activation and database schema.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the purchases table.
 */
class PCA_Install {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_table();

		if ( false === get_option( PCA_Settings::OPTION, false ) ) {
			add_option( PCA_Settings::OPTION, PCA_Settings::defaults() );
		}

		update_option( 'pca_db_version', PCA_DB_VERSION );
	}

	/**
	 * Create or update the table when the schema version changes.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'pca_db_version' ) === (string) PCA_DB_VERSION ) {
			return;
		}

		self::create_table();
		update_option( 'pca_db_version', PCA_DB_VERSION );
	}

	/**
	 * Install the purchases table via dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'pca_purchases';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			amount bigint(20) unsigned NOT NULL DEFAULT 0,
			currency varchar(3) NOT NULL DEFAULT 'eur',
			status varchar(20) NOT NULL DEFAULT 'pending',
			access_days smallint(5) unsigned NOT NULL DEFAULT 0,
			stripe_session_id varchar(191) DEFAULT NULL,
			stripe_payment_intent varchar(191) DEFAULT NULL,
			granted_by bigint(20) unsigned DEFAULT NULL,
			note text DEFAULT NULL,
			created_at datetime NOT NULL,
			paid_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY member_post (member_id,post_id),
			KEY post_status (post_id,status),
			KEY stripe_session_id (stripe_session_id),
			KEY stripe_payment_intent (stripe_payment_intent)
		) {$charset};";

		dbDelta( $sql );
	}
}
