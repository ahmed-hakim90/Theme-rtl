<?php
/**
 * Placeholders {{name}} for failure notes and Kapso error summaries.
 *
 * @package WooKapso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooKapso_Message_Templates
 */
class WooKapso_Message_Templates {

	/**
	 * Ready-made default (Arabic). Admins can override in settings.
	 */
	public static function default_failure_template(): string {
		return "❌ WooKapso — فشل الإرسال\n"
			. "━━━━━━━━━━━━━━━━━━━━\n"
			. "📌 الحدث: {{event_label}}\n"
			. "🧾 الطلب: #{{order_id}}\n"
			. "👤 العميل: {{customer_name}}\n"
			. "📱 الهاتف: {{phone}}\n"
			. "📄 القالب المستخدم: {{template_name}}\n"
			. "⏰ الوقت: {{date_time}}\n"
			. "\n"
			. "⚠️ التوضيح:\n{{error_detail}}\n"
			. "\n"
			. "{{error_code_line}}";
	}

	/**
	 * Human label per failure context (Arabic for notes).
	 *
	 * @param string $event_key Internal key.
	 */
	public static function event_label( string $event_key ): string {
		$labels = [
			'new_order_confirmation' => __( 'رسالة تأكيد الطلب (واتساب)', 'woo-kapso' ),
			'cron_reminder'          => __( 'تذكير التأكيد المجدول', 'woo-kapso' ),
			'bosta_shipment'         => __( 'إنشاء شحنة Bosta', 'woo-kapso' ),
			'tracking_resend'        => __( 'إعادة إرسال رسالة التتبع', 'woo-kapso' ),
			'generic'                => __( 'عملية WooKapso', 'woo-kapso' ),
		];
		return $labels[ $event_key ] ?? $event_key;
	}

