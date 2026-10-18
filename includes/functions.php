<?php
/**
 * Template tags and formatting helpers.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a post is marked as extra paid content.
 *
 * @param int $post_id Post ID. Defaults to the current post.
 * @return bool
 */
function pca_is_paid_post( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id || ! class_exists( 'PCA_Access' ) ) {
		return false;
	}
	return PCA_Access::is_enabled( $post_id );
}

/**
 * Whether the current Simple Membership member can read a paid post.
 *
 * @param int $post_id Post ID. Defaults to the current post.
 * @return bool
 */
function pca_current_member_has_access( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id || ! class_exists( 'PCA_Access' ) ) {
		return false;
	}
	return PCA_Access::current_visitor_can_read( $post_id );
}

/**
 * Decimal places Stripe uses for a currency.
 *
 * @param string $currency ISO currency code.
 * @return int
 */
function pca_currency_decimals( $currency ) {
	$zero_decimal = array(
		'bif',
		'clp',
		'djf',
		'gnf',
		'jpy',
		'kmf',
		'krw',
		'mga',
		'pyg',
		'rwf',
		'ugx',
		'vnd',
		'vuv',
		'xaf',
		'xof',
		'xpf',
	);

	return in_array( strtolower( (string) $currency ), $zero_decimal, true ) ? 0 : 2;
}

/**
 * Convert a decimal price to Stripe minor units.
 *
 * @param string|float $amount   Price such as 9.90.
 * @param string       $currency ISO currency code.
 * @return int
 */
function pca_to_minor_units( $amount, $currency ) {
	$normalized = str_replace( ',', '.', trim( (string) $amount ) );
	if ( ! is_numeric( $normalized ) ) {
		return 0;
	}

	$factor = pow( 10, pca_currency_decimals( $currency ) );
	return (int) round( (float) $normalized * $factor );
}

/**
 * Format minor units for display.
 *
 * @param int    $minor    Amount in minor units.
 * @param string $currency ISO currency code.
 * @return string
 */
function pca_format_money( $minor, $currency ) {
	$decimals = pca_currency_decimals( $currency );
	$factor   = pow( 10, $decimals );
	$value    = number_format_i18n( ( (int) $minor ) / $factor, $decimals );

	return $value . ' ' . strtoupper( (string) $currency );
}

/**
 * Smallest charge this plugin will send to Stripe.
 *
 * @return int
 */
function pca_minimum_amount_minor() {
	return 50;
}

/**
 * Human label for a purchase status.
 *
 * @param string $status Status slug.
 * @return string
 */
function pca_status_label( $status ) {
	$labels = array(
		'pending'   => __( 'Изчаква плащане', 'paid-content-access' ),
		'completed' => __( 'Платен', 'paid-content-access' ),
		'refunded'  => __( 'Възстановен', 'paid-content-access' ),
		'revoked'   => __( 'Отнет достъп', 'paid-content-access' ),
		'cancelled' => __( 'Отказан', 'paid-content-access' ),
	);

	return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
}
