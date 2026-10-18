<?php
/**
 * Administration screens.
 *
 * @package PaidContentAccess
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, paid posts, and purchase management.
 */
class PCA_Admin {

	/**
	 * Pages that belong to this plugin.
	 *
	 * @return string[]
	 */
	private static function pages() {
		return array( 'pca-access', 'pca-posts', 'pca-purchases', 'pca-settings' );
	}

	/**
	 * Register admin hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_columns' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post', array( __CLASS__, 'save_metabox' ), 10, 2 );
		add_action( 'admin_post_pca_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_pca_grant_access', array( __CLASS__, 'handle_grant' ) );
		add_action( 'admin_post_pca_revoke_access', array( __CLASS__, 'handle_revoke' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PCA_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/**
	 * Store one or more admin notices for the next page load.
	 *
	 * @param array<int,array{type:string,text:string}>|array{type:string,text:string} $notices Notices.
	 */
	public static function flashes( $notices ) {
		if ( isset( $notices['type'] ) ) {
			$notices = array( $notices );
		}
		set_transient( 'pca_flash_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Add the top-level menu.
	 */
	public static function menu() {
		$capability = 'manage_options';
		add_menu_page(
			__( 'Платено съдържание', 'paid-content-access' ),
			__( 'Платено съдържание', 'paid-content-access' ),
			$capability,
			'pca-access',
			array( __CLASS__, 'render_overview' ),
			'dashicons-lock',
			58
		);
		add_submenu_page( 'pca-access', __( 'Преглед', 'paid-content-access' ), __( 'Преглед', 'paid-content-access' ), $capability, 'pca-access', array( __CLASS__, 'render_overview' ) );
		add_submenu_page( 'pca-access', __( 'Платени публикации', 'paid-content-access' ), __( 'Платени публикации', 'paid-content-access' ), $capability, 'pca-posts', array( __CLASS__, 'render_posts' ) );
		add_submenu_page( 'pca-access', __( 'Покупки и достъп', 'paid-content-access' ), __( 'Покупки и достъп', 'paid-content-access' ), $capability, 'pca-purchases', array( __CLASS__, 'render_purchases' ) );
		add_submenu_page( 'pca-access', __( 'Настройки', 'paid-content-access' ), __( 'Настройки', 'paid-content-access' ), $capability, 'pca-settings', array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function plugin_links( $links ) {
		$url      = admin_url( 'admin.php?page=pca-settings' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Настройки', 'paid-content-access' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Load CSS and JS on this plugin's screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		unset( $hook );
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		if ( ! in_array( $page, self::pages(), true ) ) {
			return;
		}

		wp_enqueue_style( 'pca-admin', PCA_URL . 'assets/css/admin.css', array(), PCA_VERSION );
		wp_enqueue_script( 'pca-admin', PCA_URL . 'assets/js/admin.js', array(), PCA_VERSION, true );
	}

	/**
	 * Flash messages and the Simple Membership requirement.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$flash = get_transient( 'pca_flash_' . get_current_user_id() );
		if ( is_array( $flash ) && $flash ) {
			delete_transient( 'pca_flash_' . get_current_user_id() );
			if ( isset( $flash['type'] ) ) {
				$flash = array( $flash );
			}
			foreach ( $flash as $notice ) {
				if ( empty( $notice['text'] ) ) {
					continue;
				}
				$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
			}
		}

		if ( PCA_Member::is_available() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$show   = $screen && in_array( $screen->id, array( 'dashboard', 'plugins' ), true );
		if ( ! $show && ! in_array( $page, self::pages(), true ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Плъгинът „Платено съдържание“ работи заедно със Simple Membership. Активирайте Simple Membership, за да могат регистрираните членове да купуват достъп.', 'paid-content-access' ) . '</p></div>';
	}

	/**
	 * Paid column on supported post lists.
	 */
	public static function register_columns() {
		foreach ( PCA_Settings::post_types() as $post_type ) {
			add_filter( 'manage_' . $post_type . '_posts_columns', array( __CLASS__, 'add_column' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Column title.
	 *
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public static function add_column( $columns ) {
		$columns['pca_paid'] = __( 'Платено', 'paid-content-access' );
		return $columns;
	}

	/**
	 * Column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'pca_paid' !== $column ) {
			return;
		}
		if ( ! PCA_Access::is_enabled( $post_id ) ) {
			echo '—';
			return;
		}
		$config = PCA_Access::config( $post_id );
		echo esc_html( pca_format_money( (int) $config['amount'], (string) $config['currency'] ) );
	}

	/**
	 * Editor box used to mark a post as extra paid content.
	 */
	public static function metabox() {
		foreach ( PCA_Settings::post_types() as $post_type ) {
			add_meta_box(
				'pca-paid-content',
				__( 'Допълнително съдържание', 'paid-content-access' ),
				array( __CLASS__, 'render_metabox' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Metabox form.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_metabox( $post ) {
		wp_nonce_field( 'pca_save_metabox', 'pca_metabox_nonce' );
		$config  = PCA_Access::config( $post->ID );
		$enabled = PCA_Access::is_enabled( $post->ID );
		$price   = (string) get_post_meta( $post->ID, PCA_Access::META_PRICE, true );
		$days    = get_post_meta( $post->ID, PCA_Access::META_DAYS, true );
		$teaser  = (string) get_post_meta( $post->ID, PCA_Access::META_TEASER, true );
		$default = PCA_Settings::get( 'default_price' );
		$default = is_string( $default ) && '' !== $default ? $default : __( 'няма', 'paid-content-access' );
		?>
		<p>
			<label>
				<input type="checkbox" name="pca_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Тази публикация е допълнително платено съдържание', 'paid-content-access' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Пълният текст се вижда само от регистриран член на Simple Membership, който е заплатил за тази публикация. Преди плащане посетителят вижда началото на текста и снимката. Откъсът на публикацията остава публичен — не поставяйте в него платения текст.', 'paid-content-access' ); ?>
		</p>
		<p>
			<label for="pca_price"><strong><?php esc_html_e( 'Цена', 'paid-content-access' ); ?></strong></label><br />
			<input type="text" class="regular-text" id="pca_price" name="pca_price" value="<?php echo esc_attr( $price ); ?>" placeholder="<?php echo esc_attr( (string) PCA_Settings::get( 'default_price' ) ); ?>" inputmode="decimal" />
			<span class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: currency code, 2: default price or "none". */
						__( 'Валута: %1$s. Празно поле използва цената по подразбиране (%2$s). Минимум 0,50.', 'paid-content-access' ),
						strtoupper( (string) PCA_Settings::get( 'currency' ) ),
						$default
					)
				);
				?>
			</span>
		</p>
		<p>
			<label for="pca_access_days"><strong><?php esc_html_e( 'Срок на достъпа в дни', 'paid-content-access' ); ?></strong></label><br />
			<input type="number" min="0" max="3650" step="1" id="pca_access_days" name="pca_access_days" value="<?php echo esc_attr( is_string( $days ) || is_numeric( $days ) ? (string) $days : '' ); ?>" placeholder="<?php echo esc_attr( (string) (int) PCA_Settings::get( 'default_access_days' ) ); ?>" />
			<span class="description"><?php esc_html_e( '0 е постоянен достъп. Празно поле използва срока от настройките.', 'paid-content-access' ); ?></span>
		</p>
		<p>
			<label for="pca_teaser"><strong><?php esc_html_e( 'Текст преди плащане', 'paid-content-access' ); ?></strong></label><br />
			<textarea class="large-text" rows="4" id="pca_teaser" name="pca_teaser"><?php echo esc_textarea( $teaser ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Показва се публично над кутията за плащане. Ако е празен, използва се ръчно написаният откъс.', 'paid-content-access' ); ?></span>
		</p>
		<?php if ( $enabled ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: formatted price, 2: purchase count. */
						__( 'Текуща цена: %1$s. Заплатени достъпи: %2$d.', 'paid-content-access' ),
						pca_format_money( (int) $config['amount'], (string) $config['currency'] ),
						PCA_Purchases::count_for_post( $post->ID )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( current_user_can( 'manage_options' ) && 'publish' === $post->post_status ) : ?>
			<p>
				<a href="<?php echo esc_url( add_query_arg( 'pca_view', 'paywall', get_permalink( $post ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Преглед на стената за плащане', 'paid-content-access' ); ?></a>
				|
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pca-purchases&post_id=' . $post->ID ) ); ?>"><?php esc_html_e( 'Покупки за тази публикация', 'paid-content-access' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Save the metabox. A missing nonce means quick edit or bulk edit, so existing meta stays.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['pca_metabox_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pca_metabox_nonce'] ) ), 'pca_save_metabox' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, PCA_Settings::post_types(), true ) ) {
			return;
		}

		$price = isset( $_POST['pca_price'] ) ? PCA_Settings::sanitize_price( wp_unslash( $_POST['pca_price'] ) ) : '';
		if ( '' === $price ) {
			delete_post_meta( $post_id, PCA_Access::META_PRICE );
		} else {
			update_post_meta( $post_id, PCA_Access::META_PRICE, $price );
		}

		$days_raw = isset( $_POST['pca_access_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pca_access_days'] ) ) ) : '';
		if ( '' === $days_raw ) {
			delete_post_meta( $post_id, PCA_Access::META_DAYS );
		} else {
			update_post_meta( $post_id, PCA_Access::META_DAYS, (string) min( 3650, absint( $days_raw ) ) );
		}

		$teaser = isset( $_POST['pca_teaser'] ) ? wp_kses_post( wp_unslash( $_POST['pca_teaser'] ) ) : '';
		if ( '' === trim( $teaser ) ) {
			delete_post_meta( $post_id, PCA_Access::META_TEASER );
		} else {
			update_post_meta( $post_id, PCA_Access::META_TEASER, $teaser );
		}

		$enabled = ! empty( $_POST['pca_enabled'] );
		if ( $enabled ) {
			$config = PCA_Access::config( $post_id );
			if ( (int) $config['amount'] < pca_minimum_amount_minor() ) {
				$enabled = false;
				self::flashes(
					array(
						'type' => 'error',
						'text' => __( 'Публикацията не беше маркирана като платена, защото цената липсва или е под 0,50.', 'paid-content-access' ),
					)
				);
			}
		}

		update_post_meta( $post_id, PCA_Access::META_ENABLED, $enabled ? '1' : '0' );
	}

	/**
	 * Overview with setup status.
	 */
	public static function render_overview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}

		$stats    = PCA_Purchases::stats();
		$recent   = PCA_Purchases::recent( 8 );
		$paid     = self::count_paid_posts();
		$mode     = PCA_Settings::stripe_mode();
		$mode_lbl = 'live' === $mode ? __( 'реално плащане', 'paid-content-access' ) : ( 'test' === $mode ? __( 'тест', 'paid-content-access' ) : __( 'няма ключ', 'paid-content-access' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Платено съдържание', 'paid-content-access' ); ?></h1>
			<p><?php esc_html_e( 'Допълнение към Simple Membership. Регистрираните членове заплащат през Stripe за отделни публикации. WooCommerce не е нужен.', 'paid-content-access' ); ?></p>
			<div class="pca-cards">
				<div class="pca-card"><strong><?php echo esc_html( (string) $paid ); ?></strong><span><?php esc_html_e( 'Платени публикации', 'paid-content-access' ); ?></span></div>
				<div class="pca-card"><strong><?php echo esc_html( (string) (int) $stats['active'] ); ?></strong><span><?php esc_html_e( 'Активни достъпи', 'paid-content-access' ); ?></span></div>
				<div class="pca-card"><strong><?php echo esc_html( (string) (int) $stats['total'] ); ?></strong><span><?php esc_html_e( 'Записи за покупки', 'paid-content-access' ); ?></span></div>
				<div class="pca-card">
					<strong><?php echo esc_html( self::revenue_text( $stats['sales'] ) ); ?></strong>
					<span><?php esc_html_e( 'Приход от Stripe', 'paid-content-access' ); ?></span>
				</div>
			</div>
			<h2><?php esc_html_e( 'Състояние', 'paid-content-access' ); ?></h2>
			<ul class="pca-checklist">
				<li><?php echo PCA_Member::is_available() ? '✓' : '•'; ?> <?php echo esc_html( PCA_Member::is_available() ? __( 'Simple Membership е активно.', 'paid-content-access' ) : __( 'Simple Membership не е активно.', 'paid-content-access' ) ); ?></li>
				<li><?php echo PCA_Settings::stripe_ready() ? '✓' : '•'; ?> <?php echo esc_html( sprintf( __( 'Stripe: %s.', 'paid-content-access' ), $mode_lbl ) ); ?></li>
				<li><?php echo PCA_Settings::get( 'stripe_webhook_secret' ) ? '✓' : '•'; ?> <?php echo esc_html( PCA_Settings::get( 'stripe_webhook_secret' ) ? __( 'Webhook тайната е записана.', 'paid-content-access' ) : __( 'Webhook тайната липсва. Връщането от Stripe пак отключва достъпа, но възстановяванията няма да се хващат автоматично.', 'paid-content-access' ) ); ?></li>
			</ul>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=pca-settings' ) ); ?>"><?php esc_html_e( 'Настройки', 'paid-content-access' ); ?></a></p>
			<h2><?php esc_html_e( 'Последни покупки', 'paid-content-access' ); ?></h2>
			<?php self::purchases_table( $recent, false ); ?>
		</div>
		<?php
	}

	/**
	 * List of posts marked as paid.
	 */
	public static function render_posts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query  = new WP_Query(
			array(
				'post_type'      => PCA_Settings::post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 20,
				'paged'          => $paged,
				's'              => $search,
				'meta_key'       => PCA_Access::META_ENABLED,
				'meta_value'     => '1',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Платени публикации', 'paid-content-access' ); ?></h1>
			<p><?php esc_html_e( 'Отворете публикация и в кутията „Допълнително съдържание“ я маркирайте като платена.', 'paid-content-access' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="pca-posts" />
				<p class="search-box">
					<label class="screen-reader-text" for="pca-post-search"><?php esc_html_e( 'Търсене', 'paid-content-access' ); ?></label>
					<input type="search" id="pca-post-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<input type="submit" class="button" value="<?php esc_attr_e( 'Търсене', 'paid-content-access' ); ?>" />
				</p>
			</form>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Публикация', 'paid-content-access' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'paid-content-access' ); ?></th>
						<th><?php esc_html_e( 'Цена', 'paid-content-access' ); ?></th>
						<th><?php esc_html_e( 'Срок', 'paid-content-access' ); ?></th>
						<th><?php esc_html_e( 'Покупки', 'paid-content-access' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $query->have_posts() ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Няма маркирани публикации.', 'paid-content-access' ); ?></td></tr>
					<?php endif; ?>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						$post_id = get_the_ID();
						$config  = PCA_Access::config( $post_id );
						$days    = (int) $config['days'];
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title() ); ?></a></strong>
								<div class="row-actions">
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Редакция', 'paid-content-access' ); ?></a>
									<?php if ( 'publish' === get_post_status( $post_id ) ) : ?>
										| <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php esc_html_e( 'Преглед', 'paid-content-access' ); ?></a>
									<?php endif; ?>
									| <a href="<?php echo esc_url( admin_url( 'admin.php?page=pca-purchases&post_id=' . $post_id ) ); ?>"><?php esc_html_e( 'Покупки', 'paid-content-access' ); ?></a>
								</div>
							</td>
							<td>
							<?php
							$status_object = get_post_status_object( get_post_status( $post_id ) );
							$status_label  = ( $status_object && ! empty( $status_object->label ) ) ? $status_object->label : get_post_status( $post_id );
							echo esc_html( $status_label );
							?>
						</td>
							<td><?php echo esc_html( pca_format_money( (int) $config['amount'], (string) $config['currency'] ) ); ?></td>
							<td><?php echo esc_html( $days > 0 ? sprintf( _n( '%d ден', '%d дни', $days, 'paid-content-access' ), $days ) : __( 'Постоянен', 'paid-content-access' ) ); ?></td>
							<td><?php echo esc_html( (string) PCA_Purchases::count_for_post( $post_id ) ); ?></td>
						</tr>
					<?php endwhile; ?>
					<?php wp_reset_postdata(); ?>
				</tbody>
			</table>
			<?php self::pagination( $query->max_num_pages, $paged, array( 'page' => 'pca-posts', 's' => $search ) ); ?>
		</div>
		<?php
	}

	/**
	 * Purchases, manual grants, and revocation.
	 */
	public static function render_purchases() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}

		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result  = PCA_Purchases::query(
			array(
				'paged'   => $paged,
				'status'  => $status,
				'post_id' => $post_id,
				'search'  => $search,
			)
		);
		$pages   = (int) ceil( $result['total'] / 20 );
		$paid    = get_posts(
			array(
				'post_type'      => PCA_Settings::post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200,
				'meta_key'       => PCA_Access::META_ENABLED,
				'meta_value'     => '1',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Покупки и достъп', 'paid-content-access' ); ?></h1>
			<ul class="subsubsub">
				<?php self::status_views( $status, $post_id ); ?>
			</ul>
			<form method="get" class="pca-filters">
				<input type="hidden" name="page" value="pca-purchases" />
				<?php if ( $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<p class="search-box">
					<label class="screen-reader-text" for="pca-purchase-search"><?php esc_html_e( 'Търсене', 'paid-content-access' ); ?></label>
					<input type="search" id="pca-purchase-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Член, публикация или Stripe ID', 'paid-content-access' ); ?>" />
					<?php if ( $post_id ) : ?>
						<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
					<?php endif; ?>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Търсене', 'paid-content-access' ); ?>" />
				</p>
			</form>
			<?php if ( $post_id ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: post title. */
							__( 'Филтър по публикация: %s', 'paid-content-access' ),
							get_the_title( $post_id )
						)
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pca-purchases' ) ); ?>"><?php esc_html_e( 'Изчисти', 'paid-content-access' ); ?></a>
				</p>
			<?php endif; ?>
			<?php self::purchases_table( $result['rows'], true ); ?>
			<?php
			self::pagination(
				$pages,
				$paged,
				array(
					'page'    => 'pca-purchases',
					'status'  => $status,
					'post_id' => $post_id ? (string) $post_id : '',
					's'       => $search,
				)
			);
			?>
			<h2><?php esc_html_e( 'Ръчно даване на достъп', 'paid-content-access' ); ?></h2>
			<p><?php esc_html_e( 'За член, който вече е регистриран в Simple Membership. Не минава през Stripe.', 'paid-content-access' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pca-grant">
				<input type="hidden" name="action" value="pca_grant_access" />
				<?php wp_nonce_field( 'pca_grant_access' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pca_member"><?php esc_html_e( 'Член', 'paid-content-access' ); ?></label></th>
						<td><input type="text" class="regular-text" id="pca_member" name="pca_member" required />
							<p class="description"><?php esc_html_e( 'ID, потребителско име или имейл от Simple Membership.', 'paid-content-access' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_post_id"><?php esc_html_e( 'Публикация', 'paid-content-access' ); ?></label></th>
						<td>
							<select id="pca_post_id" name="pca_post_id">
								<option value="0"><?php esc_html_e( '— Изберете —', 'paid-content-access' ); ?></option>
								<?php foreach ( $paid as $paid_post ) : ?>
									<option value="<?php echo esc_attr( (string) $paid_post->ID ); ?>"><?php echo esc_html( $paid_post->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<p><label for="pca_post_id_manual"><?php esc_html_e( 'или ID', 'paid-content-access' ); ?></label>
								<input type="number" min="1" id="pca_post_id_manual" name="pca_post_id_manual" class="small-text" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_grant_days"><?php esc_html_e( 'Срок в дни', 'paid-content-access' ); ?></label></th>
						<td><input type="number" min="0" max="3650" id="pca_grant_days" name="pca_access_days" class="small-text" />
							<p class="description"><?php esc_html_e( 'Празно поле използва срока на публикацията. 0 е постоянен достъп.', 'paid-content-access' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_note"><?php esc_html_e( 'Бележка', 'paid-content-access' ); ?></label></th>
						<td><input type="text" class="regular-text" id="pca_note" name="pca_note" maxlength="300" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Дай достъп', 'paid-content-access' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings form.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}

		$settings = PCA_Settings::all();
		$webhook  = rest_url( 'paid-content-access/v1/stripe' );
		$secret   = (string) $settings['stripe_secret_key'];
		$whsec    = (string) $settings['stripe_webhook_secret'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Настройки', 'paid-content-access' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pca_save_settings" />
				<?php wp_nonce_field( 'pca_save_settings' ); ?>
				<h2><?php esc_html_e( 'Stripe', 'paid-content-access' ); ?></h2>
				<p><?php esc_html_e( 'Плащането става през хостваната страница на Stripe. В сайта не се въвеждат данни за карта и не е нужен WooCommerce.', 'paid-content-access' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pca_stripe_secret_key"><?php esc_html_e( 'Секретен ключ', 'paid-content-access' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="pca_stripe_secret_key" name="pca_stripe_secret_key" value="" autocomplete="off" placeholder="<?php echo esc_attr( self::secret_placeholder( $secret ) ); ?>" />
							<?php if ( $secret ) : ?>
								<label><input type="checkbox" name="pca_clear_secret" value="1" /> <?php esc_html_e( 'Премахни запазения ключ', 'paid-content-access' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Започва със sk_test_ или sk_live_. Празно поле запазва текущия ключ. При запис ключът се проверява през Stripe.', 'paid-content-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_webhook_url"><?php esc_html_e( 'Webhook адрес', 'paid-content-access' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="pca_webhook_url" readonly value="<?php echo esc_attr( $webhook ); ?>" />
							<button type="button" class="button" data-pca-copy="#pca_webhook_url"><?php esc_html_e( 'Копирай', 'paid-content-access' ); ?></button>
							<p class="description"><?php esc_html_e( 'В Stripe Dashboard → Developers → Webhooks добавете този адрес и събитията checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired и charge.refunded.', 'paid-content-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_stripe_webhook_secret"><?php esc_html_e( 'Webhook тайна', 'paid-content-access' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="pca_stripe_webhook_secret" name="pca_stripe_webhook_secret" value="" autocomplete="off" placeholder="<?php echo esc_attr( self::secret_placeholder( $whsec ) ); ?>" />
							<?php if ( $whsec ) : ?>
								<label><input type="checkbox" name="pca_clear_webhook" value="1" /> <?php esc_html_e( 'Премахни запазената тайна', 'paid-content-access' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Започва с whsec_. Stripe я показва при създаване на webhook-а.', 'paid-content-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_currency"><?php esc_html_e( 'Валута', 'paid-content-access' ); ?></label></th>
						<td>
							<select id="pca_currency" name="pca_currency">
								<?php foreach ( PCA_Settings::currencies() as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['currency'], $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Новите продажби използват тази валута. За България обикновено е EUR.', 'paid-content-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_default_price"><?php esc_html_e( 'Цена по подразбиране', 'paid-content-access' ); ?></label></th>
						<td><input type="text" class="regular-text" id="pca_default_price" name="pca_default_price" value="<?php echo esc_attr( (string) $settings['default_price'] ); ?>" inputmode="decimal" />
							<p class="description"><?php esc_html_e( 'Използва се, когато публикацията няма собствена цена. Пример: 9.90', 'paid-content-access' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_default_access_days"><?php esc_html_e( 'Срок по подразбиране', 'paid-content-access' ); ?></label></th>
						<td><input type="number" min="0" max="3650" id="pca_default_access_days" name="pca_default_access_days" value="<?php echo esc_attr( (string) (int) $settings['default_access_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Дни. 0 означава постоянен достъп.', 'paid-content-access' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Членство и текстове', 'paid-content-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Типове публикации', 'paid-content-access' ); ?></th>
						<td>
							<?php
							$public = get_post_types( array( 'public' => true ), 'objects' );
							foreach ( $public as $type ) :
								if ( 'attachment' === $type->name ) {
									continue;
								}
								?>
								<label class="pca-check">
									<input type="checkbox" name="pca_post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $type->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_login_page_id"><?php esc_html_e( 'Страница за вход', 'paid-content-access' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'pca_login_page_id',
									'id'                => 'pca_login_page_id',
									'selected'          => (int) $settings['login_page_id'],
									'show_option_none'  => __( '— От Simple Membership —', 'paid-content-access' ),
									'option_none_value' => '0',
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_register_page_id"><?php esc_html_e( 'Страница за регистрация', 'paid-content-access' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'pca_register_page_id',
									'id'                => 'pca_register_page_id',
									'selected'          => (int) $settings['register_page_id'],
									'show_option_none'  => __( '— От Simple Membership —', 'paid-content-access' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Ако не изберете страница, се ползват адресите от настройките на Simple Membership.', 'paid-content-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_paywall_heading"><?php esc_html_e( 'Заглавие на стената', 'paid-content-access' ); ?></label></th>
						<td><input type="text" class="regular-text" id="pca_paywall_heading" name="pca_paywall_heading" value="<?php echo esc_attr( (string) $settings['paywall_heading'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pca_button_label"><?php esc_html_e( 'Текст на бутона', 'paid-content-access' ); ?></label></th>
						<td><input type="text" class="regular-text" id="pca_button_label" name="pca_button_label" value="<?php echo esc_attr( (string) $settings['button_label'] ); ?>" /></td>
					</tr>
					<?php
					$messages = array(
						'pca_guest_message'    => array( __( 'Гост', 'paid-content-access' ), 'guest_message' ),
						'pca_member_message'   => array( __( 'Регистриран член', 'paid-content-access' ), 'member_message' ),
						'pca_inactive_message' => array( __( 'Неактивно членство', 'paid-content-access' ), 'inactive_message' ),
						'pca_expired_message'  => array( __( 'Изтекъл достъп', 'paid-content-access' ), 'expired_message' ),
					);
					foreach ( $messages as $name => $meta ) :
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td><textarea class="large-text" rows="3" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( (string) $settings[ $meta[1] ] ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Запази настройките', 'paid-content-access' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}
		check_admin_referer( 'pca_save_settings' );

		$warnings = PCA_Settings::save( $_POST );
		$notices  = array(
			array(
				'type' => 'success',
				'text' => __( 'Настройките са записани.', 'paid-content-access' ),
			),
		);
		foreach ( $warnings as $warning ) {
			$notices[] = array(
				'type' => 'warning',
				'text' => $warning,
			);
		}
		self::flashes( $notices );
		wp_safe_redirect( admin_url( 'admin.php?page=pca-settings' ) );
		exit;
	}

	/**
	 * Grant access without Stripe.
	 */
	public static function handle_grant() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}
		check_admin_referer( 'pca_grant_access' );

		$member_query = isset( $_POST['pca_member'] ) ? sanitize_text_field( wp_unslash( $_POST['pca_member'] ) ) : '';
		$post_id      = isset( $_POST['pca_post_id_manual'] ) && '' !== (string) $_POST['pca_post_id_manual']
			? absint( $_POST['pca_post_id_manual'] )
			: ( isset( $_POST['pca_post_id'] ) ? absint( $_POST['pca_post_id'] ) : 0 );
		$days_raw     = isset( $_POST['pca_access_days'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pca_access_days'] ) ) ) : '';
		$note         = isset( $_POST['pca_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pca_note'] ) ) : '';
		$note         = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 300 ) : substr( $note, 0, 300 );
		$member       = PCA_Member::find( $member_query );

		if ( ! $member ) {
			self::flashes(
				array(
					'type' => 'error',
					'text' => __( 'Членът не е намерен. Въведете ID, потребителско име или имейл от Simple Membership.', 'paid-content-access' ),
				)
			);
		} elseif ( ! PCA_Access::is_enabled( $post_id ) ) {
			self::flashes(
				array(
					'type' => 'error',
					'text' => __( 'Изберете публикация, която е маркирана като допълнително съдържание.', 'paid-content-access' ),
				)
			);
		} else {
			$days   = '' === $days_raw ? (int) PCA_Access::config( $post_id )['days'] : min( 3650, absint( $days_raw ) );
			$result = PCA_Purchases::grant_manual( (int) $member->member_id, $post_id, $days, $note );
			if ( is_wp_error( $result ) ) {
				self::flashes( array( 'type' => 'error', 'text' => $result->get_error_message() ) );
			} else {
				self::flashes( array( 'type' => 'success', 'text' => __( 'Достъпът е даден.', 'paid-content-access' ) ) );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=pca-purchases' ) );
		exit;
	}

	/**
	 * Revoke a grant or cancel a pending checkout.
	 */
	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'paid-content-access' ) );
		}

		$purchase_id = isset( $_GET['purchase_id'] ) ? absint( $_GET['purchase_id'] ) : 0;
		check_admin_referer( 'pca_revoke_' . $purchase_id );
		$result = PCA_Purchases::revoke( $purchase_id );
		if ( is_wp_error( $result ) ) {
			self::flashes( array( 'type' => 'error', 'text' => $result->get_error_message() ) );
		} else {
			self::flashes( array( 'type' => 'success', 'text' => __( 'Записът е променен.', 'paid-content-access' ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=pca-purchases' ) );
		exit;
	}

	/**
	 * Purchase rows.
	 *
	 * @param object[] $rows    Purchase rows.
	 * @param bool     $actions Whether to show revoke links.
	 */
	private static function purchases_table( $rows, $actions ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Дата', 'paid-content-access' ); ?></th>
					<th><?php esc_html_e( 'Член', 'paid-content-access' ); ?></th>
					<th><?php esc_html_e( 'Публикация', 'paid-content-access' ); ?></th>
					<th><?php esc_html_e( 'Сума', 'paid-content-access' ); ?></th>
					<th><?php esc_html_e( 'Достъп до', 'paid-content-access' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'paid-content-access' ); ?></th>
					<?php if ( $actions ) : ?>
						<th><?php esc_html_e( 'Действие', 'paid-content-access' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="<?php echo esc_attr( $actions ? '7' : '6' ); ?>"><?php esc_html_e( 'Няма покупки.', 'paid-content-access' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( self::format_gmt( $row->created_at ) ); ?></td>
						<td><?php echo esc_html( PCA_Member::label( (int) $row->member_id ) ); ?></td>
						<td><?php echo wp_kses_post( self::post_label( (int) $row->post_id ) ); ?></td>
						<td>
							<?php
							if ( ! empty( $row->granted_by ) ) {
								esc_html_e( 'Ръчно', 'paid-content-access' );
							} else {
								echo esc_html( pca_format_money( (int) $row->amount, (string) $row->currency ) );
							}
							?>
						</td>
						<td><?php echo esc_html( ( 'completed' === $row->status || 'revoked' === $row->status || 'refunded' === $row->status ) ? self::expiry_label( $row->expires_at ) : '—' ); ?></td>
						<td><span class="pca-badge pca-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( pca_status_label( $row->status ) ); ?></span></td>
						<?php if ( $actions ) : ?>
							<td>
								<?php if ( in_array( $row->status, array( 'completed', 'pending' ), true ) ) : ?>
									<?php
									$url = wp_nonce_url(
										admin_url( 'admin-post.php?action=pca_revoke_access&purchase_id=' . (int) $row->id ),
										'pca_revoke_' . (int) $row->id
									);
									$confirm = 'completed' === $row->status
										? __( 'Да се отнеме ли достъпът?', 'paid-content-access' )
										: __( 'Да се отмени ли това плащане?', 'paid-content-access' );
									?>
									<a class="pca-revoke" data-confirm="<?php echo esc_attr( $confirm ); ?>" href="<?php echo esc_url( $url ); ?>">
										<?php echo esc_html( 'completed' === $row->status ? __( 'Отнеми достъпа', 'paid-content-access' ) : __( 'Отмени', 'paid-content-access' ) ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Status filter links.
	 *
	 * @param string $current Current status.
	 * @param int    $post_id Optional post filter.
	 */
	private static function status_views( $current, $post_id ) {
		global $wpdb;

		$table  = PCA_Purchases::table();
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", OBJECT_K );
		$views  = array(
			''          => __( 'Всички', 'paid-content-access' ),
			'completed' => __( 'Платени', 'paid-content-access' ),
			'pending'   => __( 'Изчакващи', 'paid-content-access' ),
			'refunded'  => __( 'Възстановени', 'paid-content-access' ),
			'revoked'   => __( 'Отнети', 'paid-content-access' ),
			'cancelled' => __( 'Отказани', 'paid-content-access' ),
		);
		$links  = array();
		$total  = 0;
		if ( is_array( $counts ) ) {
			foreach ( $counts as $row ) {
				$total += (int) $row->total;
			}
		}

		foreach ( $views as $status => $label ) {
			$count = '' === $status ? $total : ( isset( $counts[ $status ] ) ? (int) $counts[ $status ]->total : 0 );
			$args  = array( 'page' => 'pca-purchases' );
			if ( $status ) {
				$args['status'] = $status;
			}
			if ( $post_id ) {
				$args['post_id'] = $post_id;
			}
			$class   = $current === $status ? ' class="current"' : '';
			$links[] = '<li><a' . $class . ' href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . ' <span class="count">(' . esc_html( (string) $count ) . ')</span></a></li>';
		}

		echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
	}

	/**
	 * Pagination links.
	 *
	 * @param int                 $total_pages Page count.
	 * @param int                 $current     Current page.
	 * @param array<string,mixed> $args        Query args.
	 */
	private static function pagination( $total_pages, $current, $args ) {
		if ( $total_pages < 2 ) {
			return;
		}

		$args = array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
		$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $current,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * Count posts marked as paid.
	 *
	 * @return int
	 */
	private static function count_paid_posts() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status NOT IN ('trash','auto-draft')",
				PCA_Access::META_ENABLED,
				'1'
			)
		);
	}

	/**
	 * Revenue summary across currencies.
	 *
	 * @param object[] $sales Grouped totals.
	 * @return string
	 */
	private static function revenue_text( $sales ) {
		if ( ! $sales ) {
			return '0';
		}
		$parts = array();
		foreach ( $sales as $sale ) {
			$parts[] = pca_format_money( (int) $sale->total, (string) $sale->currency );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Placeholder that proves a secret is stored without revealing it.
	 *
	 * @param string $secret Stored secret.
	 * @return string
	 */
	private static function secret_placeholder( $secret ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		$tail = substr( $secret, -4 );
		return sprintf(
			/* translators: %s: last four characters of a stored secret. */
			__( 'Запазен е ключ, завършващ на %s', 'paid-content-access' ),
			$tail
		);
	}

	/**
	 * GMT timestamp in the site timezone.
	 *
	 * @param string $gmt MySQL datetime.
	 * @return string
	 */
	private static function format_gmt( $gmt ) {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return '—';
		}
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Expiry cell.
	 *
	 * @param string|null $expires GMT datetime.
	 * @return string
	 */
	private static function expiry_label( $expires ) {
		if ( empty( $expires ) || '0000-00-00 00:00:00' === $expires ) {
			return __( 'Постоянен', 'paid-content-access' );
		}
		return self::format_gmt( $expires );
	}

	/**
	 * Post title, linked when the post still exists.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function post_label( $post_id ) {
		$title = get_the_title( $post_id );
		if ( ! is_string( $title ) || '' === $title ) {
			return esc_html(
				sprintf(
					/* translators: %d: post ID. */
					__( 'Публикация #%d', 'paid-content-access' ),
					$post_id
				)
			);
		}
		$link = get_edit_post_link( $post_id );
		if ( ! $link ) {
			return esc_html( $title );
		}
		return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
	}
}
