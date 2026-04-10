<?php
/**
 * Bosta shipment helpers — order meta + WhatsApp tracking message.
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read Bosta tracking from order meta (HPOS-safe) with legacy post_meta fallback.
 *
 * @param int $order_id Order ID.
 * @return string Non-empty tracking or empty string.
 */
function wookapso_get_bosta_tracking_for_order( $order_id ): string {
	$order_id = absint( $order_id );
	if ( $order_id < 1 ) {
		return '';
	}
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$t = $order->get_meta( '_bosta_tracking', true );
		if ( is_string( $t ) && $t !== '' ) {
			return $t;
		}
	}
	$legacy = get_post_meta( $order_id, '_bosta_tracking', true );
	return is_string( $legacy ) && $legacy !== '' ? $legacy : '';
}

/**
 * Create a Bosta shipment for an order and persist tracking meta.
 *
 * @param int  $order_id                    Order ID.
 * @param bool $require_automation_enabled When true (default), requires automation toggle + API key. When false (e.g. admin button), only API key must be set.
 * @return array{success:bool,tracking_number?:string,error?:\WP_Error|string}|array{success:bool,error?:\WP_Error|string}
 */
function wookapso_create_bosta_shipment( $order_id, $require_automation_enabled = true ) {
	$order_id = absint( $order_id );
	if ( $order_id < 1 ) {
		return [
			'success' => false,
			'error'   => new WP_Error( 'invalid_order', 'Invalid order ID.' ),
		];
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return [
			'success' => false,
			'error'   => new WP_Error( 'not_found', 'Order not found.' ),
		];
	}

	$api_key = (string) get_option( WooKapso_Bosta::OPTION_API_KEY, '' );
	if ( $api_key === '' ) {
		return [
			'success' => false,
			'error'   => new WP_Error( 'no_key', 'Bosta API key is not configured.' ),
		];
	}

	if ( $require_automation_enabled && ! wookapso_bosta_is_enabled() ) {
		return [
			'success' => false,
			'error'   => new WP_Error( 'disabled', 'Bosta automation is disabled.' ),
		];
	}

	$existing = wookapso_get_bosta_tracking_for_order( $order_id );
	if ( $existing !== '' ) {
		WooKapso_Logger::log(
			$order_id,
			(string) $order->get_billing_phone(),
			'bosta_create',
			'sent',
			'skipped_duplicate:' . $existing
		);
		return [
			'success'           => true,
			'tracking_number'   => $existing,
			'skipped_duplicate' => true,
		];
	}

	$bosta = new WooKapso_Bosta();
	$result = $bosta->create_delivery_from_order( $order );

	if ( ! empty( $result['duplicate'] ) ) {
		return [
			'success'           => true,
			'tracking_number'   => (string) $order->get_meta( '_bosta_tracking', true ),
			'skipped_duplicate' => true,
		];
	}

	if ( empty( $result['success'] ) ) {
		$msg = isset( $result['error_message'] ) ? (string) $result['error_message'] : 'Unknown error';
		WooKapso_Logger::log(
			$order_id,
			(string) $order->get_billing_phone(),
			'bosta_create',
			'error',
			$msg
		);
		if ( class_exists( 'WooKapso_Message_Templates' ) ) {
			$order->add_order_note(
				WooKapso_Message_Templates::build_failure_order_note(
					'bosta_shipment',
					$order,
					$msg,
					[
						'template_name' => 'Bosta API',
					]
				)
			);
		} else {
			$order->add_order_note( 'WooKapso Bosta: فشل إنشاء الشحنة — ' . $msg );
		}
		return [
			'success' => false,
			'error'   => new WP_Error( 'bosta_error', $msg ),
		];
	}

	$tracking = (string) $result['tracking_number'];
	$order->update_meta_data( '_bosta_tracking', $tracking );
	$order->update_meta_data( '_bosta_status', 'created' );
	$order->update_meta_data( '_bosta_last_error', '' );
	$order->update_meta_data( '_tracking_number', $tracking );
	$order->save();

	WooKapso_Logger::log(
		$order_id,
		(string) $order->get_billing_phone(),
		'bosta_create',
		'sent',
		$tracking
	);
	$order->add_order_note( 'WooKapso Bosta: تم إنشاء الشحنة. Tracking: ' . $tracking );

	return [
		'success'         => true,
		'tracking_number' => $tracking,
	];
}

/**
 * Send WhatsApp text with tracking info (24h session).
 *
 * @param int         $order_id   Order ID.
 * @param string      $tracking   Tracking number.
 * @param string|null $to_e164    Optional WhatsApp number (E.164). Defaults to billing phone.
 * @return mixed API response or false.
 */
