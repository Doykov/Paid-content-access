<?php
/**
 * Simple Membership lookups.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the logged-in member without depending on WooCommerce.
 */
class PCA_Member {

	/**
	 * Whether the Simple Membership API is loaded.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'SwpmAuth' ) && class_exists( 'SwpmMemberUtils' );
	}

	/**
	 * Members table, or an empty string when the plugin is absent.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		if ( ! self::is_available() ) {
			return '';
		}

		return $wpdb->prefix . 'swpm_members_tbl';
	}

	/**
	 * Whether the members table can be queried.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		$table = self::table();
		if ( '' === $table ) {
			$exists = false;
			return false;
		}

		global $wpdb;
		$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$exists = ( $found === $table );
		return $exists;
	}

	/**
	 * Current Simple Membership session.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function current() {
		if ( ! self::is_available() ) {
			return null;
		}

		$auth = SwpmAuth::get_instance();
		if ( ! is_object( $auth ) || ! method_exists( $auth, 'is_logged_in' ) || ! $auth->is_logged_in() ) {
			return null;
		}

		$member_id = $auth->get( 'member_id' );
		if ( ! is_numeric( $member_id ) ) {
			return null;
		}

		$state = sanitize_key( (string) $auth->get( 'account_state' ) );

		return array(
			'id'       => (int) $member_id,
			'email'    => sanitize_email( (string) $auth->get( 'email' ) ),
			'username' => sanitize_text_field( (string) $auth->get( 'user_name' ) ),
			'state'    => $state,
			'level'    => (int) $auth->get( 'membership_level' ),
			'active'   => ( 'active' === $state ),
		);
	}

	/**
	 * Find a member by ID, username, or email.
	 *
	 * @param string $query Admin search text.
	 * @return object|null
	 */
	public static function find( $query ) {
		global $wpdb;

		$query = trim( (string) $query );
		if ( '' === $query || ! self::table_exists() ) {
			return null;
		}

		$table = self::table();
		if ( ctype_digit( $query ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT member_id, user_name, email, account_state FROM {$table} WHERE member_id = %d",
					(int) $query
				)
			);
			if ( $row ) {
				return $row;
			}
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT member_id, user_name, email, account_state FROM {$table} WHERE email = %s OR user_name = %s LIMIT 1",
				$query,
				$query
			)
		);
	}

	/**
	 * Member IDs matching a partial name, username, or email.
	 *
	 * @param string $term Search text.
	 * @return int[]
	 */
	public static function search_ids( $term ) {
		global $wpdb;

		$term = trim( (string) $term );
		if ( '' === $term || ! self::table_exists() ) {
			return array();
		}

		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$sql   = "SELECT member_id FROM {$table} WHERE email LIKE %s OR user_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s";
		$args  = array( $like, $like, $like, $like );

		if ( ctype_digit( $term ) ) {
			$sql   .= ' OR member_id = %d';
			$args[] = (int) $term;
		}

		$sql .= ' LIMIT 50';
		$ids  = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Name and email for a member notification.
	 *
	 * @param int $member_id Member ID.
	 * @return array{email:string,name:string}
	 */
	public static function profile( $member_id ) {
		$first = sanitize_text_field( self::field( $member_id, 'first_name' ) );
		$last  = sanitize_text_field( self::field( $member_id, 'last_name' ) );
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = sanitize_text_field( self::field( $member_id, 'user_name' ) );
		}

		return array(
			'email' => sanitize_email( self::field( $member_id, 'email' ) ),
			'name'  => $name,
		);
	}

	/**
	 * One column from the Simple Membership record.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $field     Column name.
	 * @return string
	 */
	private static function field( $member_id, $field ) {
		if ( ! self::is_available() || ! method_exists( 'SwpmMemberUtils', 'get_member_field_by_id' ) ) {
			return '';
		}

		$value = SwpmMemberUtils::get_member_field_by_id( (int) $member_id, $field, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Label for tables and selects.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	public static function label( $member_id ) {
		$member_id = (int) $member_id;
		if ( $member_id < 1 ) {
			return '—';
		}

		if ( self::is_available() && method_exists( 'SwpmMemberUtils', 'get_member_field_by_id' ) ) {
			$name  = SwpmMemberUtils::get_member_field_by_id( $member_id, 'user_name', '' );
			$email = SwpmMemberUtils::get_member_field_by_id( $member_id, 'email', '' );
			$name  = is_string( $name ) ? $name : '';
			$email = is_string( $email ) ? $email : '';
			if ( '' !== $name && '' !== $email ) {
				return $name . ' (' . $email . ')';
			}
			if ( '' !== $name || '' !== $email ) {
				return '' !== $name ? $name : $email;
			}
		}

		return sprintf(
			/* translators: %d: Simple Membership member ID. */
			__( 'Член #%d', 'paid-content-access' ),
			$member_id
		);
	}

	/**
	 * Login URL from the plugin settings, then from Simple Membership.
	 *
	 * @return string
	 */
	public static function login_url() {
		return self::page_url( 'login' );
	}

	/**
	 * Registration URL from the plugin settings, then from Simple Membership.
	 *
	 * @return string
	 */
	public static function register_url() {
		return self::page_url( 'register' );
	}

	/**
	 * Resolve a membership page URL.
	 *
	 * @param string $which login|register.
	 * @return string
	 */
	private static function page_url( $which ) {
		$page_id = (int) ( 'login' === $which ? PCA_Settings::get( 'login_page_id' ) : PCA_Settings::get( 'register_page_id' ) );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			return is_string( $url ) ? $url : '';
		}

		if ( self::is_available() && class_exists( 'SwpmSettings' ) ) {
			$settings = SwpmSettings::get_instance();
			$key      = ( 'login' === $which ) ? 'login-page-url' : 'registration-page-url';
			if ( is_object( $settings ) && method_exists( $settings, 'get_value' ) ) {
				$url = $settings->get_value( $key );
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}
		}

		if ( 'login' === $which ) {
			return wp_login_url();
		}

		return '';
	}
}