	/**
	 * Replace {{placeholders}} with values. Unknown keys stay unchanged.
	 *
	 * @param string               $text Template string.
	 * @param array<string,string> $vars Keys without braces.
	 */
	public static function replace_placeholders( string $text, array $vars ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u',
			static function ( array $m ) use ( $vars ): string {
				$key = strtolower( $m[1] );
				if ( ! isset( $vars[ $key ] ) ) {
					return $m[0];
				}
				return $vars[ $key ];
			},
			$text
		);
	}

	/**
	 * Build order note text when something fails.
	 *
	 * @param string               $event_key   e.g. new_order_confirmation.
	 * @param WC_Order|null        $order       Order if available.
	 * @param string               $error_detail Human-readable error (may be long).
	 * @param array<string,string> $extra       Extra vars (merged, lowercased keys).
	 */
	public static function build_failure_order_note( string $event_key, ?WC_Order $order, string $error_detail, array $extra = [] ): string {
		$tpl = (string) get_option( 'wookapso_failure_note_template', '' );
		if ( $tpl === '' ) {
			$tpl = self::default_failure_template();
		}

		$error_detail = wp_strip_all_tags( $error_detail );
		$error_detail = str_replace( [ "\r\n", "\r" ], "\n", $error_detail );

		$code = isset( $extra['error_code'] ) ? wp_strip_all_tags( (string) $extra['error_code'] ) : '';
		unset( $extra['error_code'] );

		$vars = [
			'event_label'     => self::event_label( $event_key ),
			'order_id'        => $order ? (string) $order->get_id() : ( $extra['order_id'] ?? '—' ),
			'customer_name'   => $order ? $order->get_formatted_billing_full_name() : ( $extra['customer_name'] ?? '—' ),
			'phone'           => $order ? (string) $order->get_billing_phone() : ( $extra['phone'] ?? '—' ),
			'template_name'   => $extra['template_name'] ?? '—',
			'error_detail'    => $error_detail !== '' ? $error_detail : __( 'لم يُرجع الخادم تفاصيل إضافية.', 'woo-kapso' ),
			'date_time'       => wp_date( 'Y-m-d H:i:s' ),
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'error_code_line' => $code !== ''
				? __( 'رمز/نوع الخطأ:', 'woo-kapso' ) . ' ' . $code
				: '',
		];

		foreach ( $extra as $k => $v ) {
			$vars[ strtolower( (string) $k ) ] = wp_strip_all_tags( (string) $v );
		}

		/**
		 * Add or override {{placeholder}} values for failure order notes.
		 *
		 * @param array<string,string> $vars         Lowercase keys.
		 * @param string               $event_key    Event key.
		 * @param WC_Order|null        $order        Order.
		 * @param string               $error_detail Sanitized error text.
		 * @param array<string,string> $extra        Original extra args.
		 */
		$filtered = apply_filters( 'wookapso_failure_note_vars', $vars, $event_key, $order, $error_detail, $extra );
		if ( is_array( $filtered ) ) {
			$vars = $filtered;
		}

		$out = self::replace_placeholders( $tpl, $vars );
		$out = preg_replace( "/\n{3,}/", "\n\n", $out );

		return trim( $out );
	}

	/**
	 * Short summary from Kapso / Meta / network response for logs and notes.
	 *
	 * @param mixed $response Array from API or false.
	 */
	public static function kapso_response_error_summary( $response ): string {
		if ( $response === false || $response === null ) {
			return __( 'لا توجد استجابة من الخادم.', 'woo-kapso' );
		}
		if ( ! is_array( $response ) ) {
			return (string) $response;
		}

		// استجابة إرسال من WooKapso_API::request() مع _http_status أو خطأ نقل.
		if ( class_exists( 'WooKapso_API' ) && (
			isset( $response['_http_status'] )
			|| ! empty( $response['_transport_error'] )
			|| ( isset( $response['error'] ) && empty( $response['messages'] ) )
		) ) {
			$ex = WooKapso_API::explain_send_failure( $response );
			$line = $ex['message'];
			if ( ! empty( $ex['cause'] ) ) {
				$line .= ' — ' . $ex['cause'];
			}
			return $line;
		}

		if ( isset( $response['error'] ) && is_string( $response['error'] ) ) {
			return $response['error'];
		}

		if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
			$e = $response['error'];
			$parts = [];
			if ( ! empty( $e['message'] ) ) {
				$parts[] = (string) $e['message'];
			}
			if ( ! empty( $e['error_user_msg'] ) ) {
				$parts[] = (string) $e['error_user_msg'];
			}
			if ( ! empty( $e['type'] ) ) {
				$parts[] = 'type: ' . (string) $e['type'];
			}
			if ( ! empty( $e['code'] ) ) {
				$parts[] = 'code: ' . (string) $e['code'];
			}
			if ( ! empty( $e['error_subcode'] ) ) {
				$parts[] = 'subcode: ' . (string) $e['error_subcode'];
			}
			if ( $parts ) {
				return implode( ' — ', array_unique( $parts ) );
			}
		}

		if ( isset( $response['message'] ) && is_string( $response['message'] ) ) {
			return $response['message'];
		}

		$enc = wp_json_encode( $response, JSON_UNESCAPED_UNICODE );
		if ( is_string( $enc ) && strlen( $enc ) > 800 ) {
			return substr( $enc, 0, 800 ) . '…';
		}
		return is_string( $enc ) ? $enc : __( 'خطأ غير معروف.', 'woo-kapso' );
	}

	/**
	 * Error code only (if any), for {{error_code_line}} helper.
	 *
	 * @param mixed $response API response array.
	 */
	public static function kapso_response_error_code( $response ): string {
		if ( ! is_array( $response ) ) {
			return '';
		}
		if ( isset( $response['_http_status'] ) ) {
			return 'http_' . (string) (int) $response['_http_status'];
		}
		if ( isset( $response['error'] ) && is_array( $response['error'] ) ) {
			$e = $response['error'];
			if ( ! empty( $e['code'] ) ) {
				return (string) $e['code'];
			}
			if ( ! empty( $e['type'] ) ) {
				return (string) $e['type'];
			}
		}
		return '';
	}
}
