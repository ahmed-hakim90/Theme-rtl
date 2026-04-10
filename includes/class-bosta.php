<?php
/**
 * Bosta API client — create deliveries (v2).
 *
 * Payload shape may vary by Bosta account; use filter `wookapso_bosta_delivery_payload` to adjust.
 * Base URLs: live uses app.bosta.co; sandbox may use the same host with a test API key — override via `wookapso_bosta_api_base` filter if needed.
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Bosta
 */
class WooKapso_Bosta {

	/**
	 * Option keys (also registered in WooKapso_Bosta_Admin).
	 */
	public const OPTION_API_KEY      = 'wookapso_bosta_api_key';
	public const OPTION_ENVIRONMENT  = 'wookapso_bosta_environment';
	public const OPTION_ENABLED      = 'wookapso_bosta_enabled';

	/**
	 * Default API roots (no trailing slash).
	 */
	private const URL_LIVE    = 'https://app.bosta.co/api/v2';
	private const URL_SANDBOX = 'https://app.bosta.co/api/v2';

	/**
	 * Create a delivery from a WooCommerce order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array{success:bool,tracking_number?:string,error_message?:string,raw?:array|string}
	 */
	public function create_delivery_from_order( WC_Order $order ): array {
		$api_key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( $api_key === '' ) {
			return [
				'success'       => false,
				'error_message' => 'Bosta API key is not configured.',
			];
		}

		$existing = function_exists( 'wookapso_get_bosta_tracking_for_order' )
			? wookapso_get_bosta_tracking_for_order( $order->get_id() )
			: (string) $order->get_meta( '_bosta_tracking', true );
		if ( $existing !== '' ) {
			return [
				'success'       => false,
				'error_message' => 'duplicate_shipment',
				'duplicate'     => true,
			];
		}

		$payload = $this->build_delivery_payload( $order );
		$url     = trailingslashit( $this->get_api_base() ) . 'deliveries';

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'    => 'application/json',
					'Accept'          => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			error_log( '[WooKapso Bosta] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return [
				'success'       => false,
				'error_message' => $msg,
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return [
				'success'       => false,
				'error_message' => 'Invalid JSON response (HTTP ' . $code . ').',
				'raw'           => $body,
			];
		}

		if ( $code < 200 || $code >= 300 ) {
			$err = $data['message'] ?? $data['error'] ?? $data['msg'] ?? wp_json_encode( $data );
			if ( is_array( $err ) ) {
				$err = wp_json_encode( $err );
			}
			return [
				'success'       => false,
				'error_message' => is_string( $err ) ? $err : 'HTTP ' . $code,
				'raw'           => $data,
			];
		}

		$tracking = $this->parse_tracking_number( $data );
		if ( $tracking === null || $tracking === '' ) {
			return [
				'success'       => false,
				'error_message' => 'Response OK but tracking number not found.',
				'raw'           => $data,
			];
		}

		return [
			'success'         => true,
			'tracking_number' => $tracking,
			'raw'             => $data,
		];
	}

	/**
	 * Public API base (filtered).
	 */
	public function get_api_base(): string {
		$env = sanitize_key( (string) get_option( self::OPTION_ENVIRONMENT, 'live' ) );
		$base = ( 'sandbox' === $env ) ? self::URL_SANDBOX : self::URL_LIVE;
		/**
		 * Override Bosta API base URL (e.g. staging).
		 *
		 * @param string   $base Base URL without trailing slash.
		 * @param string   $env  live|sandbox.
		 */
		return (string) apply_filters( 'wookapso_bosta_api_base', $base, $env );
	}

