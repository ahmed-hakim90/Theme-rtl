<?php
/**
 * WP-Cron: resend confirmation reminder and auto-cancel unconfirmed orders.
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Cron
 */
class WooKapso_Cron {

	public const HOOK_REMINDER    = 'wookapso_reminder_order';
	public const HOOK_AUTO_CANCEL = 'wookapso_auto_cancel_order';

	public function __construct() {
		add_action( self::HOOK_REMINDER, [ $this, 'run_reminder' ], 10, 1 );
		add_action( self::HOOK_AUTO_CANCEL, [ $this, 'run_auto_cancel' ], 10, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'maybe_clear_on_status' ], 25, 4 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'bust_metrics_cache' ], 99, 0 );
	}

	/**
	 * Refresh cached order metrics when any order status changes.
	 */
	public function bust_metrics_cache(): void {
		WooKapso_Order_Metrics::bust_cache();
	}

	/**
	 * Delay before first reminder (seconds).
	 */
	public static function get_reminder_delay(): int {
		$h = absint( get_option( 'wookapso_reminder_hours', 2 ) );
		if ( $h < 1 ) {
			$h = 2;
		}
		return (int) apply_filters( 'wookapso_reminder_delay_seconds', $h * HOUR_IN_SECONDS );
	}

	/**
	 * Delay before auto-cancel (seconds).
	 */
	public static function get_cancel_delay(): int {
		$h = absint( get_option( 'wookapso_auto_cancel_hours', 24 ) );
		if ( $h < 2 ) {
			$h = 24;
		}
		return (int) apply_filters( 'wookapso_auto_cancel_delay_seconds', $h * HOUR_IN_SECONDS );
	}

	/**
	 * Schedule reminder + auto-cancel when initial WhatsApp confirmation is sent.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function schedule_pending_confirmation_tasks( int $order_id ): void {
		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( (string) $order->get_meta( '_wookapso_cron_scheduled', true ) === '1' ) {
			return;
		}

		self::clear_events( $order_id );

		$t_remind = time() + self::get_reminder_delay();
		$t_cancel = time() + self::get_cancel_delay();

		wp_schedule_single_event( $t_remind, self::HOOK_REMINDER, [ $order_id ] );
		wp_schedule_single_event( $t_cancel, self::HOOK_AUTO_CANCEL, [ $order_id ] );

		$order->update_meta_data( '_wookapso_cron_scheduled', '1' );
		$order->save();
	}

	/**
	 * Clear WP-Cron hooks and order meta flag.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function clear_events( int $order_id ): void {
		$order_id = absint( $order_id );
		wp_clear_scheduled_hook( self::HOOK_REMINDER, [ $order_id ] );
		wp_clear_scheduled_hook( self::HOOK_AUTO_CANCEL, [ $order_id ] );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete_meta_data( '_wookapso_cron_scheduled' );
			$order->save();
		}
	}

	/**
	 * Stop timers when order is no longer waiting for WhatsApp confirmation.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from_status From.
	 * @param string   $to_status To.
	 * @param WC_Order $order Order.
	 */
	public function maybe_clear_on_status( $order_id, $from_status, $to_status, $order ): void {
		unset( $from_status );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( in_array( $to_status, [ 'pending', 'on-hold' ], true ) ) {
			return;
		}
		self::clear_events( (int) $order_id );
	}

	/**
	 * @param int|string $order_id Order ID.
	 */
	public function run_reminder( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( [ 'pending', 'on-hold' ] ) ) {
			return;
		}

		if ( absint( $order->get_meta( '_wookapso_reminder_sent', true ) ) >= 1 ) {
			return;
		}

		if ( ! (bool) get_option( 'wookapso_enable_new_order', 0 ) ) {
			return;
		}

		$attempt = absint( $order->get_meta( '_wookapso_retry_count', true ) ) + 1;
		$order->update_meta_data( '_wookapso_retry_count', (string) $attempt );

		$send = WooKapso_Order_Hooks::send_new_order_confirmation_template( $order );
		$sent = ! empty( $send['success'] );

		$order->update_meta_data( '_wookapso_reminder_sent', '1' );
		$order->save();

		$log_note = $sent
			? 'retry_attempt=' . $attempt . ' sent'
			: 'retry_attempt=' . $attempt . ' failed: ' . ( $send['error_detail'] ?? '' );
		WooKapso_Logger::log(
			$order_id,
			(string) $order->get_billing_phone(),
			'cron_reminder',
			$sent ? 'sent' : 'error',
			$log_note
		);

		if ( $sent ) {
			$order->add_order_note( __( 'WooKapso: Reminder confirmation message sent (scheduled).', 'woo-kapso' ) );
		} elseif ( class_exists( 'WooKapso_Message_Templates' ) ) {
			$detail = $send['error_detail'] ?? WooKapso_Message_Templates::kapso_response_error_summary( $send['response'] ?? [] );
			$order->add_order_note(
				WooKapso_Message_Templates::build_failure_order_note(
					'cron_reminder',
					$order,
					$detail,
					[
						'template_name' => (string) get_option( 'wookapso_tpl_new_order', '' ),
						'error_code'    => WooKapso_Message_Templates::kapso_response_error_code( $send['response'] ?? [] ),
					]
				)
			);
		}
	}

	/**
	 * @param int|string $order_id Order ID.
	 */
	public function run_auto_cancel( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( [ 'pending', 'on-hold' ] ) ) {
			return;
		}

		if ( (string) get_option( 'wookapso_auto_cancel_unconfirmed', '1' ) !== '1' ) {
			return;
		}

		$order->update_status(
			'cancelled',
			__( 'WooKapso: Auto-cancelled — no confirmation within the configured time.', 'woo-kapso' )
		);

		WooKapso_Logger::log(
			$order_id,
			(string) $order->get_billing_phone(),
			'cron_auto_cancel',
			'sent',
			'auto_cancelled'
		);
	}
}
