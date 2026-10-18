<?php
/**
 * Stripe HTTP API and webhook signatures.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to Stripe without the official SDK or WooCommerce.
 */
class PCA_Stripe {

	/**
	 * Call the Stripe REST API.
	 *
	 * @param string              $method      HTTP method.
	 * @param string              $path        Path under /v1/.
	 * @param array<string,mixed> $params      Form parameters.
	 * @param string              $idempotency Optional idempotency key.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function request( $method, $path, $params = array(), $idempotency = '' ) {
		$secret = (string) PCA_Settings::get( 'stripe_secret_key' );
		if ( ! preg_match( '/^(sk|rk)_(test|live)_/', $secret ) ) {
			return new WP_Error( 'pca_stripe_config', __( 'Stripe не е настроен.', 'paid-content-access' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $secret,
		);
		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => $headers,
		);
		if ( ! empty( $params ) && 'GET' !== $args['method'] ) {
			$args['body'] = $params;
		}

		$response = wp_remote_request( 'https://api.stripe.com/v1/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pca_stripe_http', __( 'Връзката със Stripe не успя.', 'paid-content-access' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'pca_stripe_invalid', __( 'Невалиден отговор от Stripe.', 'paid-content-access' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? sanitize_text_field( (string) $body['error']['message'] ) : __( 'Stripe върна грешка.', 'paid-content-access' );
			return new WP_Error( 'pca_stripe_api', $message, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Retrieve a Checkout Session.
	 *
	 * @param string $session_id Stripe session ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_session( $session_id ) {
		if ( ! self::is_session_id( $session_id ) ) {
			return new WP_Error( 'pca_session', __( 'Невалидна сесия за плащане.', 'paid-content-access' ) );
		}

		return self::request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
	}

	/**
	 * Checkout Session IDs Stripe currently issues.
	 *
	 * @param string $session_id Candidate ID.
	 * @return bool
	 */
	public static function is_session_id( $session_id ) {
		return (bool) preg_match( '/^cs_[A-Za-z0-9_]{8,200}$/', (string) $session_id );
	}

	/**
	 * Accept only Stripe-hosted checkout URLs.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_checkout_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== $parts['scheme'] ) {
			return false;
		}

		$host = strtolower( $parts['host'] );
		return ( 'stripe.com' === $host || substr( $host, -11 ) === '.stripe.com' );
	}

	/**
	 * Verify a webhook signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $header    Stripe-Signature header.
	 * @param string $secret    Webhook signing secret.
	 * @param int    $tolerance Maximum age in seconds.
	 * @return bool
	 */
	public static function verify_signature( $payload, $header, $secret, $tolerance = 300 ) {
		if ( '' === $payload || '' === $header || '' === $secret ) {
			return false;
		}

		$timestamp  = '';
		$signatures = array();
		foreach ( explode( ',', $header ) as $item ) {
			$pair = explode( '=', trim( $item ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( '' === $timestamp || ! ctype_digit( $timestamp ) || empty( $signatures ) ) {
			return false;
		}

		$age = abs( time() - (int) $timestamp );
		if ( $age > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write a debug line without secrets.
	 *
	 * @param string $message Log message.
	 */
	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Paid Content Access] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
