<?php
/**
 * Front-end content protection.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces paid post content until the visitor has a valid purchase.
 */
class PCA_Access {

	const META_ENABLED = '_pca_enabled';
	const META_PRICE   = '_pca_price';
	const META_DAYS    = '_pca_access_days';
	const META_TEASER  = '_pca_teaser';

	/**
	 * Prevents nested content filters from looping.
	 *
	 * @var bool
	 */
	private static $filtering = false;

	/**
	 * Whether a paywall was rendered during this request.
	 *
	 * @var bool
	 */
	private static $paywall_rendered = false;

	/**
	 * Whether the inline stylesheet was printed.
	 *
	 * @var bool
	 */
	private static $css_printed = false;

	/**
	 * Register front-end hooks.
	 */
	public static function hooks() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 9999 );
		add_action( 'wp', array( __CLASS__, 'send_nocache_headers' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'wp_footer', array( __CLASS__, 'enqueue_script' ), 1 );
	}

	/**
	 * Whether the post is for sale.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_enabled( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, self::META_ENABLED, true );
	}

	/**
	 * Price, duration, and teaser for a paid post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function config( $post_id ) {
		$currency = (string) PCA_Settings::get( 'currency' );
		$price    = get_post_meta( $post_id, self::META_PRICE, true );
		$price    = is_string( $price ) ? PCA_Settings::sanitize_price( $price ) : '';
		if ( '' === $price ) {
			$price = PCA_Settings::sanitize_price( (string) PCA_Settings::get( 'default_price' ) );
		}

		$days = get_post_meta( $post_id, self::META_DAYS, true );
		if ( '' === $days || false === $days ) {
			$days = (int) PCA_Settings::get( 'default_access_days' );
		} else {
			$days = (int) $days;
		}

		$teaser = get_post_meta( $post_id, self::META_TEASER, true );
		if ( ! is_string( $teaser ) || '' === trim( $teaser ) ) {
			$excerpt = get_post_field( 'post_excerpt', $post_id );
			$teaser  = is_string( $excerpt ) ? $excerpt : '';
		}

		return array(
			'enabled'  => self::is_enabled( $post_id ),
			'price'    => PCA_Settings::sanitize_price( $price ),
			'amount'   => pca_to_minor_units( $price, $currency ),
			'currency' => $currency,
			'days'     => max( 0, $days ),
			'teaser'   => $teaser,
		);
	}

	/**
	 * Staff, or a member with a valid purchase, may read the post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function current_visitor_can_read( $post_id ) {
		if ( ! self::is_enabled( $post_id ) ) {
			return true;
		}
		if ( self::force_paywall_preview( $post_id ) ) {
			return false;
		}
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		$member = PCA_Member::current();
		if ( ! $member || empty( $member['active'] ) ) {
			return false;
		}

		return PCA_Purchases::has_access( (int) $member['id'], $post_id );
	}

	/**
	 * Ask page caches not to store a paid post.
	 */
	public static function send_nocache_headers() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! self::is_enabled( $post_id ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'paid content access' );
	}

	/**
	 * Replace protected content with the paywall.
	 *
	 * @param string $content Original content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( self::$filtering || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ! self::is_enabled( $post->ID ) ) {
			return $content;
		}

		self::$filtering = true;
		$notice          = self::notice_html();
		$preview         = self::force_paywall_preview( $post->ID );

		if ( ! $preview && current_user_can( 'edit_post', $post->ID ) ) {
			$html = $notice . self::staff_note( $post->ID ) . $content;
			self::$filtering = false;
			return $html;
		}

		$member = PCA_Member::current();
		$access = ( $member && ! empty( $member['active'] ) && ! $preview )
			? PCA_Purchases::access_for( (int) $member['id'], $post->ID )
			: array(
				'state'      => 'none',
				'expires_at' => null,
			);

		if ( 'granted' === $access['state'] && ! $preview ) {
			$html = $notice . self::expiry_note( $access['expires_at'] ) . $content;
			/**
			 * Filters the content shown to a visitor who already has access.
			 *
			 * @param string $html    Content HTML.
			 * @param int    $post_id Post ID.
			 */
			$html = apply_filters( 'pca_protected_content', $html, $post->ID );
			self::$filtering = false;
			return $html;
		}

		$state = self::paywall_state( $member, $access, $preview );
		$html  = $notice . self::locked_preview( $post ) . self::paywall_html( $post, $state, $preview );
		$html  = apply_filters( 'pca_paywall_html', $html, $post->ID, $state );
		self::$filtering = false;
		return $html;
	}

	/**
	 * Register the checkout script. It is printed only when a paywall needs it.
	 */
	public static function register_script() {
		wp_register_script( 'pca-frontend', PCA_URL . 'assets/js/frontend.js', array(), PCA_VERSION, true );
	}

	/**
	 * Enqueue the checkout script in the footer after a buy button was rendered.
	 */
	public static function enqueue_script() {
		if ( ! self::$paywall_rendered ) {
			return;
		}
		if ( ! wp_script_is( 'pca-frontend', 'registered' ) ) {
			self::register_script();
		}
		if ( wp_script_is( 'pca-frontend', 'enqueued' ) || wp_script_is( 'pca-frontend', 'done' ) ) {
			return;
		}

		wp_enqueue_script( 'pca-frontend' );
		wp_localize_script(
			'pca-frontend',
			'pcaPaywall',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'pca_checkout' ),
				'genericError' => __( 'Плащането не можа да започне. Опитайте отново.', 'paid-content-access' ),
			)
		);
	}

	/**
	 * Editors can add ?pca_view=paywall to see the locked state.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function force_paywall_preview( $post_id ) {
		if ( ! isset( $_GET['pca_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flag.
			return false;
		}

		$view = sanitize_key( wp_unslash( $_GET['pca_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'paywall' === $view && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Which paywall variant to show.
	 *
	 * @param array<string,mixed>|null $member  Current member.
	 * @param array<string,mixed>      $access  Access lookup.
	 * @param bool                     $preview Forced preview.
	 * @return string
	 */
	private static function paywall_state( $member, $access, $preview ) {
		if ( $preview ) {
			return 'preview';
		}
		if ( ! PCA_Member::is_available() ) {
			return 'unavailable';
		}
		if ( ! $member ) {
			return 'guest';
		}
		if ( empty( $member['active'] ) ) {
			return 'inactive';
		}
		if ( 'expired' === $access['state'] ) {
			return 'expired';
		}
		if ( ! PCA_Settings::stripe_ready() ) {
			return 'unconfigured';
		}
		return 'locked';
	}

	/**
	 * Banner after returning from Stripe.
	 *
	 * @return string
	 */
	private static function notice_html() {
		if ( ! isset( $_GET['pca_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our redirect.
			return '';
		}

		$notice = sanitize_key( wp_unslash( $_GET['pca_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'success'    => __( 'Плащането е прието. Достъпът до публикацията е отключен.', 'paid-content-access' ),
			'processing' => __( 'Плащането се обработва. Достъпът ще се отключи, когато Stripe потвърди плащането.', 'paid-content-access' ),
			'cancel'     => __( 'Плащането е прекъснато. Не е направено заплащане.', 'paid-content-access' ),
			'error'      => __( 'Плащането не можа да бъде потвърдено. Ако сумата е удържана, достъпът ще се отключи след потвърждение от Stripe.', 'paid-content-access' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return '';
		}

		$class = 'pca-notice';
		if ( 'error' === $notice ) {
			$class .= ' pca-notice--error';
		} elseif ( 'cancel' === $notice || 'processing' === $notice ) {
			$class .= ' pca-notice--pending';
		}

		return '<p class="' . esc_attr( $class ) . '" role="status">' . esc_html( $messages[ $notice ] ) . '</p>';
	}

	/**
	 * Reminder shown to people who can edit the post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function staff_note( $post_id ) {
		if ( ! is_singular() ) {
			return '';
		}

		$link = get_permalink( $post_id );
		$text = __( 'Тази публикация е допълнително платено съдържание. Посетителите без покупка виждат стена за плащане.', 'paid-content-access' );
		$html = '<p class="pca-staff-note">' . esc_html( $text );
		if ( is_string( $link ) && 'publish' === get_post_status( $post_id ) ) {
			$url   = add_query_arg( 'pca_view', 'paywall', $link );
			$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Преглед на стената', 'paid-content-access' ) . '</a>';
		}
		$html .= '</p>';
		return $html;
	}

	/**
	 * Tell a buyer when a time-limited grant ends.
	 *
	 * @param string|null $expires_at GMT datetime.
	 * @return string
	 */
	private static function expiry_note( $expires_at ) {
		if ( empty( $expires_at ) || ! is_singular() ) {
			return '';
		}

		$local = get_date_from_gmt( $expires_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		return '<p class="pca-staff-note">' . esc_html(
			sprintf(
				/* translators: %s: local date and time when access ends. */
				__( 'Достъпът ви до тази публикация е активен до %s.', 'paid-content-access' ),
				$local
			)
		) . '</p>';
	}

	/**
	 * Paywall markup. The paid post content is not included.
	 *
	 * @param WP_Post $post    Post object.
	 * @param string  $state   Paywall variant.
	 * @param bool    $preview Forced preview.
	 * @return string
	 */
	private static function paywall_html( $post, $state, $preview ) {
		$config  = self::config( $post->ID );
		$heading = (string) PCA_Settings::get( 'paywall_heading' );
		$days    = (int) $config['days'];
		$period  = $days > 0
			? sprintf(
				/* translators: %d: number of days of access. */
				_n( 'Достъп за %d ден', 'Достъп за %d дни', $days, 'paid-content-access' ),
				$days
			)
			: __( 'Постоянен достъп', 'paid-content-access' );

		$message = self::message_for_state( $state );
		$actions = self::actions_html( $post->ID, $state );

		self::$paywall_rendered = ( 'locked' === $state || 'expired' === $state );

		$html  = self::inline_css();
		$html .= '<section class="pca-paywall" aria-label="' . esc_attr( $heading ) . '">';
		if ( $preview ) {
			$html .= '<p class="pca-staff-note">' . esc_html__( 'Това е преглед. Посетителите без достъп виждат тази стена вместо публикацията.', 'paid-content-access' ) . '</p>';
		}
		$teaser = self::format_text( (string) $config['teaser'] );
		if ( '' !== $teaser ) {
			$html .= '<div class="pca-paywall__teaser">' . $teaser . '</div>';
		}
		$html .= '<div class="pca-paywall__panel">';
		$html .= '<p class="pca-paywall__kicker">' . esc_html__( 'Само за регистрирани членове', 'paid-content-access' ) . '</p>';
		$html .= '<h2 class="pca-paywall__title">' . esc_html( $heading ) . '</h2>';
		if ( $config['amount'] >= pca_minimum_amount_minor() ) {
			$html .= '<p class="pca-paywall__price">' . esc_html( pca_format_money( (int) $config['amount'], (string) $config['currency'] ) ) . '</p>';
		}
		$html .= '<p class="pca-paywall__meta">' . esc_html( $period ) . '</p>';
		$html .= '<div class="pca-paywall__message">' . self::format_text( $message ) . '</div>';
		$html .= $actions;
		$html .= '</div></section>';

		return $html;
	}

	/**
	 * Message copy for a paywall variant.
	 *
	 * @param string $state Paywall variant.
	 * @return string
	 */
	private static function message_for_state( $state ) {
		$settings = PCA_Settings::all();
		if ( 'guest' === $state || 'preview' === $state ) {
			return (string) $settings['guest_message'];
		}
		if ( 'inactive' === $state ) {
			return (string) $settings['inactive_message'];
		}
		if ( 'expired' === $state ) {
			return (string) $settings['expired_message'];
		}
		if ( 'unavailable' === $state ) {
			return __( 'Продажбата временно не е достъпна, защото членството не е включено.', 'paid-content-access' );
		}
		if ( 'unconfigured' === $state ) {
			return __( 'Плащането още не е настроено. Моля, опитайте по-късно.', 'paid-content-access' );
		}
		return (string) $settings['member_message'];
	}

	/**
	 * Buttons under the paywall message.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $state   Paywall variant.
	 * @return string
	 */
	private static function actions_html( $post_id, $state ) {
		$html = '<div class="pca-paywall__actions">';

		if ( 'locked' === $state || 'expired' === $state ) {
			$label = (string) PCA_Settings::get( 'button_label' );
			$html .= '<button type="button" class="pca-button pca-buy" data-post-id="' . esc_attr( (string) $post_id ) . '">' . esc_html( $label ) . '</button>';
			$html .= '<noscript><p class="pca-paywall__error">' . esc_html__( 'За плащане е нужен JavaScript.', 'paid-content-access' ) . '</p></noscript>';
		}

		if ( 'guest' === $state || 'preview' === $state || 'inactive' === $state ) {
			$login = PCA_Member::login_url();
			if ( $login ) {
				$html .= '<a class="pca-button" href="' . esc_url( $login ) . '">' . esc_html__( 'Вход', 'paid-content-access' ) . '</a>';
			}
			$register = PCA_Member::register_url();
			if ( $register ) {
				$html .= '<a class="pca-button pca-button--ghost" href="' . esc_url( $register ) . '">' . esc_html__( 'Регистрация', 'paid-content-access' ) . '</a>';
			}
		}

		$html .= '</div><p class="pca-paywall__error" data-pca-error hidden></p>';
		return $html;
	}

	/**
	 * Plain text becomes paragraphs. Existing markup is limited to post HTML.
	 *
	 * @param string $text Message or teaser.
	 * @return string
	 */
	private static function format_text( $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( $text === wp_strip_all_tags( $text ) ) {
			return wpautop( esc_html( $text ) );
		}
		return wp_kses_post( $text );
	}

	/**
	 * Opening of a locked post: some text, the picture, then the paywall follows.
	 *
	 * The hidden remainder is never passed through content filters.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function locked_preview( $post ) {
		$raw      = (string) $post->post_content;
		$extended = get_extended( $raw );
		if ( '' !== trim( (string) $extended['extended'] ) ) {
			$slice = (string) $extended['main'];
		} else {
			$slice = self::slice_preview( $raw, self::preview_word_budget( $raw ) );
		}

		$slice = trim( $slice );
		$html  = '';
		if ( '' !== $slice ) {
			$html = apply_filters( 'the_content', $slice );
			$html = is_string( $html ) ? trim( $html ) : '';
		}

		if ( ! self::html_has_image( $html ) ) {
			$image = get_the_post_thumbnail(
				$post,
				'large',
				array(
					'class' => 'pca-preview__image',
				)
			);
			if ( is_string( $image ) && '' !== $image ) {
				$html = $image . $html;
			}
		}

		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		return '<div class="pca-preview">' . $html . '</div>';
	}

	/**
	 * How many words of a locked post stay visible.
	 *
	 * A short post still keeps part of itself behind the paywall.
	 *
	 * @param string $raw Post content.
	 * @return int
	 */
	private static function preview_word_budget( $raw ) {
		$limit = max( 20, (int) apply_filters( 'pca_preview_word_limit', 80 ) );
		$total = self::count_words( wp_strip_all_tags( $raw ) );
		if ( $total > $limit ) {
			return $limit;
		}

		return max( 1, (int) floor( $total * 0.45 ) );
	}

	/**
	 * Keep the opening blocks and the first image. Drop the rest.
	 *
	 * @param string $content Raw post content.
	 * @param int    $budget  Visible word budget.
	 * @return string
	 */
	private static function slice_preview( $content, $budget ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return '';
		}

		$dom  = new DOMDocument();
		$root = self::load_fragment( $dom, $content );
		if ( ! $root instanceof DOMElement ) {
			return self::slice_preview_fallback( $content, $budget );
		}

		$has_image = false;
		self::trim_container( $dom, $root, $budget, $has_image );

		return self::inner_html( $dom, $root );
	}

	/**
	 * Drop child nodes after the word budget, keeping the first picture.
	 *
	 * @param DOMDocument $dom       Document.
	 * @param DOMElement  $parent    Parent whose children are trimmed.
	 * @param int         $budget    Visible word budget.
	 * @param bool        $has_image Whether a picture is already kept.
	 * @return int Words kept under this parent.
	 */
	private static function trim_container( DOMDocument $dom, DOMElement $parent, $budget, &$has_image ) {
		unset( $dom );
		$words   = 0;
		$held    = null;
		$remove  = array();
		$cutting = false;

		foreach ( iterator_to_array( $parent->childNodes ) as $child ) {
			$is_image = self::node_has_image( $child );
			$count    = self::count_words( $child->textContent );

			if ( $cutting ) {
				if ( ! $has_image && $is_image && ! $held instanceof DOMNode ) {
					$held = $child;
				} else {
					$remove[] = $child;
				}
				continue;
			}

			if ( 0 === $words && $count > $budget && $child instanceof DOMElement && self::has_element_children( $child ) ) {
				$words   = self::trim_container( $child->ownerDocument, $child, $budget, $has_image );
				$cutting = true;
				continue;
			}

			if ( 0 === $words && ! $is_image && $count > $budget ) {
				self::trim_element_words( $child, $budget );
				$words   = $budget;
				$cutting = true;
				continue;
			}

			if ( $words > 0 && $words >= $budget && ! ( $is_image && ! $has_image ) ) {
				$cutting = true;
				if ( ! $has_image && $is_image && ! $held instanceof DOMNode ) {
					$held = $child;
				} else {
					$remove[] = $child;
				}
				continue;
			}

			$words += $count;
			if ( $is_image ) {
				$has_image = true;
			}
			if ( $words >= $budget ) {
				$cutting = true;
			}
		}

		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		if ( ! $has_image && $held instanceof DOMNode && $held->parentNode ) {
			$has_image = true;
		} elseif ( $held instanceof DOMNode && $held->parentNode && $has_image ) {
			$held->parentNode->removeChild( $held );
		}

		return $words;
	}

	/**
	 * Whether an element contains nested elements other than a bare image.
	 *
	 * @param DOMElement $element Element to inspect.
	 * @return bool
	 */
	private static function has_element_children( DOMElement $element ) {
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'img' !== strtolower( $child->tagName ) && 'br' !== strtolower( $child->tagName ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plain-text fallback when the HTML fragment cannot be parsed.
	 *
	 * @param string $content Raw post content.
	 * @param int    $budget  Visible word budget.
	 * @return string
	 */
	private static function slice_preview_fallback( $content, $budget ) {
		$text  = wpautop( esc_html( wp_trim_words( wp_strip_all_tags( $content ), $budget, '…' ) ) );
		$image = '';
		if ( preg_match( '/<figure\b[^>]*>.*?<img\b[^>]*>.*?<\/figure>/is', $content, $match ) ) {
			$image = $match[0];
		} elseif ( preg_match( '/<img\b[^>]*>/i', $content, $match ) ) {
			$image = $match[0];
		}

		return $image . $text;
	}

	/**
	 * Load an HTML fragment and return its root element.
	 *
	 * @param DOMDocument $dom     Document to fill.
	 * @param string      $content Fragment HTML.
	 * @return DOMElement|null
	 */
	private static function load_fragment( DOMDocument $dom, $content ) {
		$previous = libxml_use_internal_errors( true );
		$markup   = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="pca-root">' . $content . '</div></body></html>';
		$loaded   = $dom->loadHTML( $markup );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$root = $dom->getElementById( 'pca-root' );
		return $root instanceof DOMElement ? $root : null;
	}

	/**
	 * Whether a node contains a picture.
	 *
	 * @param DOMNode $node Node to inspect.
	 * @return bool
	 */
	private static function node_has_image( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		if ( 'img' === strtolower( $node->tagName ) ) {
			return true;
		}

		return $node->getElementsByTagName( 'img' )->length > 0;
	}

	/**
	 * Replace an element's contents with the first words of its text.
	 *
	 * @param DOMNode $node  Element or other node.
	 * @param int     $limit Word limit.
	 */
	private static function trim_element_words( DOMNode $node, $limit ) {
		if ( ! $node instanceof DOMElement ) {
			return;
		}

		$words = preg_split( '/\s+/u', trim( (string) $node->textContent ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return;
		}
		$words = array_slice( $words, 0, max( 1, (int) $limit ) );
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		$node->appendChild( $node->ownerDocument->createTextNode( implode( ' ', $words ) . '…' ) );
	}

	/**
	 * Serialize one node.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	private static function node_html( DOMDocument $dom, DOMNode $node ) {
		$html = $dom->saveHTML( $node );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Serialize the children of the fragment root.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMElement  $root Fragment root.
	 * @return string
	 */
	private static function inner_html( DOMDocument $dom, DOMElement $root ) {
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= self::node_html( $dom, $child );
		}
		return $html;
	}

	/**
	 * Count words separated by whitespace, including Cyrillic.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	private static function count_words( $text ) {
		$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( (string) $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Whether rendered HTML already contains a picture.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	private static function html_has_image( $html ) {
		return (bool) preg_match( '/<img\b/i', (string) $html );
	}

	/**
	 * Print the paywall stylesheet once, even when it is rendered after the head.
	 *
	 * @return string
	 */
	private static function inline_css() {
		if ( self::$css_printed ) {
			return '';
		}

		$path = PCA_PATH . 'assets/css/frontend.css';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$css = file_get_contents( $path );
		if ( ! is_string( $css ) || '' === $css ) {
			return '';
		}

		self::$css_printed = true;
		return '<style id="pca-paywall-css">' . str_replace( '</', '<\/', $css ) . '</style>';
	}
}
