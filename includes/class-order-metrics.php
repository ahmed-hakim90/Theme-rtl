<?php
/**
 * WooCommerce order counts for WooKapso (SQL aggregation + transient cache).
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Order_Metrics
 */
class WooKapso_Order_Metrics {

	private const CACHE_KEY = 'wookapso_order_metrics_v2';
	private const CACHE_TTL = 300;

	/**
	 * Cached metrics including rates (percent 0–100).
	 *
	 * @return array{
	 *   total:int,
	 *   confirmed:int,
	 *   cancelled:int,
	 *   shipped:int,
	 *   confirmation_rate:float,
	 *   cancellation_rate:float
	 * }
	 */
	public static function get_counts(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['total'], $cached['confirmation_rate'] ) ) {
			return $cached;
		}

		$counts = self::query_counts();
		set_transient( self::CACHE_KEY, $counts, self::CACHE_TTL );

		return $counts;
	}

	/**
	 * Invalidate cached metrics.
	 */
	public static function bust_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Whether HPOS (custom order tables) is active.
	 */
	private static function is_hpos(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Aggregate counts from DB without loading order IDs.
	 *
	 * @return array{
	 *   total:int,
	 *   confirmed:int,
	 *   cancelled:int,
	 *   shipped:int,
	 *   confirmation_rate:float,
	 *   cancellation_rate:float
	 * }
	 */
	private static function query_counts(): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return self::empty_metrics();
		}

		$valid = array_keys( wc_get_order_statuses() );
		if ( empty( $valid ) ) {
			return self::empty_metrics();
		}

		global $wpdb;

		if ( self::is_hpos() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT status, COUNT(*) AS c FROM {$table} WHERE type = %s GROUP BY status",
					'shop_order'
				),
				ARRAY_A
			);
			if ( empty( $wpdb->last_error ) && is_array( $rows ) ) {
				return self::aggregate_rows( $rows, 'status', $valid );
			}
		}

		// Legacy: wp_posts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT post_status AS status, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = 'shop_order' GROUP BY post_status",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return self::empty_metrics();
		}

		return self::aggregate_rows( $rows, 'status', $valid );
	}

	/**
	 * Normalize status to wc-* for comparison (HPOS may omit prefix).
	 *
	 * @param string $st Raw status.
	 */
	private static function normalize_status( string $st ): string {
		if ( $st === '' || $st === 'trash' ) {
			return $st;
		}
		if ( strpos( $st, 'wc-' ) === 0 ) {
			return $st;
		}
		return 'wc-' . $st;
	}

	/**
	 * @param array  $rows     DB rows.
	 * @param string $key_field status column name.
	 * @param array  $valid    Allowed WC status keys (wc-*).
	 */
	private static function aggregate_rows( array $rows, string $key_field, array $valid ): array {
		$valid_map = array_flip( $valid );
		$total     = 0;
		$confirmed = 0;
		$cancelled = 0;
		$shipped   = 0;

		foreach ( $rows as $row ) {
			$raw = isset( $row[ $key_field ] ) ? (string) $row[ $key_field ] : '';
			$st  = self::normalize_status( $raw );
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( $raw === 'trash' || ! isset( $valid_map[ $st ] ) ) {
				continue;
			}
			$total += $c;
			if ( in_array( $st, [ 'wc-processing', 'wc-on-hold' ], true ) ) {
				$confirmed += $c;
			}
			if ( $st === 'wc-cancelled' ) {
				$cancelled += $c;
			}
			if ( $st === 'wc-completed' ) {
				$shipped += $c;
			}
		}

		$moved_forward = $confirmed + $shipped;
		$conf_rate     = $total > 0 ? round( ( $moved_forward / $total ) * 100, 1 ) : 0.0;
		$cancel_rate   = $total > 0 ? round( ( $cancelled / $total ) * 100, 1 ) : 0.0;

		$out = [
			'total'               => $total,
			'confirmed'           => $confirmed,
			'cancelled'           => $cancelled,
			'shipped'             => $shipped,
			'confirmation_rate'   => $conf_rate,
			'cancellation_rate'   => $cancel_rate,
		];

		return apply_filters( 'wookapso_order_metrics', $out );
	}

	/**
	 * @return array{
	 *   total:int,
	 *   confirmed:int,
	 *   cancelled:int,
	 *   shipped:int,
	 *   confirmation_rate:float,
	 *   cancellation_rate:float
	 * }
	 */
	private static function empty_metrics(): array {
		return [
			'total'             => 0,
			'confirmed'         => 0,
			'cancelled'         => 0,
			'shipped'           => 0,
			'confirmation_rate' => 0.0,
			'cancellation_rate' => 0.0,
		];
	}
}
