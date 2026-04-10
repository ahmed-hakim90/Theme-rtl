<?php
/**
 * WooKapso SaaS-style admin dashboard (UI + AJAX).
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Dashboard
 */
class WooKapso_Dashboard {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 9 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wookapso_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
	}

	/**
	 * Register top-level admin page.
	 */
	public function register_menu(): void {
		$cap = WooKapso_Capabilities::menu_capability();

		add_menu_page(
			__( 'WooKapso Dashboard', 'woo-kapso' ),
			__( 'WooKapso', 'woo-kapso' ),
			$cap,
			'wookapso-dashboard',
			[ $this, 'render_page' ],
			'dashicons-chart-line',
			56
		);

		add_submenu_page(
			'wookapso-dashboard',
			__( 'Settings & tools', 'woo-kapso' ),
			__( 'Settings & tools', 'woo-kapso' ),
			$cap,
			'wookapso-settings-tools',
			static function () {
				wp_safe_redirect( admin_url( 'admin.php?page=wookapso' ) );
				exit;
			}
		);
	}

	/**
	 * AJAX: dashboard JSON payload.
	 */
	public function ajax_dashboard_data(): void {
		check_ajax_referer( 'wookapso_dashboard', 'nonce' );

		if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden', 'woo-kapso' ) ], 403 );
		}

		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 7;
		$days = max( 1, min( 90, $days ) );

		if ( ! empty( $_POST['refresh'] ) ) {
			WooKapso_Dashboard_Data::bust_cache( $days );
			if ( class_exists( 'WooKapso_Order_Metrics' ) ) {
				WooKapso_Order_Metrics::bust_cache();
			}
		}
		$data = WooKapso_Dashboard_Data::get_payload( $days );

		wp_send_json_success( $data );
	}

	/**
	 * Enqueue scripts/styles on dashboard screen only.
	 *
	 * @param string $hook_suffix Current screen id.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( strpos( $hook_suffix, 'wookapso-dashboard' ) === false && 'toplevel_page_wookapso-dashboard' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wookapso-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cairo:wght@400;600;700&display=swap',
			[],
			null
		);
		wp_enqueue_style(
			'wookapso-tokens',
			WOOKAPSO_URL . 'assets/css/wookapso-tokens.css',
			[],
			WOOKAPSO_VERSION
		);
		wp_enqueue_style(
			'wookapso-dashboard',
			WOOKAPSO_URL . 'assets/css/dashboard.css',
			[ 'wookapso-tokens', 'wookapso-fonts' ],
			WOOKAPSO_VERSION
		);

		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			[],
			'4.4.1',
			true
		);

		wp_enqueue_script(
			'wookapso-dashboard',
			WOOKAPSO_URL . 'assets/js/dashboard.js',
			[ 'jquery', 'chart-js' ],
			WOOKAPSO_VERSION,
			true
		);

		wp_localize_script(
			'wookapso-dashboard',
			'wookapsoDashboard',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wookapso_dashboard' ),
				'i18n'    => [
					'loading'     => __( 'Loading dashboard…', 'woo-kapso' ),
					'loadError'   => __( 'Could not load dashboard data.', 'woo-kapso' ),
					'lastNDays'   => __( 'Last %d days', 'woo-kapso' ),
					'last7Days'   => __( 'Last 7 days', 'woo-kapso' ),
					'ordersLine'  => __( 'Orders over time', 'woo-kapso' ),
					'barTitle'    => __( 'Confirmed vs cancelled', 'woo-kapso' ),
					'confirmed'   => __( 'Confirmed', 'woo-kapso' ),
					'cancelled'   => __( 'Cancelled', 'woo-kapso' ),
					'refresh'     => __( 'Refresh data', 'woo-kapso' ),
					'warnCancel'  => __( 'Cancellation rate is above 30%. Review unconfirmed orders and messaging.', 'woo-kapso' ),
				],
			]
		);
	}

	/**
	 * Render dashboard HTML shell (data via AJAX).
	 */
	public function render_page(): void {
		if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-kapso' ) );
		}

		$settings_url = admin_url( 'admin.php?page=wookapso' );
		$logs_url     = admin_url( 'admin.php?page=wookapso&tab=logs' );
		?>
		<div class="wrap wookapso-dashboard-wrap wkpd-app">
			<div class="wkpd-shell">
				<header class="wkpd-hero">
					<div class="wkpd-hero__text">
						<p class="wkpd-hero__eyebrow"><?php esc_html_e( 'Overview', 'woo-kapso' ); ?></p>
						<h1><?php esc_html_e( 'WooKapso Dashboard', 'woo-kapso' ); ?></h1>
						<p class="wkpd-hero__sub"><?php esc_html_e( 'Order performance, confirmations, and shipping insights.', 'woo-kapso' ); ?></p>
						<p class="wkpd-period" id="wkpd-period" hidden></p>
					</div>
					<div class="wkpd-hero__actions">
						<button type="button" class="button wkpd-btn wkpd-btn--ghost" id="wkpd-refresh">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<?php esc_html_e( 'Refresh', 'woo-kapso' ); ?>
						</button>
						<a class="button wkpd-btn wkpd-btn--secondary" href="<?php echo esc_url( $logs_url ); ?>"><?php esc_html_e( 'Message logs', 'woo-kapso' ); ?></a>
						<a class="button wkpd-btn wkpd-btn--primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'woo-kapso' ); ?></a>
					</div>
				</header>

				<div class="wkpd-banner-error" id="wkpd-load-error" hidden role="alert">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<div class="wkpd-banner-error__body">
						<strong class="wkpd-banner-error__title"><?php esc_html_e( 'Could not load dashboard data.', 'woo-kapso' ); ?></strong>
						<p class="wkpd-banner-error__text" id="wkpd-load-error-text"></p>
					</div>
				</div>

				<div class="wkpd-loading" id="wkpd-loading" role="status" aria-live="polite">
					<span class="wkpd-spinner" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Loading dashboard…', 'woo-kapso' ); ?></span>
				</div>

				<div class="wkpd-content" id="wkpd-content" hidden>
					<section class="wkpd-kpi" aria-label="<?php esc_attr_e( 'Key metrics', 'woo-kapso' ); ?>">
						<article class="wkpd-card wkpd-card--accent-blue">
							<span class="wkpd-card__accent" aria-hidden="true"></span>
							<div class="wkpd-card__icon" aria-hidden="true">
								<span class="dashicons dashicons-cart"></span>
							</div>
							<div class="wkpd-card__body">
								<h2 class="wkpd-card__title"><?php esc_html_e( 'Total Orders', 'woo-kapso' ); ?></h2>
								<p class="wkpd-card__num" id="wkpd-kpi-total">—</p>
								<p class="wkpd-card__sub"><?php esc_html_e( 'Last 7 days', 'woo-kapso' ); ?></p>
							</div>
						</article>
						<article class="wkpd-card wkpd-card--accent-green">
							<span class="wkpd-card__accent" aria-hidden="true"></span>
							<div class="wkpd-card__icon" aria-hidden="true">
								<span class="dashicons dashicons-yes-alt"></span>
							</div>
							<div class="wkpd-card__body">
								<h2 class="wkpd-card__title"><?php esc_html_e( 'Confirmed Orders', 'woo-kapso' ); ?></h2>
								<p class="wkpd-card__num" id="wkpd-kpi-confirmed">—</p>
								<p class="wkpd-card__sub"><?php esc_html_e( 'Processing / On-hold', 'woo-kapso' ); ?></p>
							</div>
						</article>
						<article class="wkpd-card wkpd-card--accent-red">
							<span class="wkpd-card__accent" aria-hidden="true"></span>
							<div class="wkpd-card__icon" aria-hidden="true">
								<span class="dashicons dashicons-dismiss"></span>
							</div>
							<div class="wkpd-card__body">
								<h2 class="wkpd-card__title"><?php esc_html_e( 'Cancelled Orders', 'woo-kapso' ); ?></h2>
								<p class="wkpd-card__num" id="wkpd-kpi-cancelled">—</p>
								<p class="wkpd-card__sub"><?php esc_html_e( 'Last 7 days', 'woo-kapso' ); ?></p>
							</div>
						</article>
						<article class="wkpd-card wkpd-card--accent-amber">
							<span class="wkpd-card__accent" aria-hidden="true"></span>
							<div class="wkpd-card__icon" aria-hidden="true">
								<span class="dashicons dashicons-airplane"></span>
							</div>
							<div class="wkpd-card__body">
								<h2 class="wkpd-card__title"><?php esc_html_e( 'Shipped Orders', 'woo-kapso' ); ?></h2>
								<p class="wkpd-card__num" id="wkpd-kpi-shipped">—</p>
								<p class="wkpd-card__sub"><?php esc_html_e( 'Completed', 'woo-kapso' ); ?></p>
							</div>
						</article>
					</section>

					<section class="wkpd-charts">
						<div class="wkpd-panel">
							<h3 class="wkpd-panel__title"><?php esc_html_e( 'Orders over time', 'woo-kapso' ); ?></h3>
							<div class="wkpd-chart-wrap">
								<canvas id="wkpd-chart-line" height="280"></canvas>
							</div>
						</div>
						<div class="wkpd-panel">
							<h3 class="wkpd-panel__title"><?php esc_html_e( 'Confirmed vs cancelled', 'woo-kapso' ); ?></h3>
							<div class="wkpd-chart-wrap">
								<canvas id="wkpd-chart-bar" height="280"></canvas>
							</div>
						</div>
					</section>

					<section class="wkpd-insights" aria-label="<?php esc_attr_e( 'Insights', 'woo-kapso' ); ?>">
						<h3 class="wkpd-insights__heading"><?php esc_html_e( 'Rates', 'woo-kapso' ); ?></h3>
						<div class="wkpd-insights__grid">
							<div class="wkpd-insight">
								<span class="wkpd-insight__label"><?php esc_html_e( 'Confirmation rate', 'woo-kapso' ); ?></span>
								<strong class="wkpd-insight__val" id="wkpd-rate-confirm">—</strong>
							</div>
							<div class="wkpd-insight wkpd-insight--divider">
								<span class="wkpd-insight__label"><?php esc_html_e( 'Cancellation rate', 'woo-kapso' ); ?></span>
								<strong class="wkpd-insight__val" id="wkpd-rate-cancel">—</strong>
							</div>
						</div>
						<div class="wkpd-alert wkpd-alert--danger" id="wkpd-alert-cancel" hidden role="alert">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<p id="wkpd-alert-cancel-text"></p>
						</div>
					</section>

					<section class="wkpd-table-section">
						<h3 class="wkpd-panel__title"><?php esc_html_e( 'Recent orders', 'woo-kapso' ); ?></h3>
						<div class="wkpd-table-wrap">
							<table class="wkpd-table widefat">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Order', 'woo-kapso' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Customer', 'woo-kapso' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'woo-kapso' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Tracking', 'woo-kapso' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Date', 'woo-kapso' ); ?></th>
									</tr>
								</thead>
								<tbody id="wkpd-recent-body">
									<tr><td colspan="5"><?php esc_html_e( 'Loading…', 'woo-kapso' ); ?></td></tr>
								</tbody>
							</table>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}
}