function wookapso_send_tracking_message( $order_id, $tracking, $to_e164 = null ) {
	$order_id = absint( $order_id );
	$tracking = sanitize_text_field( $tracking );
	$order    = wc_get_order( $order_id );

	if ( ! $order || $tracking === '' ) {
		return false;
	}

	$phone = is_string( $to_e164 ) && $to_e164 !== '' ? $to_e164 : $order->get_billing_phone();
	if ( $phone === '' && $order->get_shipping_phone() ) {
		$phone = $order->get_shipping_phone();
	}
	if ( $phone === '' ) {
		return false;
	}

	$base = (string) get_option( 'wookapso_bosta_tracking_url_base', 'https://business.bosta.co/track-shipment' );
	$base = untrailingslashit( $base );
	$url  = $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 'tracking-number=' . rawurlencode( $tracking );

	/**
	 * Tracking URL for customer message.
	 *
	 * @param string   $url      URL.
	 * @param string   $tracking Tracking number.
	 * @param WC_Order $order    Order.
	 */
	$url = apply_filters( 'wookapso_bosta_tracking_url', $url, $tracking, $order );

	$name = $order->get_billing_first_name();
	if ( $name === '' ) {
		$name = __( 'عزيزنا العميل', 'woo-kapso' );
	}
	/* translators: 1: name, 2: order id, 3: tracking, 4: URL */
	$template = __( "مرحباً %1\$s،\n\nتم تأكيد طلبك بنجاح.\nرقم الطلب: #%2\$s\nرقم التتبع: %3\$s\n\nالتسليم المتوقع خلال 2–3 أيام عمل.\nتتبع الشحنة:\n%4\$s\n\nشكراً لثقتك بنا.", 'woo-kapso' );
	$message  = sprintf( $template, $name, (string) $order_id, $tracking, $url );

	/**
	 * Full WhatsApp body for tracking message.
	 *
	 * @param string   $message Default message.
	 * @param WC_Order $order   Order.
	 * @param string   $tracking Tracking.
	 */
	$message = apply_filters( 'wookapso_bosta_tracking_message', $message, $order, $tracking );

	$api      = new WooKapso_API();
	$response = $api->send_text( $phone, $message, $order_id );
	$ok       = is_array( $response ) && isset( $response['messages'][0]['id'] );
	WooKapso_Logger::log(
		$order_id,
		(string) $phone,
		'whatsapp_tracking',
		$ok ? 'sent' : 'error',
		$ok
			? (string) ( $response['messages'][0]['id'] ?? 'ok' )
			: wp_json_encode( WooKapso_API::explain_send_failure( is_array( $response ) ? $response : [] ), JSON_UNESCAPED_UNICODE )
	);

	return $response;
}

/**
 * Resend tracking WhatsApp (admin). Uses billing phone if no override.
 *
 * @param int $order_id Order ID.
 * @return array{success:bool,message?:string}
 */
function wookapso_resend_tracking_message( $order_id ) {
	$order_id = absint( $order_id );
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return [ 'success' => false, 'message' => __( 'Order not found.', 'woo-kapso' ) ];
	}
	$tracking = wookapso_get_bosta_tracking_for_order( $order_id );
	if ( $tracking === '' ) {
		return [ 'success' => false, 'message' => __( 'No Bosta tracking number yet.', 'woo-kapso' ) ];
	}
	$res = wookapso_send_tracking_message( $order_id, $tracking, null );
	$ok  = is_array( $res ) && isset( $res['messages'][0]['id'] );
	if ( $ok ) {
		$order->add_order_note( __( 'WooKapso: Tracking message resent via WhatsApp.', 'woo-kapso' ) );
		return [ 'success' => true ];
	}
	$detail = class_exists( 'WooKapso_Message_Templates' )
		? WooKapso_Message_Templates::kapso_response_error_summary( $res )
		: '';
	if ( class_exists( 'WooKapso_Message_Templates' ) && $detail !== '' ) {
		$order->add_order_note(
			WooKapso_Message_Templates::build_failure_order_note(
				'tracking_resend',
				$order,
				$detail,
				[
					'template_name' => __( 'رسالة نصية (تتبع)', 'woo-kapso' ),
					'error_code'    => WooKapso_Message_Templates::kapso_response_error_code( is_array( $res ) ? $res : [] ),
				]
			)
		);
	}
	return [
		'success' => false,
		'message' => __( 'Failed to send WhatsApp message.', 'woo-kapso' ),
		'detail'  => $detail !== '' ? $detail : __( 'No details from API.', 'woo-kapso' ),
	];
}

/**
 * Whether Bosta automation is on and API key set.
 */
function wookapso_bosta_is_enabled(): bool {
	if ( (string) get_option( WooKapso_Bosta::OPTION_ENABLED, '0' ) !== '1' ) {
		return false;
	}
	$key = (string) get_option( WooKapso_Bosta::OPTION_API_KEY, '' );
	return $key !== '';
}
