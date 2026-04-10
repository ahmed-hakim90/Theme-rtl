<?php
/**
 * WooKapso_Order_Hooks
 * Hooks على WooCommerce + REST endpoint لاستقبال ردود الأزرار من Kapso
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WooKapso_Order_Hooks {

    private WooKapso_API $api;

    private const PAYLOAD_CONFIRM = 'CONFIRM_ORDER';
    private const PAYLOAD_CANCEL  = 'CANCEL_ORDER';

    public function __construct() {
        $this->api = new WooKapso_API();
        $this->register();
    }

    private function register(): void {
        add_action( 'woocommerce_order_status_pending',    [ $this, 'on_new_order' ],   10, 2 );
        add_action( 'woocommerce_order_status_on-hold',    [ $this, 'on_new_order' ],   10, 2 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'on_processing' ],  10, 2 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'on_shipped' ],     10, 2 );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'on_cancelled' ],   10, 2 );
        add_action( 'rest_api_init', [ $this, 'register_webhook_endpoint' ] );
    }

    // ── Order Handlers ──────────────────────────────────────────────────────

    public function on_new_order( int $order_id, WC_Order $order ): void {
        if ( ! $this->enabled( 'new_order' ) ) {
            return;
        }
        $phone = $this->get_phone( $order );
        if ( ! $phone ) {
            return;
        }

        $send = self::send_new_order_confirmation_template( $order );
        $ok   = ! empty( $send['success'] );

        if ( $ok ) {
            $order->add_order_note( '✅ WooKapso: رسالة تأكيد بالأزرار اتبعتت على WhatsApp' );
        } elseif ( class_exists( 'WooKapso_Message_Templates' ) ) {
            $detail = $send['error_detail'] ?? WooKapso_Message_Templates::kapso_response_error_summary( $send['response'] ?? [] );
            $order->add_order_note(
                WooKapso_Message_Templates::build_failure_order_note(
                    'new_order_confirmation',
                    $order,
                    $detail,
                    [
                        'template_name' => (string) get_option( 'wookapso_tpl_new_order', '' ),
                        'error_code'    => WooKapso_Message_Templates::kapso_response_error_code( $send['response'] ?? [] ),
                    ]
                )
            );
        } else {
            $order->add_order_note( '❌ WooKapso: فشل إرسال رسالة التأكيد' );
        }

        if ( $ok && class_exists( 'WooKapso_Cron' ) ) {
            WooKapso_Cron::schedule_pending_confirmation_tasks( $order_id );
        }
    }

    /**
     * Send the new-order WhatsApp template with confirm/cancel buttons (used by cron reminder too).
     *
     * @param WC_Order $order Order.
     * @return array{success:bool,error_detail:string,response:mixed}
     */
    public static function send_new_order_confirmation_template( WC_Order $order ): array {
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) {
            $msg = __( 'لا يوجد رقم هاتف في الطلب.', 'woo-kapso' );
            return [
                'success'      => false,
                'error_detail' => $msg,
                'response'     => [],
            ];
        }

        $api        = new WooKapso_API();
        $order_id   = $order->get_id();
        $template   = get_option( 'wookapso_tpl_new_order', 'order_confirmation_buttons_ar' );
        $buttons    = [
            [ 'index' => 0, 'payload' => self::PAYLOAD_CONFIRM . '_' . $order_id ],
            [ 'index' => 1, 'payload' => self::PAYLOAD_CANCEL . '_' . $order_id ],
        ];
        $total_text = number_format( (float) $order->get_total(), 2 ) . ' ' . get_woocommerce_currency_symbol();

        $response = $api->send_template_with_buttons(
            $phone,
            $template,
            [ $order->get_billing_first_name(), (string) $order_id, $total_text ],
            $buttons,
            $order_id
        );

        $ok = isset( $response['messages'][0]['id'] );
        return [
            'success'      => $ok,
            'error_detail' => $ok ? '' : WooKapso_Message_Templates::kapso_response_error_summary( $response ),
            'response'     => $response,
        ];
    }

    public function on_processing( int $order_id, WC_Order $order ): void {
        if ( ! $this->enabled( 'processing' ) ) return;
        // لو جاي من زرار التأكيد — مش محتاج رسالة تانية
        if ( get_post_meta( $order_id, '_wookapso_confirmed_via_button', true ) ) {
            delete_post_meta( $order_id, '_wookapso_confirmed_via_button' );
            return;
        }
        $phone = $this->get_phone( $order );
        if ( ! $phone ) return;
        $this->api->send_template( $phone,
            get_option( 'wookapso_tpl_processing', 'order_processing_ar' ),
            [ $order->get_billing_first_name(), (string) $order_id ], $order_id );
    }

    public function on_shipped( int $order_id, WC_Order $order ): void {
        if ( ! $this->enabled( 'shipped' ) ) return;
        $phone = $this->get_phone( $order );
        if ( ! $phone ) return;
        $this->api->send_template( $phone,
            get_option( 'wookapso_tpl_shipped', 'order_shipped_ar' ),
            [ $order->get_billing_first_name(), (string) $order_id, $this->get_tracking( $order_id ) ],
            $order_id );
        $order->add_order_note( '✅ WooKapso: رسالة الشحن اتبعتت' );
    }

    public function on_cancelled( int $order_id, WC_Order $order ): void {
        if ( ! $this->enabled( 'cancelled' ) ) return;
        if ( get_post_meta( $order_id, '_wookapso_cancelled_via_button', true ) ) {
            delete_post_meta( $order_id, '_wookapso_cancelled_via_button' );
            return;
        }
        $phone = $this->get_phone( $order );
        if ( ! $phone ) return;
        $this->api->send_template( $phone,
            get_option( 'wookapso_tpl_cancelled', 'order_cancelled_ar' ),
            [ $order->get_billing_first_name(), (string) $order_id ], $order_id );
    }

    // ── REST Endpoint ───────────────────────────────────────────────────────

    public function register_webhook_endpoint(): void {
        register_rest_route( 'wookapso/v1', '/inbound', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_inbound' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_inbound( WP_REST_Request $request ): WP_REST_Response {
        $secret = (string) get_option( 'wookapso_webhook_secret', '' );
        if ( $secret !== '' ) {
            $hdr = (string) $request->get_header( 'X-WooKapso-Secret' );
            if ( ! hash_equals( $secret, $hdr ) ) {
                return new WP_REST_Response( [ 'error' => 'forbidden' ], 403 );
            }
        }

        $body = $request->get_json_params();
        $type = $body['message']['type'] ?? '';
        $from = $body['message']['from'] ?? '';

        // ── Button Reply ────────────────────────────────────────────────────
        if ( $type === 'interactive' ) {
            $subtype = $body['message']['interactive']['type'] ?? '';
            if ( $subtype === 'button_reply' ) {
                $payload = $body['message']['interactive']['button_reply']['id'] ?? '';
                $this->handle_button_payload( $payload, $from );
            }
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        // ── Text Fallback (لو العميل كتب بدل ما يضغط) ───────────────────
        if ( $type === 'text' ) {
            $text = mb_strtolower( trim( $body['message']['text']['body'] ?? '' ) );
            $this->handle_text_reply( $text, $from );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    // ── Button Logic ────────────────────────────────────────────────────────

    private function handle_button_payload( string $payload, string $from ): void {
        if ( str_starts_with( $payload, self::PAYLOAD_CONFIRM . '_' ) ) {
            $order_id = (int) str_replace( self::PAYLOAD_CONFIRM . '_', '', $payload );
            $this->confirm_order( $order_id, $from );
            return;
        }
        if ( str_starts_with( $payload, self::PAYLOAD_CANCEL . '_' ) ) {
            $order_id = (int) str_replace( self::PAYLOAD_CANCEL . '_', '', $payload );
            $this->cancel_order( $order_id, $from );
        }
    }

    private function handle_text_reply( string $text, string $from ): void {
        $confirm = [ 'تأكيد', 'تأكد', 'نعم', 'ok', '1', 'yes', 'ايوه', 'أيوه', 'موافق' ];
        $cancel  = [ 'الغاء', 'إلغاء', 'لا', 'no', '2', 'cancel', 'لأ', 'بلاش' ];

        $order = $this->find_pending_order_by_phone( $from );
        if ( ! $order ) return;

        if ( in_array( $text, $confirm, true ) ) {
            $this->confirm_order( $order->get_id(), $from );
        } elseif ( in_array( $text, $cancel, true ) ) {
            $this->cancel_order( $order->get_id(), $from );
        }
    }

    private function confirm_order( int $order_id, string $from ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        update_post_meta( $order_id, '_wookapso_confirmed_via_button', true );
        if ( class_exists( 'WooKapso_Cron' ) ) {
            WooKapso_Cron::clear_events( $order_id );
        }
        $order->update_status( 'processing', 'WooKapso: العميل أكّد الطلب عبر WhatsApp ✅' );

        $bosta_ok = false;
        $tracking = '';
        if ( function_exists( 'wookapso_bosta_is_enabled' ) && function_exists( 'wookapso_create_bosta_shipment' ) && wookapso_bosta_is_enabled() ) {
            $ship = wookapso_create_bosta_shipment( $order_id, true );
            if ( ! empty( $ship['success'] ) && ! empty( $ship['tracking_number'] ) ) {
                $bosta_ok = true;
                $tracking = (string) $ship['tracking_number'];
            }
        }

        if ( $bosta_ok && $tracking !== '' && function_exists( 'wookapso_send_tracking_message' ) ) {
            wookapso_send_tracking_message( $order_id, $tracking, $from );
            return;
        }

        $reply = "شكراً {$order->get_billing_first_name()}! 🎉\n"
               . "تم تأكيد طلبك رقم #{$order_id} بنجاح.\n"
               . "جاري التجهيز وسنُعلمك فور الشحن. 🚚";
        $this->api->send_text( $from, $reply, $order_id );
    }

    private function cancel_order( int $order_id, string $from ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            $this->api->send_text( $from,
                "عذراً، طلبك رقم #{$order_id} لا يمكن إلغاؤه الآن. تواصل معنا مباشرة.",
                $order_id );
            return;
        }

        update_post_meta( $order_id, '_wookapso_cancelled_via_button', true );
        if ( class_exists( 'WooKapso_Cron' ) ) {
            WooKapso_Cron::clear_events( $order_id );
        }
        $order->update_status( 'cancelled', 'WooKapso: العميل ألغى الطلب عبر WhatsApp ❌' );

        $reply = "تم إلغاء طلبك رقم #{$order_id} بنجاح. 🙏\n"
               . "نأسف لذلك ونتمنى خدمتك مرة أخرى.";
        $this->api->send_text( $from, $reply, $order_id );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function find_pending_order_by_phone( string $phone ): ?WC_Order {
        $formatted = $this->api->format_phone( $phone );
        $local     = preg_replace( '/^\+20/', '0', $formatted );
        foreach ( [ $formatted, $local, $phone ] as $try ) {
            $orders = wc_get_orders( [
                'billing_phone' => $try,
                'status'        => [ 'wc-pending', 'wc-on-hold' ],
                'limit'         => 1,
                'orderby'       => 'date',
                'order'         => 'DESC',
            ] );
            if ( ! empty( $orders ) ) return $orders[0];
        }
        return null;
    }

    private function get_phone( WC_Order $order ): ?string {
        $p = $order->get_billing_phone();
        return ! empty( $p ) ? $p : null;
    }

    private function get_tracking( int $order_id ): string {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $b = $order->get_meta( '_bosta_tracking', true );
            if ( ! empty( $b ) ) {
                return (string) $b;
            }
        }
        $t = get_post_meta( $order_id, '_tracking_number', true );
        if ( ! empty( $t ) ) {
            return (string) $t;
        }
        $items = get_post_meta( $order_id, '_wc_shipment_tracking_items', true );
        if ( is_array( $items ) && ! empty( $items ) ) {
            return (string) ( $items[0]['tracking_number'] ?? 'غير متاح' );
        }
        return 'غير متاح';
    }

    private function format_total( WC_Order $order ): string {
        return number_format( (float) $order->get_total(), 2 ) . ' ' . get_woocommerce_currency_symbol();
    }

    private function enabled( string $key ): bool {
        return (bool) get_option( 'wookapso_enable_' . $key, 0 );
    }
}
