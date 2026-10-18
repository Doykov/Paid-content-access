<?php
/**
 * Stripe Checkout and webhooks.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts a payment and records it after Stripe confirms it.
 */
class PCA_Checkout {

	/**
	 * Register payment endpoints.
	 */
	public static function hooks() {
		add_action( 'wp_ajax_pca_create_checkout', array( __CLASS__, 'ajax_checkout' ) );
		add_action( 'wp_ajax_nopriv_pca_create_checkout', array( __CLASS__, 'ajax_checkout' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_return' ) );
	}

	/**
	 * Public webhook. Authentication is the Stripe signature.
	 */
	public static function register_webhook() {
		register_rest_route(
			'paid-content-access/v1',
			'/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Create a Checkout Session for the logged-in member.
	 */
	public static function ajax_checkout() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pca_checkout' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Сесията ви е изтекла. Презаредете страницата.', 'paid-content-access' ) ),
				403
			);
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$result  = self::create_session( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'url' => $result ) );
	}

	/**
	 * Build or reuse a Checkout Session.
	 *
	 * The price always comes from the post, never from the browser.
	 *
	 * @param int $post_id Post ID.
	 * @return string|WP_Error Checkout URL.
	 */
	public static function create_session( $post_id ) {
		if ( ! PCA_Member::is_available() ) {
			return new WP_Error( 'pca_membership', __( 'Членството не е налично.', 'paid-content-access' ) );
		}
		if ( ! PCA_Settings::stripe_ready() ) {
			return new WP_Error( 'pca_stripe', __( 'Плащането не е настроено.', 'paid-content-access' ) );
		}

		$member = PCA_Member::current();
		if ( ! $member ) {
			return new WP_Error( 'pca_guest', __( 'Трябва да влезете като регистриран член, преди да платите.', 'paid-content-access' ) );
		}
		if ( empty( $member['active'] ) ) {
			return new WP_Error( 'pca_inactive', __( 'Членството ви не е активно.', 'paid-content-access' ) );
		}

		$limited = self::rate_limit( (int) $member['id'] );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! PCA_Access::is_enabled( $post_id ) ) {
			return new WP_Error( 'pca_post', __( 'Тази публикация не се продава.', 'paid-content-access' ) );
		}

		if ( PCA_Purchases::has_access( (int) $member['id'], $post_id ) ) {
			return new WP_Error( 'pca_owned', __( 'Вече имате достъп. Презаредете страницата.', 'paid-content-access' ) );
		}

		$config = PCA_Access::config( $post_id );
		if ( (int) $config['amount'] < pca_minimum_amount_minor() ) {
			return new WP_Error( 'pca_price', __( 'Цената на публикацията не е зададена.', 'paid-content-access' ) );
		}

		$pending     = PCA_Purchases::open_pending( (int) $member['id'], $post_id, (int) $config['amount'], (string) $config['currency'] );
		$purchase_id = 0;

		if ( $pending && ! empty( $pending->stripe_session_id ) ) {
			$existing = PCA_Stripe::get_session( $pending->stripe_session_id );
			if ( is_wp_error( $existing ) ) {
				PCA_Stripe::log( 'Could not reuse session for purchase ' . (int) $pending->id . ': ' . $existing->get_error_message() );
				return new WP_Error( 'pca_stripe', __( 'Плащането не можа да започне. Опитайте отново по-късно.', 'paid-content-access' ) );
			}
			$managed = $existing['managed_payments']['enabled'] ?? null;
			if ( 'open' === ( $existing['status'] ?? '' ) && ! empty( $existing['url'] ) && PCA_Stripe::is_checkout_url( $existing['url'] ) && true !== $managed && 'true' !== $managed ) {
				return $existing['url'];
			}
			if ( 'paid' === ( $existing['payment_status'] ?? '' ) ) {
				PCA_Purchases::fulfill_session( $existing );
				return new WP_Error( 'pca_owned', __( 'Плащането вече е прието. Презаредете страницата.', 'paid-content-access' ) );
			}

			PCA_Purchases::cancel_pending( (int) $pending->id );
			$pending = null;
		}

		if ( $pending && empty( $pending->stripe_session_id ) ) {
			$purchase_id = (int) $pending->id;
		} else {
			$purchase_id = PCA_Purchases::create_pending(
				array(
					'member_id'   => (int) $member['id'],
					'post_id'     => $post_id,
					'amount'      => (int) $config['amount'],
					'currency'    => $config['currency'],
					'access_days' => (int) $config['days'],
				)
			);
		}
		if ( is_wp_error( $purchase_id ) ) {
			return $purchase_id;
		}

		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return new WP_Error( 'pca_post', __( 'Публикацията няма адрес.', 'paid-content-access' ) );
		}

		$success = add_query_arg( 'pca_checkout', 'success', $permalink );
		$success .= ( false === strpos( $success, '?' ) ? '?' : '&' ) . 'session_id={CHECKOUT_SESSION_ID}';
		$cancel   = add_query_arg( 'pca_checkout', 'cancel', $permalink );
		$title    = html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$title    = wp_html_excerpt( wp_strip_all_tags( $title ), 120, '…' );
		$locale = ( 0 === strpos( get_locale(), 'bg' ) ) ? 'bg' : 'auto';

		$params = array(
			'mode'                => 'payment',
			'success_url'         => $success,
			'cancel_url'          => $cancel,
			'client_reference_id' => (string) $purchase_id,
			'locale'              => $locale,
			// String, not boolean: Stripe must receive the literal "false".
			'managed_payments'    => array(
				'enabled' => 'false',
			),
			'metadata'            => array(
				'plugin'      => 'paid-content-access',
				'purchase_id' => (string) $purchase_id,
				'member_id'   => (string) $member['id'],
				'post_id'     => (string) $post_id,
			),
			'payment_intent_data' => array(
				'metadata' => array(
					'plugin'      => 'paid-content-access',
					'purchase_id' => (string) $purchase_id,
					'member_id'   => (string) $member['id'],
					'post_id'     => (string) $post_id,
				),
			),
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( (string) $config['currency'] ),
						'unit_amount'  => (int) $config['amount'],
						'product_data' => array(
							'name'        => $title,
							'description' => __( 'Достъп до допълнително съдържание', 'paid-content-access' ),
						),
					),
				),
			),
		);

		if ( is_email( $member['email'] ) ) {
			$params['customer_email'] = $member['email'];
		}

		/**
		 * Filters the Stripe Checkout Session parameters.
		 *
		 * @param array<string,mixed> $params      Session parameters.
		 * @param int                 $post_id     Post ID.
		 * @param int                 $purchase_id Purchase ID.
		 */
		$params  = apply_filters( 'pca_checkout_session_args', $params, $post_id, $purchase_id );
		$session = PCA_Stripe::request( 'POST', 'checkout/sessions', $params, 'pca-purchase-' . $purchase_id );

		if ( is_wp_error( $session ) ) {
			if ( 'pca_stripe_http' !== $session->get_error_code() ) {
				PCA_Purchases::cancel_pending( (int) $purchase_id );
			}
			PCA_Stripe::log( 'Checkout session failed for purchase ' . $purchase_id . ': ' . $session->get_error_message() );
			return new WP_Error( 'pca_stripe', __( 'Плащането не можа да започне. Опитайте отново по-късно.', 'paid-content-access' ) );
		}

		if ( empty( $session['id'] ) || empty( $session['url'] ) || ! PCA_Stripe::is_checkout_url( $session['url'] ) ) {
			PCA_Purchases::cancel_pending( (int) $purchase_id );
			return new WP_Error( 'pca_stripe', __( 'Stripe не върна адрес за плащане.', 'paid-content-access' ) );
		}

		PCA_Purchases::attach_session( $purchase_id, (string) $session['id'] );
		PCA_Purchases::cancel_other_pending( (int) $member['id'], $post_id, $purchase_id );
		return (string) $session['url'];
	}

	/**
	 * Confirm the session when Stripe sends the member back.
	 */
	public static function handle_return() {
		if ( empty( $_GET['pca_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stripe return URL, confirmed via the API.
			return;
		}

		$result  = sanitize_key( wp_unslash( $_GET['pca_checkout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = (int) get_queried_object_id();
		$target  = get_permalink( $post_id );
		if ( ! is_string( $target ) || '' === $target ) {
			return;
		}

		if ( 'cancel' === $result ) {
			wp_safe_redirect( add_query_arg( 'pca_notice', 'cancel', $target ) );
			exit;
		}

		if ( 'success' !== $result ) {
			return;
		}

		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session    = PCA_Stripe::get_session( $session_id );
		if ( is_wp_error( $session ) ) {
			PCA_Stripe::log( 'Return verification failed: ' . $session->get_error_message() );
			wp_safe_redirect( add_query_arg( 'pca_notice', 'error', $target ) );
			exit;
		}

		$meta_post = isset( $session['metadata']['post_id'] ) ? (int) $session['metadata']['post_id'] : 0;
		if ( $meta_post !== $post_id ) {
			wp_safe_redirect( add_query_arg( 'pca_notice', 'error', $target ) );
			exit;
		}

		if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
			$notice = ( 'complete' === ( $session['status'] ?? '' ) ) ? 'processing' : 'error';
			wp_safe_redirect( add_query_arg( 'pca_notice', $notice, $target ) );
			exit;
		}

		$fulfilled = PCA_Purchases::fulfill_session( $session );
		$notice    = is_wp_error( $fulfilled ) ? 'error' : 'success';
		if ( is_wp_error( $fulfilled ) ) {
			PCA_Stripe::log( 'Fulfillment failed for ' . $session_id . ': ' . $fulfilled->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( 'pca_notice', $notice, $target ) );
		exit;
	}

	/**
	 * Receive Stripe events.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$header  = (string) $request->get_header( 'stripe_signature' );
		if ( '' === $header && isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) && is_string( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) {
			$header = wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] );
		}
		$secret = (string) PCA_Settings::get( 'stripe_webhook_secret' );

		if ( ! PCA_Stripe::verify_signature( $payload, $header, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$type   = (string) $event['type'];
		$object = $event['data']['object'];

		if ( 'checkout.session.completed' === $type || 'checkout.session.async_payment_succeeded' === $type ) {
			return self::fulfill_webhook_session( $object );
		}

		if ( 'checkout.session.async_payment_failed' === $type || 'checkout.session.expired' === $type ) {
			$purchase_id = isset( $object['metadata']['purchase_id'] ) ? (int) $object['metadata']['purchase_id'] : 0;
			if ( $purchase_id && 'paid-content-access' === ( $object['metadata']['plugin'] ?? '' ) ) {
				PCA_Purchases::cancel_pending( $purchase_id );
			}
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		if ( 'charge.refunded' === $type && ! empty( $object['refunded'] ) ) {
			$payment_intent = isset( $object['payment_intent'] ) ? (string) $object['payment_intent'] : '';
			PCA_Purchases::refund_by_payment_intent( $payment_intent );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Re-read the session from Stripe before granting access.
	 *
	 * @param array<string,mixed> $object Event object.
	 * @return WP_REST_Response
	 */
	private static function fulfill_webhook_session( $object ) {
		$session_id = isset( $object['id'] ) ? (string) $object['id'] : '';
		if ( 'paid-content-access' !== ( $object['metadata']['plugin'] ?? '' ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$session = PCA_Stripe::get_session( $session_id );
		if ( is_wp_error( $session ) ) {
			PCA_Stripe::log( 'Webhook could not retrieve ' . $session_id . ': ' . $session->get_error_message() );
			return new WP_REST_Response( array( 'error' => 'retry' ), 500 );
		}

		if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$result = PCA_Purchases::fulfill_session( $session );
		if ( is_wp_error( $result ) && 'pca_db' === $result->get_error_code() ) {
			return new WP_REST_Response( array( 'error' => 'retry' ), 500 );
		}
		if ( is_wp_error( $result ) ) {
			PCA_Stripe::log( 'Webhook fulfillment skipped for ' . $session_id . ': ' . $result->get_error_message() );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Slow repeated checkout attempts from one member.
	 *
	 * @param int $member_id Member ID.
	 * @return true|WP_Error
	 */
	private static function rate_limit( $member_id ) {
		$key   = 'pca_checkout_' . $member_id;
		$count = (int) get_transient( $key );
		if ( $count > 15 ) {
			return new WP_Error( 'pca_rate', __( 'Твърде много опити. Изчакайте няколко минути.', 'paid-content-access' ) );
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
