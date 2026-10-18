<?php
/**
 * Plugin options.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the settings option.
 */
class PCA_Settings {

	const OPTION = 'pca_settings';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'stripe_secret_key'     => '',
			'stripe_webhook_secret' => '',
			'currency'              => 'eur',
			'default_price'         => '',
			'default_access_days'   => 0,
			'post_types'            => array( 'post', 'page' ),
			'login_page_id'         => 0,
			'register_page_id'      => 0,
			'paywall_heading'       => __( 'Допълнително съдържание', 'paid-content-access' ),
			'button_label'          => __( 'Плати със Stripe', 'paid-content-access' ),
			'guest_message'         => __( 'Това съдържание е достъпно само за регистрирани членове. Влезте в профила си или се регистрирайте, след което можете да го закупите.', 'paid-content-access' ),
			'member_message'        => __( 'Това е допълнително съдържание. Заплатете, за да получите достъп до пълната публикация.', 'paid-content-access' ),
			'inactive_message'      => __( 'Членството ви не е активно. Подновете го или се свържете с администратора, преди да закупите това съдържание.', 'paid-content-access' ),
			'expired_message'       => __( 'Срокът на достъпа ви до тази публикация е изтекъл. Можете да го закупите отново.', 'paid-content-access' ),
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = array_merge( self::defaults(), $saved );
		if ( empty( $settings['post_types'] ) || ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'post', 'page' );
		}

		return $settings;
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Public post types the metabox may use.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$public  = get_post_types( array( 'public' => true ), 'names' );
		$allowed = array();

		foreach ( (array) self::get( 'post_types' ) as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( $post_type && isset( $public[ $post_type ] ) && 'attachment' !== $post_type ) {
				$allowed[] = $post_type;
			}
		}

		return $allowed ? $allowed : array( 'post' );
	}

	/**
	 * Whether a usable Stripe secret key is stored.
	 *
	 * @return bool
	 */
	public static function stripe_ready() {
		return (bool) preg_match( '/^(sk|rk)_(test|live)_/', (string) self::get( 'stripe_secret_key' ) );
	}

	/**
	 * Test or live, based on the secret key prefix.
	 *
	 * @return string
	 */
	public static function stripe_mode() {
		$key = (string) self::get( 'stripe_secret_key' );
		if ( false !== strpos( $key, '_live_' ) ) {
			return 'live';
		}
		if ( false !== strpos( $key, '_test_' ) ) {
			return 'test';
		}
		return '';
	}

	/**
	 * Currencies offered in the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function currencies() {
		return array(
			'eur' => 'EUR',
			'bgn' => 'BGN',
			'usd' => 'USD',
			'gbp' => 'GBP',
		);
	}

	/**
	 * Sanitize and store a settings submission.
	 *
	 * @param array<string,mixed> $post Raw request values.
	 * @return string[] Warning messages. An empty list means the save was clean.
	 */
	public static function save( $post ) {
		$current  = self::all();
		$clean    = self::defaults();
		$warnings = array();

		$currency = isset( $post['pca_currency'] ) ? strtolower( sanitize_text_field( wp_unslash( $post['pca_currency'] ) ) ) : 'eur';
		$clean['currency'] = array_key_exists( $currency, self::currencies() ) ? $currency : 'eur';

		if ( $clean['currency'] !== $current['currency'] && class_exists( 'PCA_Purchases' ) && PCA_Purchases::has_completed_sales() ) {
			$warnings[] = __( 'Валутата е сменена, но вече има заплатени покупки. Старите суми остават в предишната валута. Новите цени се тълкуват в новата.', 'paid-content-access' );
		}

		$price = isset( $post['pca_default_price'] ) ? self::sanitize_price( wp_unslash( $post['pca_default_price'] ) ) : '';
		if ( '' !== $price && pca_to_minor_units( $price, $clean['currency'] ) < pca_minimum_amount_minor() ) {
			$warnings[] = __( 'Цената по подразбиране е под минимума на Stripe и не беше записана.', 'paid-content-access' );
			$price      = $current['default_price'];
		}
		$clean['default_price']       = $price;
		$clean['default_access_days'] = isset( $post['pca_default_access_days'] ) ? min( 3650, absint( wp_unslash( $post['pca_default_access_days'] ) ) ) : 0;

		$requested_types = array();
		if ( isset( $post['pca_post_types'] ) && is_array( $post['pca_post_types'] ) ) {
			foreach ( $post['pca_post_types'] as $post_type ) {
				$requested_types[] = sanitize_key( wp_unslash( $post_type ) );
			}
		}
		$clean['post_types'] = self::sanitize_post_types( $requested_types );
		if ( ! $clean['post_types'] ) {
			$clean['post_types'] = $current['post_types'];
			$warnings[]          = __( 'Изберете поне един публичен тип. Запазен е предишният избор.', 'paid-content-access' );
		}

		$clean['login_page_id']    = self::sanitize_page_id( isset( $post['pca_login_page_id'] ) ? wp_unslash( $post['pca_login_page_id'] ) : 0 );
		$clean['register_page_id'] = self::sanitize_page_id( isset( $post['pca_register_page_id'] ) ? wp_unslash( $post['pca_register_page_id'] ) : 0 );

		$clean['paywall_heading']  = self::sanitize_line( isset( $post['pca_paywall_heading'] ) ? wp_unslash( $post['pca_paywall_heading'] ) : '', self::defaults()['paywall_heading'] );
		$clean['button_label']     = self::sanitize_line( isset( $post['pca_button_label'] ) ? wp_unslash( $post['pca_button_label'] ) : '', self::defaults()['button_label'] );
		$defaults = self::defaults();
		foreach ( array( 'guest_message', 'member_message', 'inactive_message', 'expired_message' ) as $message_key ) {
			$raw     = isset( $post[ 'pca_' . $message_key ] ) ? wp_unslash( $post[ 'pca_' . $message_key ] ) : '';
			$message = self::sanitize_message( $raw );
			$clean[ $message_key ] = '' === trim( wp_strip_all_tags( $message ) ) ? $defaults[ $message_key ] : $message;
		}

		$clean['stripe_secret_key']     = self::incoming_secret( $post, 'pca_stripe_secret_key', 'pca_clear_secret', $current['stripe_secret_key'], '/^(sk|rk)_(test|live)_/', __( 'Секретният ключ на Stripe не изглежда валиден и не беше записан.', 'paid-content-access' ), $warnings );
		$clean['stripe_webhook_secret'] = self::incoming_secret( $post, 'pca_stripe_webhook_secret', 'pca_clear_webhook', $current['stripe_webhook_secret'], '/^whsec_/', __( 'Тайната за webhook не започва с whsec_ и не беше записана.', 'paid-content-access' ), $warnings );

		update_option( self::OPTION, $clean );

		if ( self::stripe_ready() ) {
			$balance = PCA_Stripe::request( 'GET', 'balance' );
			if ( is_wp_error( $balance ) ) {
				$warnings[] = sprintf(
					/* translators: %s: Stripe error message shown to an administrator. */
					__( 'Stripe не потвърди ключа: %s', 'paid-content-access' ),
					$balance->get_error_message()
				);
			}
		}

		return $warnings;
	}

	/**
	 * Keep, replace, or clear a secret field.
	 *
	 * @param array<string,mixed> $post      Request.
	 * @param string              $field     Posted field name.
	 * @param string              $clear     Checkbox name.
	 * @param string              $existing  Current stored value.
	 * @param string              $pattern   Validity pattern.
	 * @param string              $invalid   Warning when the new value is rejected.
	 * @param string[]            $warnings  Warning list, passed by reference.
	 * @return string
	 */
	private static function incoming_secret( $post, $field, $clear, $existing, $pattern, $invalid, &$warnings ) {
		if ( ! empty( $post[ $clear ] ) ) {
			return '';
		}

		$raw = isset( $post[ $field ] ) ? wp_unslash( $post[ $field ] ) : '';
		$key = self::normalize_secret( $raw );
		if ( '' === $key ) {
			return (string) $existing;
		}

		if ( ! preg_match( $pattern, $key ) ) {
			$warnings[] = $invalid;
			return (string) $existing;
		}

		return $key;
	}

	/**
	 * Strip whitespace and wrapping quotes from a pasted key.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function normalize_secret( $value ) {
		$key = trim( sanitize_text_field( (string) $value ) );
		$key = preg_replace( '/\s+/', '', $key );
		return trim( (string) $key, "\"'" );
	}

	/**
	 * Decimal price or an empty string.
	 *
	 * @param mixed $value Raw price.
	 * @return string
	 */
	public static function sanitize_price( $value ) {
		$value = str_replace( ',', '.', trim( sanitize_text_field( (string) $value ) ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) ) {
			return '';
		}
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Keep only public post types other than attachments.
	 *
	 * @param string[] $requested Requested post type names.
	 * @return string[]
	 */
	private static function sanitize_post_types( $requested ) {
		$public  = get_post_types( array( 'public' => true ), 'names' );
		$allowed = array();

		foreach ( $requested as $post_type ) {
			if ( $post_type && isset( $public[ $post_type ] ) && 'attachment' !== $post_type ) {
				$allowed[] = $post_type;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Published page ID or zero.
	 *
	 * @param mixed $value Raw page ID.
	 * @return int
	 */
	private static function sanitize_page_id( $value ) {
		$page_id = absint( $value );
		if ( ! $page_id || 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
			return 0;
		}
		return $page_id;
	}

	/**
	 * Single-line label with a fallback.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback text.
	 * @return string
	 */
	private static function sanitize_line( $value, $fallback ) {
		$line = sanitize_text_field( (string) $value );
		return '' === $line ? $fallback : $line;
	}

	/**
	 * Multiline message. Empty values fall back when rendered.
	 *
	 * @param mixed $value Raw message.
	 * @return string
	 */
	private static function sanitize_message( $value ) {
		return wp_kses_post( (string) $value );
	}
}
