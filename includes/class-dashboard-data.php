<?php
/**
 * Aggregated WooCommerce data for the WooKapso dashboard (date range, cached).
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Dashboard_Data
 */
class WooKapso_Dashboard_Data {

	private const CACHE_PREFIX = 'wookapso_dash_payload_';
	private const CACHE_TTL    = 300;

	/**
	 * Default lookback in days.
	 */
	private const DEFAULT_DAYS = 7;

	/**
	 * Build full payload for dashboard AJAX (last N days).
	 *
	 * @param int $days Lookback days (default 7).
	 * @return array<string,mixed>
	 */
	public static function get_payload( int $days = self::DEFAULT_DAYS ): array {
		$days = max( 1, min( 90, $days ) );
		$key  = self::CACHE_PREFIX . $days . '_v1';
		$data = get_transient( $key );
		if ( is_array( $data ) && isset( $data['total_orders'], $data['recent_orders'] ) ) {
			return $data;
		}

		$data = self::build_payload( $days );
		set_transient( $key, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Invalidate dashboard cache. Pass day range to clear one key, or null for all (1–90).
	 *
	 * @param int|null $days Default window size, or null to clear all known keys.
	 */
	public static function bust_cache( ?int $days = null ): void {
		if ( null !== $days ) {
			$days = max( 1, min( 90, $days ) );
			delete_transient( self::CACHE_PREFIX . $days . '_v1' );
			return;
		}
		for ( $d = 1; $d <= 90; $d++ ) {
			delete_transient( self::CACHE_PREFIX . $d . '_v1' );
		}
	}

	/**
	 * @param int $days Lookback.
	 * @return array<string,mixed>
	 */
	private static function build_payload( int $days ): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return self::empty_payload();
		}

		$valid = array_keys( wc_get_order_statuses() );
		if ( empty( $valid ) ) {
			return self::empty_payload();
		}

		$valid_map = array_flip( $valid );

		$timeline = self::empty_timeline( $days );
		$day_keys  = array_keys( $timeline );
		$start_gmt = ( $day_keys[0] ?? gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) ) ) . ' 00:00:00';
		$end_gmt   = gmdate( 'Y-m-d H:i:s' );

		$kpi = [
			'total'     => 0,
			'confirmed' => 0,
			'cancelled' => 0,
			'shipped'   => 0,
		];

		if ( self::is_hpos() ) {
			self::aggregate_hpos( $start_gmt, $end_gmt, $valid_map, $timeline, $kpi );
		} else {
			self::aggregate_legacy( $start_gmt, $end_gmt, $valid_map, $timeline, $kpi );
		}

		$total_orders = $kpi['total'];
		$moved        = $kpi['confirmed'] + $kpi['shipped'];
		$conf_rate    = $total_orders > 0 ? round( ( $moved / $total_orders ) * 100, 1 ) : 0.0;
		$cancel_rate  = $total_orders > 0 ? round( ( $kpi['cancelled'] / $total_orders ) * 100, 1 ) : 0.0;

		$orders_by_date = [];
		$confirmed_series = [];
		$cancelled_series = [];
		$labels           = [];

		foreach ( $timeline as $day => $bucket ) {
			$orders_by_date[] = [
				'date'  => $day,
				'count' => $bucket['total'],
			];
			$confirmed_series[] = $bucket['confirmed'];
			$cancelled_series[] = $bucket['cancelled'];
			$labels[]           = self::format_chart_label( $day );
		}

		return [
			'total_orders'       => $kpi['total'],
			'confirmed_orders'   => $kpi['confirmed'],
			'cancelled_orders'   => $kpi['cancelled'],
			'shipped_orders'     => $kpi['shipped'],
			'confirmation_rate'  => $conf_rate,
			'cancellation_rate'  => $cancel_rate,
			'orders_by_date'     => $orders_by_date,
			'chart_labels'       => $labels,
			'confirmed_series'   => $confirmed_series,
			'cancelled_series'   => $cancelled_series,
			'recent_orders'      => self::get_recent_orders( 10 ),
			'days'               => $days,
		];
	}

	/**
	 * @param string $day_ymd Date Y-m-d (GMT bucket).
	 */
	private static function format_chart_label( string $day_ymd ): string {
		$ts = strtotime( $day_ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return $day_ymd;
		}
		return date_i18n( 'M j', $ts );
	}

	/**
	 * Last N calendar days (UTC), oldest → newest keys for charts.
	 *
	 * @return array<string,array{total:int,confirmed:int,cancelled:int,shipped:int}>
	 */
	private static function empty_timeline( int $days ): array {
		$out = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d           = gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
			$out[ $d ] = [
				'total'     => 0,
				'confirmed' => 0,
				'cancelled' => 0,
				'shipped'   => 0,
			];
		}
		return $out;
	}

	/**
	 * @param array<string,bool> $valid_map Valid WC status keys.
	 * @param array<string,array> $timeline By reference.
	 * @param array<string,int>   $kpi By reference.
	 */
	private static function aggregate_hpos( string $start_gmt, string $end_gmt, array $valid_map, array &$timeline, array &$kpi ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(date_created_gmt) AS d, status, COUNT(*) AS c
				FROM {$table}
				WHERE type = %s
				AND date_created_gmt >= %s
				AND date_created_gmt <= %s
				GROUP BY DATE(date_created_gmt), status",
				'shop_order',
				$start_gmt,
				$end_gmt
			),
			ARRAY_A
		);

		if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$raw_d = isset( $row['d'] ) ? (string) $row['d'] : '';
			$raw_s = isset( $row['status'] ) ? (string) $row['status'] : '';
			$c     = isset( $row['c'] ) ? (int) $row['c'] : 0;
			$st    = self::normalize_status( $raw_s );

			if ( ! isset( $valid_map[ $st ] ) ) {
				continue;
			}

			$kpi['total'] += $c;

			if ( in_array( $st, [ 'wc-processing', 'wc-on-hold' ], true ) ) {
				$kpi['confirmed'] += $c;
			}
			if ( $st === 'wc-cancelled' ) {
				$kpi['cancelled'] += $c;
			}
			if ( $st === 'wc-completed' ) {
				$kpi['shipped'] += $c;
			}

			if ( $raw_d === '' || ! isset( $timeline[ $raw_d ] ) ) {
				continue;
			}

			$timeline[ $raw_d ]['total'] += $c;
			if ( in_array( $st, [ 'wc-processing', 'wc-on-hold' ], true ) ) {
				$timeline[ $raw_d ]['confirmed'] += $c;
			}
			if ( $st === 'wc-cancelled' ) {
				$timeline[ $raw_d ]['cancelled'] += $c;
			}
			if ( $st === 'wc-completed' ) {
				$timeline[ $raw_d ]['shipped'] += $c;
			}
		}
	}

	/**
	 * @param array<string,bool> $valid_map Valid WC status keys.
	 * @param array<string,array> $timeline By reference.
	 * @param array<string,int>   $kpi By reference.
	 */
	private static function aggregate_legacy( string $start_gmt, string $end_gmt, array $valid_map, array &$timeline, array &$kpi ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(post_date_gmt) AS d, post_status AS status, COUNT(*) AS c
				FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status != 'trash'
				AND post_date_gmt >= %s
				AND post_date_gmt <= %s
				GROUP BY DATE(post_date_gmt), post_status",
				'shop_order',
				$start_gmt,
				$end_gmt
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$raw_d = isset( $row['d'] ) ? (string) $row['d'] : '';
			$raw_s = isset( $row['status'] ) ? (string) $row['status'] : '';
			$c     = isset( $row['c'] ) ? (int) $row['c'] : 0;
			$st    = self::normalize_status( $raw_s );

			if ( ! isset( $valid_map[ $st ] ) ) {
				continue;
			}

			$kpi['total'] += $c;

			if ( in_array( $st, [ 'wc-processing', 'wc-on-hold' ], true ) ) {
				$kpi['confirmed'] += $c;
			}
			if ( $st === 'wc-cancelled' ) {
				$kpi['cancelled'] += $c;
			}
			if ( $st === 'wc-completed' ) {
				$kpi['shipped'] += $c;
			}

			if ( $raw_d === '' || ! isset( $timeline[ $raw_d ] ) ) {
				continue;
			}

			$timeline[ $raw_d ]['total'] += $c;
			if ( in_array( $st, [ 'wc-processing', 'wc-on-hold' ], true ) ) {
				$timeline[ $raw_d ]['confirmed'] += $c;
			}
			if ( $st === 'wc-cancelled' ) {
				$timeline[ $raw_d ]['cancelled'] += $c;
			}
			if ( $st === 'wc-completed' ) {
				$timeline[ $raw_d ]['shipped'] += $c;
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_recent_orders( int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders(
			[
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			]
		);

		$out = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$oid = $order->get_id();
			$tr  = function_exists( 'wookapso_get_bosta_tracking_for_order' )
				? wookapso_get_bosta_tracking_for_order( $oid )
				: (string) $order->get_meta( '_bosta_tracking', true );

			$out[] = [
				'id'         => $oid,
				'customer'   => $order->get_formatted_billing_full_name() ? $order->get_formatted_billing_full_name() : __( 'Guest', 'woo-kapso' ),
				'status'     => wc_get_order_status_name( $order->get_status() ),
				'status_key' => $order->get_status(),
				'tracking'   => $tr !== '' ? $tr : '—',
				'date'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
				'edit_url'   => $order->get_edit_order_url(),
			];
		}

		return $out;
	}

	private static function normalize_status( string $st ): string {
		if ( $st === '' || $st === 'trash' ) {
			return $st;
		}
		if ( strpos( $st, 'wc-' ) === 0 ) {
			return $st;
		}
		return 'wc-' . $st;
	}

	private static function is_hpos(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_payload(): array {
		$empty_timeline = [];
		$labels         = [];
		for ( $i = 6; $i >= 0; $i-- ) {
			$d                = gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
			$empty_timeline[] = [ 'date' => $d, 'count' => 0 ];
			$labels[]         = self::format_chart_label( $d );
		}
		return [
			'total_orders'       => 0,
			'confirmed_orders'   => 0,
			'cancelled_orders'   => 0,
			'shipped_orders'     => 0,
			'confirmation_rate'  => 0.0,
			'cancellation_rate'  => 0.0,
			'orders_by_date'     => $empty_timeline,
			'chart_labels'       => $labels,
			'confirmed_series'   => array_fill( 0, 7, 0 ),
			'cancelled_series'   => array_fill( 0, 7, 0 ),
			'recent_orders'      => [],
			'days'               => 7,
		];
	}
}
