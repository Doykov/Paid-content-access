<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'pca_purchases';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'pca_settings' );
delete_option( 'pca_db_version' );

delete_metadata( 'post', 0, '_pca_enabled', '', true );
delete_metadata( 'post', 0, '_pca_price', '', true );
delete_metadata( 'post', 0, '_pca_access_days', '', true );
delete_metadata( 'post', 0, '_pca_teaser', '', true );