	/**
	 * Build request body from order + plugin options.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public function build_delivery_payload( WC_Order $order ): array {
		$phone = $order->get_billing_phone();
		if ( $phone === '' && $order->get_shipping_phone() ) {
			$phone = $order->get_shipping_phone();
		}

		$api    = new WooKapso_API();
		$e164   = $api->format_phone( $phone );
		$first  = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last   = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$line1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
		$line2  = trim( (string) $order->get_shipping_address_2() . ' ' . (string) $order->get_billing_address_2() );
		$city   = $order->get_shipping_city() ?: $order->get_billing_city();
		$state  = $order->get_shipping_state() ?: $order->get_billing_state();
		$full   = trim( $line1 . ( $line2 !== '' ? ', ' . $line2 : '' ) );

		// COD amount for courier: unpaid orders typically need cash collection.
		$cod = ! $order->is_paid() ? (float) $order->get_total() : 0.0;

		$pickup = [
			'firstLine'  => sanitize_text_field( (string) get_option( 'wookapso_bosta_pickup_first_line', '' ) ),
			'secondLine' => sanitize_text_field( (string) get_option( 'wookapso_bosta_pickup_second_line', '' ) ),
			'city'       => sanitize_text_field( (string) get_option( 'wookapso_bosta_pickup_city', '' ) ),
			'zone'       => sanitize_text_field( (string) get_option( 'wookapso_bosta_pickup_zone', '' ) ),
		];

		$pickup_district = sanitize_text_field( (string) get_option( 'wookapso_bosta_pickup_district_id', '' ) );
		if ( $pickup_district !== '' ) {
			$pickup['districtId'] = $pickup_district;
		}

		$drop = [
			'firstLine' => $full !== '' ? $full : $line1,
			'city'      => $city ?: '—',
			'zone'      => $state ?: sanitize_text_field( (string) get_option( 'wookapso_bosta_dropoff_zone_fallback', '' ) ),
		];

		$drop_district = sanitize_text_field( (string) get_option( 'wookapso_bosta_dropoff_district_id', '' ) );
		if ( $drop_district !== '' ) {
			$drop['districtId'] = $drop_district;
		}

		$payload = [
			'type'              => 10,
			'businessReference' => 'WC-' . $order->get_order_number(),
			'notes'             => sprintf(
				/* translators: %s: order ID */
				__( 'WooCommerce order #%s', 'woo-kapso' ),
				(string) $order->get_id()
			),
			'specs'             => [
				'packageType' => sanitize_text_field( (string) get_option( 'wookapso_bosta_package_type', 'Parcel' ) ),
				'size'        => sanitize_text_field( (string) get_option( 'wookapso_bosta_package_size', 'SMALL' ) ),
			],
			'packageDetails'    => [
				'itemsCount'  => max( 1, (int) $order->get_item_count() ),
				'description' => 'Order #' . $order->get_order_number(),
			],
			'customer'          => [
				'firstName'   => $first ?: __( 'Customer', 'woo-kapso' ),
				'lastName'    => $last ?: '—',
				'phone'       => $e164,
				'secondPhone' => '',
			],
			'pickupAddress'     => $pickup,
			'dropOffAddress'    => $drop,
			'cod'               => round( $cod, 2 ),
		];

		/**
		 * Filter the full Bosta delivery payload before send.
		 *
		 * @param array    $payload Delivery body.
		 * @param WC_Order $order   Order.
		 */
		return apply_filters( 'wookapso_bosta_delivery_payload', $payload, $order );
	}

	/**
	 * Extract tracking number from JSON body (several possible shapes).
	 *
	 * @param array<string,mixed> $data Decoded response.
	 */
	private function parse_tracking_number( array $data ): ?string {
		$candidates = [
			$data['trackingNumber'] ?? null,
			$data['tracking_number'] ?? null,
			isset( $data['data']['trackingNumber'] ) ? $data['data']['trackingNumber'] : null,
			isset( $data['data']['tracking_number'] ) ? $data['data']['tracking_number'] : null,
			isset( $data['delivery']['trackingNumber'] ) ? $data['delivery']['trackingNumber'] : null,
			isset( $data['Delivery']['trackingNumber'] ) ? $data['Delivery']['trackingNumber'] : null,
		];
		foreach ( $candidates as $c ) {
			if ( is_string( $c ) && $c !== '' ) {
				return $c;
			}
			if ( is_numeric( $c ) ) {
				return (string) $c;
			}
		}
		return null;
	}
}
