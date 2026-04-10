<?php
/**
 * Bosta admin: settings registration, order meta box, AJAX create shipment.
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Bosta_Admin
 */
class WooKapso_Bosta_Admin {

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_assets' ] );
		add_action( 'wp_ajax_wookapso_bosta_create_shipment', [ $this, 'ajax_create_shipment' ] );
		add_action( 'wp_ajax_wookapso_bosta_resend_tracking', [ $this, 'ajax_resend_tracking' ] );
	}

	/**
	 * Register options in the same group as Kapso (single options.php save).
	 */
	public function register_settings(): void {
		register_setting(
			'wookapso_group',
			WooKapso_Bosta::OPTION_API_KEY,
			[
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);
		register_setting(
			'wookapso_group',
			WooKapso_Bosta::OPTION_ENVIRONMENT,
			[
				'sanitize_callback' => function ( $v ) {
					$v = sanitize_key( (string) $v );
					return in_array( $v, [ 'live', 'sandbox' ], true ) ? $v : 'live';
				},
				'default'           => 'live',
			]
		);
		register_setting(
			'wookapso_group',
			WooKapso_Bosta::OPTION_ENABLED,
			[
				'sanitize_callback' => function ( $v ) {
					return ! empty( $v ) ? '1' : '0';
				},
				'default'           => '0',
			]
		);
		$text = [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ];
		foreach (
			[
				'wookapso_bosta_pickup_first_line',
				'wookapso_bosta_pickup_second_line',
				'wookapso_bosta_pickup_city',
				'wookapso_bosta_pickup_zone',
				'wookapso_bosta_pickup_district_id',
				'wookapso_bosta_dropoff_district_id',
				'wookapso_bosta_dropoff_zone_fallback',
				'wookapso_bosta_package_type',
				'wookapso_bosta_package_size',
			] as $key
		) {
			register_setting( 'wookapso_group', $key, $text );
		}

		register_setting(
			'wookapso_group',
			'wookapso_bosta_tracking_url_base',
			[
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://business.bosta.co/track-shipment',
			]
		);

		register_setting(
			'wookapso_group',
			'wookapso_reminder_hours',
			[
				'sanitize_callback' => static function ( $v ) {
					$n = absint( $v );
					return (string) max( 1, $n );
				},
				'default'           => '2',
			]
		);
		register_setting(
			'wookapso_group',
			'wookapso_auto_cancel_hours',
			[
				'sanitize_callback' => static function ( $v ) {
					$n = absint( $v );
					return (string) max( 2, $n );
				},
				'default'           => '24',
			]
		);
		register_setting(
			'wookapso_group',
			'wookapso_auto_cancel_unconfirmed',
			[
				'sanitize_callback' => static function ( $v ) {
					return ! empty( $v ) ? '1' : '0';
				},
				'default'           => '1',
			]
		);
	}

	/**
	 * Meta box on classic and HPOS order screens.
	 */
	public function add_meta_boxes(): void {
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hp = wc_get_page_screen_id( 'shop-order' );
			if ( $hp && ! in_array( $hp, $screens, true ) ) {
				$screens[] = $hp;
			}
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'wookapso_bosta_shipment',
				__( 'Bosta shipment', 'woo-kapso' ),
				[ $this, 'render_order_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Order edit / HPOS order screen scripts.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_order_assets( string $hook_suffix ): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		$done_label  = esc_js( __( 'Done.', 'woo-kapso' ) );
		$sent_label  = esc_js( __( 'Tracking message sent.', 'woo-kapso' ) );
		$req_failed  = esc_js( __( 'Request failed.', 'woo-kapso' ) );
		wp_add_inline_script(
			'jquery',
			"jQuery(function(\$){function wkpdErr(d){if(!d)return'Error';var m=d.message||'Error';if(d.detail&&d.detail!==m){m+='\\n\\n'+d.detail;}return m;}function wkpdBostaNotice(k,msg){\$('#wookapso-bosta-notice').removeClass('notice-error notice-success notice-info updated').addClass(k==='success'?'notice-success':(k==='error'?'notice-error':'notice-info')).show();\$('#wookapso-bosta-notice-text').text(msg);}\$(document).on('click','#wookapso-bosta-create-btn',function(){var btn=\$(this),id=btn.data('order-id');btn.prop('disabled',true);\$('#wookapso-bosta-notice').hide();\$.post(ajaxurl,{action:'wookapso_bosta_create_shipment',nonce:btn.data('nonce'),order_id:id}).done(function(r){if(r.success&&r.data&&r.data.tracking){\$('#wookapso-bosta-tracking').text(r.data.tracking);\$('#wookapso-bosta-status').text(r.data.status||'created');btn.replaceWith('<span class=\"description\">{$done_label}</span>');}else{wkpdBostaNotice('error',wkpdErr(r.data));btn.prop('disabled',false);}}).fail(function(){wkpdBostaNotice('error','{$req_failed}');btn.prop('disabled',false);});});\$(document).on('click','#wookapso-bosta-resend-btn',function(){var btn=\$(this),id=btn.data('order-id');btn.prop('disabled',true);\$('#wookapso-bosta-notice').hide();\$.post(ajaxurl,{action:'wookapso_bosta_resend_tracking',nonce:btn.data('nonce'),order_id:id}).done(function(r){if(r.success){wkpdBostaNotice('success','{$sent_label}');}else{wkpdBostaNotice('error',wkpdErr(r.data));}btn.prop('disabled',false);}).fail(function(){wkpdBostaNotice('error','{$req_failed}');btn.prop('disabled',false);});});});",
			'after'
		);
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order $object Post or order.
	 */
	public function render_order_meta_box( $object ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order ) {
			if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$order = wc_get_order( absint( $_GET['id'] ) );
			}
		}
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not loaded.', 'woo-kapso' ) . '</p>';
			return;
		}

		$oid      = $order->get_id();
		$tracking = (string) $order->get_meta( '_bosta_tracking', true );
		$status   = (string) $order->get_meta( '_bosta_status', true );
		$nonce    = wp_create_nonce( 'wookapso_bosta_shipment_' . $oid );

		echo '<p><strong>' . esc_html__( 'Tracking', 'woo-kapso' ) . '</strong> ';
		echo '<span id="wookapso-bosta-tracking">' . esc_html( $tracking !== '' ? $tracking : '—' ) . '</span></p>';
		echo '<p><strong>' . esc_html__( 'Shipment status', 'woo-kapso' ) . '</strong> ';
		echo '<span id="wookapso-bosta-status">' . esc_html( $status !== '' ? $status : '—' ) . '</span></p>';

		echo '<div id="wookapso-bosta-notice" class="notice wookapso-bosta-notice-inline" style="display:none;margin:10px 0;padding:8px 12px;" role="alert"><p id="wookapso-bosta-notice-text" style="margin:0;"></p></div>';

		$api_key = (string) get_option( WooKapso_Bosta::OPTION_API_KEY, '' );
		if ( $tracking === '' && $api_key !== '' ) {
			printf(
				'<p><button type="button" class="button button-primary" id="wookapso-bosta-create-btn" data-order-id="%d" data-nonce="%s">%s</button></p>',
				(int) $oid,
				esc_attr( $nonce ),
				esc_html__( 'Create shipment', 'woo-kapso' )
			);
		} elseif ( $tracking === '' ) {
			echo '<p class="description">' . esc_html__( 'Add your Bosta API key under WooKapso → Bosta.', 'woo-kapso' ) . '</p>';
		}

		if ( $tracking !== '' ) {
			printf(
				'<p><button type="button" class="button" id="wookapso-bosta-resend-btn" data-order-id="%d" data-nonce="%s">%s</button></p>',
				(int) $oid,
				esc_attr( $nonce ),
				esc_html__( 'Resend tracking', 'woo-kapso' )
			);
		}
	}

	/**
	 * AJAX: resend tracking WhatsApp.
	 */
	public function ajax_resend_tracking(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id < 1 || ! check_ajax_referer( 'wookapso_bosta_shipment_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-kapso' ) ], 403 );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden.', 'woo-kapso' ) ], 403 );
		}

		if ( ! function_exists( 'wookapso_resend_tracking_message' ) ) {
			wp_send_json_error( [ 'message' => __( 'Handler missing.', 'woo-kapso' ) ] );
		}

		$result = wookapso_resend_tracking_message( $order_id );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success();
		}

		$msg    = $result['message'] ?? __( 'Failed.', 'woo-kapso' );
		$detail = $result['detail'] ?? '';
		wp_send_json_error(
			[
				'message' => $msg,
				'detail'  => $detail !== '' ? $detail : $msg,
			]
		);
	}

	/**
	 * AJAX: create shipment from order screen.
	 */
	public function ajax_create_shipment(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id < 1 || ! check_ajax_referer( 'wookapso_bosta_shipment_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'woo-kapso' ) ], 403 );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Forbidden.', 'woo-kapso' ) ], 403 );
		}

		$result = wookapso_create_bosta_shipment( $order_id, false );
		if ( ! empty( $result['success'] ) && ! empty( $result['tracking_number'] ) ) {
			wp_send_json_success(
				[
					'tracking'          => $result['tracking_number'],
					'status'            => ! empty( $result['skipped_duplicate'] ) ? 'existing' : 'created',
					'skipped_duplicate' => ! empty( $result['skipped_duplicate'] ),
				]
			);
		}

		$msg = __( 'Could not create shipment.', 'woo-kapso' );
		if ( isset( $result['error'] ) && is_wp_error( $result['error'] ) ) {
			$msg = $result['error']->get_error_message();
		}
		wp_send_json_error(
			[
				'message' => $msg,
				'detail'  => $msg,
			]
		);
	}
}
