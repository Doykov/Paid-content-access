<?php
/**
 * Purchase confirmation email.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the member which post they unlocked and until when.
 */
class PCA_Mail {

	/**
	 * Register hooks.
	 */
	public static function hooks() {
		add_action( 'pca_access_granted', array( __CLASS__, 'send_purchase_email' ), 10, 2 );
	}

	/**
	 * Email the buyer after Stripe confirms the payment.
	 *
	 * Manual grants do not send this message. A repeated fulfillment does not
	 * send it again, because the grant action runs only on the first write.
	 *
	 * @param int         $purchase_id Purchase ID.
	 * @param object|null $purchase    Purchase row from the grant action.
	 */
	public static function send_purchase_email( $purchase_id, $purchase = null ) {
		unset( $purchase );

		$purchase = PCA_Purchases::get( (int) $purchase_id );
		if ( ! $purchase || 'completed' !== $purchase->status || ! empty( $purchase->granted_by ) ) {
			return;
		}

		$profile = PCA_Member::profile( (int) $purchase->member_id );
		if ( ! is_email( $profile['email'] ) ) {
			PCA_Stripe::log( 'Purchase ' . (int) $purchase->id . ' has no member email.' );
			return;
		}

		$post_id = (int) $purchase->post_id;
		$title   = get_the_title( $post_id );
		$title   = is_string( $title ) && '' !== $title ? html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) : sprintf(
			/* translators: %d: post ID. */
			__( 'Публикация #%d', 'paid-content-access' ),
			$post_id
		);
		$url = get_permalink( $post_id );
		$url = is_string( $url ) ? $url : '';

		$access = self::access_line( $purchase->expires_at );
		$amount = pca_format_money( (int) $purchase->amount, (string) $purchase->currency );
		$hello  = '' !== $profile['name']
			? sprintf(
				/* translators: %s: member name. */
				__( 'Здравейте, %s,', 'paid-content-access' ),
				$profile['name']
			)
			: __( 'Здравейте,', 'paid-content-access' );

		$lines = array(
			$hello,
			'',
			__( 'Плащането е прието. Имате достъп до следната публикация:', 'paid-content-access' ),
			'',
			$title,
		);
		if ( '' !== $url ) {
			$lines[] = $url;
		}
		$lines[] = '';
		$lines[] = $access;
		$lines[] = sprintf(
			/* translators: %s: formatted amount paid. */
			__( 'Платена сума: %s', 'paid-content-access' ),
			$amount
		);
		$lines[] = '';
		$lines[] = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );

		$subject = sprintf(
			/* translators: %s: post title. */
			__( 'Достъп до „%s“', 'paid-content-access' ),
			$title
		);
		$message = implode( "\n", $lines );

		/**
		 * Filters the purchase confirmation email before it is sent.
		 *
		 * @param array<string,string> $email    to, subject, and message.
		 * @param object               $purchase Completed purchase row.
		 */
		$email = apply_filters(
			'pca_purchase_email',
			array(
				'to'      => $profile['email'],
				'subject' => $subject,
				'message' => $message,
			),
			$purchase
		);

		$to      = isset( $email['to'] ) ? sanitize_email( $email['to'] ) : '';
		$subject = isset( $email['subject'] ) ? sanitize_text_field( $email['subject'] ) : '';
		$body    = isset( $email['message'] ) ? (string) $email['message'] : '';
		if ( ! is_email( $to ) || '' === $subject || '' === trim( $body ) ) {
			return;
		}

		$sent = wp_mail(
			$to,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		if ( ! $sent ) {
			PCA_Stripe::log( 'Purchase email was not sent for purchase ' . (int) $purchase->id . '.' );
		}
	}

	/**
	 * Describe how long the purchase stays open.
	 *
	 * @param string|null $expires_at GMT datetime, or empty for lifetime access.
	 * @return string
	 */
	private static function access_line( $expires_at ) {
		if ( empty( $expires_at ) || '0000-00-00 00:00:00' === $expires_at ) {
			return __( 'Достъпът е постоянен.', 'paid-content-access' );
		}

		$local = get_date_from_gmt( $expires_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		return sprintf(
			/* translators: %s: local date and time when access ends. */
			__( 'Достъпът е активен до %s.', 'paid-content-access' ),
			$local
		);
	}
}
