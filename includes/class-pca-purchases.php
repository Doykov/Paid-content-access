<?php
/**
 * Purchase records and access grants.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores who paid for which post.
 */
class PCA_Purchases {

	/**
	 * Per-request access lookups.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $cache = array();

	/**
	 * Table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pca_purchases';
	}

	/**
	 * Drop cached access checks after a write.
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Read one purchase.
	 *
	 * @param int $purchase_id Purchase ID.
	 * @return object|null
	 */
	public static function get( $purchase_id ) {
		global $wpdb;

		$purchase_id = (int) $purchase_id;
		if ( $purchase_id < 1 ) {
			return null;
		}

		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $purchase_id ) );
	}

	/**
	 * Whether any paid Stripe sale exists.
	 *
	 * @return bool
	 */
	public static function has_completed_sales() {
		global $wpdb;

		$table = self::table();
		$found = $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'completed' AND granted_by IS NULL LIMIT 1" );
		return ! empty( $found );
	}

	/**
	 * Access state for a member and post.
	 *
	 * @param int $member_id Member ID.
	 * @param int $post_id   Post ID.
	 * @return array{state:string,expires_at:?string}
	 */
	public static function access_for( $member_id, $post_id ) {
		$member_id = (int) $member_id;
		$post_id   = (int) $post_id;
		$key       = $member_id . ':' . $post_id;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$result = array(
			'state'      => 'none',
			'expires_at' => null,
		);

		if ( $member_id < 1 || $post_id < 1 ) {
			self::$cache[ $key ] = $result;
			return $result;
		}

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT expires_at FROM {$table} WHERE member_id = %d AND post_id = %d AND status = %s ORDER BY id DESC LIMIT 30",
				$member_id,
				$post_id,
				'completed'
			)
		);

		$now            = current_time( 'mysql', true );
		$saw_expired    = false;
		$active_expiry  = null;
		$active_forever = false;

		foreach ( (array) $rows as $row ) {
			if ( self::is_lifetime( $row->expires_at ) || (string) $row->expires_at > $now ) {
				if ( self::is_lifetime( $row->expires_at ) ) {
					$active_forever = true;
				} elseif ( null === $active_expiry || (string) $row->expires_at > $active_expiry ) {
					$active_expiry = (string) $row->expires_at;
				}
			} else {
				$saw_expired = true;
			}
		}

		if ( $active_forever || null !== $active_expiry ) {
			$result['state']      = 'granted';
			$result['expires_at'] = $active_forever ? null : $active_expiry;
		} elseif ( $saw_expired ) {
			$result['state'] = 'expired';
		}

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Whether a completed grant is still valid.
	 *
	 * @param int $member_id Member ID.
	 * @param int $post_id   Post ID.
	 * @return bool
	 */
	public static function has_access( $member_id, $post_id ) {
		$access = self::access_for( $member_id, $post_id );
		return 'granted' === $access['state'];
	}

	/**
	 * Latest reusable pending checkout for this member and post.
	 *
	 * @param int    $member_id Member ID.
	 * @param int    $post_id   Post ID.
	 * @param int    $amount    Expected minor units.
	 * @param string $currency  Expected currency code.
	 * @return object|null
	 */
	public static function open_pending( $member_id, $post_id, $amount, $currency ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE member_id = %d AND post_id = %d AND status = %s AND amount = %d AND currency = %s ORDER BY id DESC LIMIT 1",
				(int) $member_id,
				(int) $post_id,
				'pending',
				(int) $amount,
				strtolower( (string) $currency )
			)
		);

		if ( ! $row ) {
			return null;
		}

		$created = strtotime( $row->created_at . ' UTC' );
		if ( ! $created || ( time() - $created ) > 23 * HOUR_IN_SECONDS ) {
			return null;
		}

		return $row;
	}

	/**
	 * Insert a pending purchase and return its ID.
	 *
	 * @param array<string,mixed> $data Purchase fields.
	 * @return int|WP_Error
	 */
	public static function create_pending( $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'member_id'    => (int) $data['member_id'],
				'post_id'      => (int) $data['post_id'],
				'amount'       => (int) $data['amount'],
				'currency'     => strtolower( (string) $data['currency'] ),
				'status'       => 'pending',
				'access_days'  => (int) $data['access_days'],
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'pca_db', __( 'Покупката не можа да бъде записана.', 'paid-content-access' ) );
		}

		self::flush_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Attach a Checkout Session to a pending purchase.
	 *
	 * @param int    $purchase_id Purchase ID.
	 * @param string $session_id  Stripe session ID.
	 * @return void
	 */
	public static function attach_session( $purchase_id, $session_id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'stripe_session_id' => $session_id ),
			array(
				'id'     => (int) $purchase_id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Cancel other pending rows so only one checkout stays open.
	 *
	 * @param int $member_id          Member ID.
	 * @param int $post_id            Post ID.
	 * @param int $except_purchase_id Purchase to keep.
	 */
	public static function cancel_other_pending( $member_id, $post_id, $except_purchase_id ) {
		global $wpdb;

		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE member_id = %d AND post_id = %d AND status = %s AND id != %d",
				'cancelled',
				(int) $member_id,
				(int) $post_id,
				'pending',
				(int) $except_purchase_id
			)
		);
	}

	/**
	 * Grant access from a paid Checkout Session. Safe to call more than once.
	 *
	 * @param array<string,mixed> $session Stripe session object.
	 * @return true|WP_Error
	 */
	public static function fulfill_session( $session ) {
		if ( empty( $session['id'] ) || empty( $session['metadata'] ) || ! is_array( $session['metadata'] ) ) {
			return new WP_Error( 'pca_session', 'Checkout session is missing metadata.' );
		}

		if ( 'paid-content-access' !== ( $session['metadata']['plugin'] ?? '' ) ) {
			return new WP_Error( 'pca_plugin', 'Checkout session belongs to another integration.' );
		}

		if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
			return new WP_Error( 'pca_unpaid', 'Checkout session is not paid.' );
		}

		$purchase_id = isset( $session['metadata']['purchase_id'] ) ? (int) $session['metadata']['purchase_id'] : 0;
		$member_id   = isset( $session['metadata']['member_id'] ) ? (int) $session['metadata']['member_id'] : 0;
		$post_id     = isset( $session['metadata']['post_id'] ) ? (int) $session['metadata']['post_id'] : 0;
		$purchase    = self::get( $purchase_id );

		if ( ! $purchase || (int) $purchase->member_id !== $member_id || (int) $purchase->post_id !== $post_id ) {
			return new WP_Error( 'pca_mismatch', 'Checkout session does not match the purchase.' );
		}

		if ( 'completed' === $purchase->status ) {
			return true;
		}

		if ( 'pending' !== $purchase->status ) {
			return new WP_Error( 'pca_status', 'Purchase can no longer be completed.' );
		}

		$currency = strtolower( (string) ( $session['currency'] ?? '' ) );
		$paid     = isset( $session['amount_total'] ) ? (int) $session['amount_total'] : 0;
		if ( $currency !== strtolower( (string) $purchase->currency ) || $paid < (int) $purchase->amount ) {
			return new WP_Error( 'pca_amount', 'Paid amount does not match the purchase.' );
		}

		$payment_intent = $session['payment_intent'] ?? '';
		if ( is_array( $payment_intent ) ) {
			$payment_intent = $payment_intent['id'] ?? '';
		}
		$payment_intent = is_string( $payment_intent ) ? $payment_intent : '';

		$expires = null;
		if ( (int) $purchase->access_days > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + ( (int) $purchase->access_days * DAY_IN_SECONDS ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array(
				'status'                 => 'completed',
				'stripe_session_id'      => (string) $session['id'],
				'stripe_payment_intent'  => '' !== $payment_intent ? $payment_intent : null,
				'paid_at'                => current_time( 'mysql', true ),
				'expires_at'             => $expires,
			),
			array(
				'id'     => (int) $purchase->id,
				'status' => 'pending',
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'pca_db', 'Purchase update failed.' );
		}

		self::flush_cache();

		if ( 0 === $updated ) {
			$fresh = self::get( (int) $purchase->id );
			if ( $fresh && 'completed' === $fresh->status ) {
				return true;
			}
			return new WP_Error( 'pca_status', 'Purchase was not pending.' );
		}

		/**
		 * Fires after a paid purchase unlocks a post.
		 *
		 * @param int    $purchase_id Purchase ID.
		 * @param object $purchase    Purchase row before completion.
		 */
		do_action( 'pca_access_granted', (int) $purchase->id, $purchase );
		return true;
	}

	/**
	 * Mark a purchase refunded or revoked when Stripe sends a full refund.
	 *
	 * @param string $payment_intent PaymentIntent ID.
	 * @return bool
	 */
	public static function refund_by_payment_intent( $payment_intent ) {
		global $wpdb;

		$payment_intent = (string) $payment_intent;
		if ( ! preg_match( '/^pi_[A-Za-z0-9_]+$/', $payment_intent ) ) {
			return false;
		}

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_payment_intent = %s LIMIT 1", $payment_intent ) );
		if ( ! $row || 'completed' !== $row->status ) {
			return false;
		}

		$updated = $wpdb->update(
			self::table(),
			array( 'status' => 'refunded' ),
			array(
				'id'     => (int) $row->id,
				'status' => 'completed',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated ) {
			self::flush_cache();
			do_action( 'pca_access_revoked', (int) $row->id, $row );
		}

		return (bool) $updated;
	}

	/**
	 * Mark a pending purchase cancelled from a failed or expired session.
	 *
	 * @param int $purchase_id Purchase ID.
	 */
	public static function cancel_pending( $purchase_id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'status' => 'cancelled' ),
			array(
				'id'     => (int) $purchase_id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		self::flush_cache();
	}

	/**
	 * Administrator grant. Fails when the member already has access.
	 *
	 * @param int    $member_id   Member ID.
	 * @param int    $post_id     Post ID.
	 * @param int    $access_days Days, or zero for lifetime.
	 * @param string $note        Internal note.
	 * @return int|WP_Error
	 */
	public static function grant_manual( $member_id, $post_id, $access_days, $note ) {
		if ( self::has_access( $member_id, $post_id ) ) {
			return new WP_Error( 'pca_exists', __( 'Този член вече има активен достъп до публикацията.', 'paid-content-access' ) );
		}

		$expires = null;
		if ( $access_days > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + ( $access_days * DAY_IN_SECONDS ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'member_id'   => (int) $member_id,
				'post_id'     => (int) $post_id,
				'amount'      => 0,
				'currency'    => strtolower( (string) PCA_Settings::get( 'currency' ) ),
				'status'      => 'completed',
				'access_days' => (int) $access_days,
				'granted_by'  => get_current_user_id(),
				'note'        => '' !== $note ? $note : null,
				'created_at'  => current_time( 'mysql', true ),
				'paid_at'     => current_time( 'mysql', true ),
				'expires_at'  => $expires,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'pca_db', __( 'Достъпът не можа да бъде записан.', 'paid-content-access' ) );
		}

		self::flush_cache();
		$purchase_id = (int) $wpdb->insert_id;
		do_action( 'pca_access_granted', $purchase_id, self::get( $purchase_id ) );
		return $purchase_id;
	}

	/**
	 * Revoke a completed grant or cancel a pending checkout.
	 *
	 * @param int $purchase_id Purchase ID.
	 * @return true|WP_Error
	 */
	public static function revoke( $purchase_id ) {
		$purchase = self::get( $purchase_id );
		if ( ! $purchase ) {
			return new WP_Error( 'pca_missing', __( 'Покупката не е намерена.', 'paid-content-access' ) );
		}

		$next = '';
		if ( 'completed' === $purchase->status ) {
			$next = 'revoked';
		} elseif ( 'pending' === $purchase->status ) {
			$next = 'cancelled';
		} else {
			return new WP_Error( 'pca_status', __( 'Тази покупка не може да бъде променена.', 'paid-content-access' ) );
		}

		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => $next ),
			array(
				'id'     => (int) $purchase->id,
				'status' => $purchase->status,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			return new WP_Error( 'pca_db', __( 'Промяната не беше записана.', 'paid-content-access' ) );
		}

		self::flush_cache();
		if ( 'revoked' === $next ) {
			do_action( 'pca_access_revoked', (int) $purchase->id, $purchase );
		}
		return true;
	}

	/**
	 * Paginated purchase list.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public static function query( $args ) {
		global $wpdb;

		$args     = wp_parse_args(
			$args,
			array(
				'paged'     => 1,
				'per_page'  => 20,
				'status'    => '',
				'post_id'   => 0,
				'search'    => '',
			)
		);
		$page     = max( 1, (int) $args['paged'] );
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = array( '1=1' );
		$params   = array();

		$statuses = array( 'pending', 'completed', 'refunded', 'revoked', 'cancelled' );
		if ( in_array( $args['status'], $statuses, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( (int) $args['post_id'] > 0 ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$clauses = array();
			$members = PCA_Member::search_ids( $search );
			$posts   = self::search_post_ids( $search );

			if ( $members ) {
				$clauses[] = 'member_id IN (' . implode( ',', array_map( 'intval', $members ) ) . ')';
			}
			if ( $posts ) {
				$clauses[] = 'post_id IN (' . implode( ',', array_map( 'intval', $posts ) ) . ')';
			}

			$clauses[] = 'stripe_session_id = %s';
			$params[]  = $search;
			$clauses[] = 'stripe_payment_intent = %s';
			$params[]  = $search;

			if ( ctype_digit( $search ) ) {
				$clauses[] = 'id = %d';
				$params[]  = (int) $search;
				$clauses[] = 'member_id = %d';
				$params[]  = (int) $search;
			}

			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		}

		$table = self::table();
		$sql   = "FROM {$table} WHERE " . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) {$sql}";
		$list_sql  = "SELECT * {$sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		if ( $params ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Post IDs whose title matches a search.
	 *
	 * @param string $term Search text.
	 * @return int[]
	 */
	private static function search_post_ids( $term ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s LIMIT 50", $like ) );
		if ( ctype_digit( $term ) ) {
			$ids[] = (int) $term;
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Completed purchase count for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count_for_post( $post_id ) {
		global $wpdb;

		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = %s",
				(int) $post_id,
				'completed'
			)
		);
	}

	/**
	 * Overview numbers.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats() {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );
		$sales = $wpdb->get_results( "SELECT currency, SUM(amount) AS total, COUNT(*) AS sales FROM {$table} WHERE status = 'completed' AND granted_by IS NULL GROUP BY currency" );
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND (expires_at IS NULL OR expires_at = %s OR expires_at > %s)",
				'completed',
				'0000-00-00 00:00:00',
				$now
			)
		);

		return array(
			'sales'  => is_array( $sales ) ? $sales : array(),
			'active' => $active,
			'total'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
		);
	}

	/**
	 * Recent purchases for the overview.
	 *
	 * @param int $limit Row count.
	 * @return object[]
	 */
	public static function recent( $limit = 8 ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Empty or zero dates mean the grant does not expire.
	 *
	 * @param string|null $expires Stored datetime.
	 * @return bool
	 */
	private static function is_lifetime( $expires ) {
		return empty( $expires ) || '0000-00-00 00:00:00' === $expires;
	}
}
