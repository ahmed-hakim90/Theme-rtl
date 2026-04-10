<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WooKapso_API {

    /** Kapso legacy base (messages, phone-numbers test, etc.) */
    private $base_url = 'https://api.kapso.ai/meta/whatsapp';

    /** Kapso Meta Proxy v24.0 — templates CRUD (WABA path) */
    private const META_V24_BASE = 'https://api.kapso.ai/meta/whatsapp/v24.0';

    private $api_key;
    private $phone_number_id;
    private $business_account_id;

    public function __construct() {
        $this->api_key               = get_option( 'wookapso_api_key', '' );
        $this->phone_number_id       = get_option( 'wookapso_phone_number_id', '' );
        $this->business_account_id   = trim( (string) get_option( 'wookapso_business_account_id', '' ) );
    }

    /* ─────────────────────────────────────────
       Public Methods
    ───────────────────────────────────────── */

    /**
     * Send a template message (outside 24h window — requires approved Meta template)
     *
     * @param string $to         Raw phone number from WooCommerce billing
     * @param string $template   Template name (must be approved on Meta)
     * @param array  $params     Ordered list of body parameters {{1}}, {{2}}, ...
     * @param int    $order_id   For logging
     */
    public function send_template( $to, $template, $params = [], $order_id = 0 ) {
        if ( ! $this->is_configured() ) {
            WooKapso_Logger::log( $order_id, $to, $template, 'error', 'API Key or Phone Number ID missing' );
            return false;
        }

        $components = [];
        if ( ! empty( $params ) ) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map( fn( $p ) => [ 'type' => 'text', 'text' => (string) $p ], $params ),
            ];
        }

        $body = [
            'phoneNumberId' => $this->phone_number_id,
            'to'            => $this->format_phone( $to ),
            'type'          => 'template',
            'template'      => [
                'name'       => $template,
                'language'   => [
                    'code' => (string) apply_filters( 'wookapso_template_language_code', 'ar', $template, $order_id ),
                ],
                'components' => $components,
            ],
        ];

        $response = $this->request( 'messages', $body );
        $success  = isset( $response['messages'][0]['id'] );
        $status   = $success ? 'sent' : 'error';
        $note     = $success
            ? (string) ( $response['messages'][0]['id'] ?? '' )
            : wp_json_encode( self::explain_send_failure( $response ), JSON_UNESCAPED_UNICODE );

        WooKapso_Logger::log( $order_id, $to, $template, $status, $note );
        return $response;
    }

    /**
     * Send a template message WITH Quick Reply Buttons
     *
     * The WhatsApp template must have a BUTTONS component with QUICK_REPLY type.
     * Each button gets a payload string that we use to identify the action later.
     *
     * @param string $to         Phone number
     * @param string $template   Approved template name (must have buttons component)
     * @param array  $params     Body parameters {{1}}, {{2}}, ...
     * @param array  $buttons    [ ['payload' => 'CONFIRM_55', 'index' => 0], ... ]
     * @param int    $order_id   For logging
     */
    public function send_template_with_buttons( $to, $template, $params = [], $buttons = [], $order_id = 0 ) {
        if ( ! $this->is_configured() ) {
            WooKapso_Logger::log( $order_id, $to, $template, 'error', 'API Key or Phone Number ID missing' );
            return false;
        }

        $components = [];

        // Body parameters
        if ( ! empty( $params ) ) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map( fn( $p ) => [ 'type' => 'text', 'text' => (string) $p ], $params ),
            ];
        }

        // Button payloads — one component per button
        foreach ( $buttons as $btn ) {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'quick_reply',
                'index'      => (string) $btn['index'],
                'parameters' => [
                    [ 'type' => 'payload', 'payload' => $btn['payload'] ],
                ],
            ];
        }

        $body = [
            'phoneNumberId' => $this->phone_number_id,
            'to'            => $this->format_phone( $to ),
            'type'          => 'template',
            'template'      => [
                'name'       => $template,
                'language'   => [
                    'code' => (string) apply_filters( 'wookapso_template_language_code', 'ar', $template, $order_id ),
                ],
                'components' => $components,
            ],
        ];

        $response = $this->request( 'messages', $body );
        $success  = isset( $response['messages'][0]['id'] );
        $status   = $success ? 'sent' : 'error';
        $note     = $success
            ? (string) ( $response['messages'][0]['id'] ?? '' )
            : wp_json_encode( self::explain_send_failure( $response ), JSON_UNESCAPED_UNICODE );

        WooKapso_Logger::log( $order_id, $to, $template . ' [buttons]', $status, $note );
        return $response;
    }

    /**
     * Send a free-text message (only valid inside an open 24h conversation window)
     */
    public function send_text( $to, $message, $order_id = 0 ) {
        if ( ! $this->is_configured() ) return false;

        $body = [
            'phoneNumberId' => $this->phone_number_id,
            'to'            => $this->format_phone( $to ),
            'type'          => 'text',
            'text'          => [ 'body' => $message ],
        ];

        $response = $this->request( 'messages', $body );
        $success  = isset( $response['messages'][0]['id'] );
        $note     = $success
            ? (string) ( $response['messages'][0]['id'] ?? '' )
            : wp_json_encode( self::explain_send_failure( $response ), JSON_UNESCAPED_UNICODE );
        WooKapso_Logger::log( $order_id, $to, 'text_message', $success ? 'sent' : 'error', $note );
        return $response;
    }

    /**
     * اختبار الاتصال — يستخدم مسار Kapso v24.0 (قائمة أرقام الحساب) لأن /phone-numbers القديم أزيل ويعيد 404.
     */
    public function test_connection() {
        if ( ! $this->is_configured() ) {
            return [
                'success' => false,
                'message' => __( 'API Key أو Phone Number ID ناقص.', 'woo-kapso' ),
                'cause'   => __( 'لم يُدخل أحد المطلوبين لإرسال الرسائل.', 'woo-kapso' ),
                'hint'    => __( 'من WooCommerce ← WhatsApp Kapso: عبّئ API Key من Kapso (Project Settings → API Keys) و Phone Number ID من لوحة Kapso ← WhatsApp.', 'woo-kapso' ),
            ];
        }

        if ( $this->business_account_id === '' ) {
            return [
                'success' => false,
                'message' => __( 'WhatsApp Business Account ID (WABA) غير مُدخل.', 'woo-kapso' ),
                'cause'   => __( 'اختبار الاتصال يعتمد الآن على مسار v24.0 الذي يتطلب معرف حساب واتساب للأعمال.', 'woo-kapso' ),
                'hint'    => __( 'أضف الحقل «WhatsApp Business Account ID» في نفس بطاقة الإعدادات (من Meta Business Suite أو Kapso). بدون WABA لن يعمل اختبار الاتصال ولا إدارة القوالب.', 'woo-kapso' ),
            ];
        }

        $url      = self::META_V24_BASE . '/' . rawurlencode( $this->business_account_id ) . '/phone_numbers';
        $response = wp_remote_get(
            $url,
            [
                'headers' => $this->meta_proxy_headers(),
                'timeout' => 12,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'cause'   => __( 'تعذّر الوصول إلى خادم Kapso (شبكة، DNS، أو حظر جدار ناري).', 'woo-kapso' ),
                'hint'    => __( 'تأكد أن الموقع يستطيع إجراء طلبات HTTPS صادرة إلى api.kapso.ai.', 'woo-kapso' ),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        $body = is_array( $body ) ? $body : [];

        if ( $code === 200 ) {
            $data  = $body['data'] ?? [];
            $match = false;
            if ( is_array( $data ) ) {
                foreach ( $data as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    if ( (string) ( $row['id'] ?? '' ) === (string) $this->phone_number_id ) {
                        $match = true;
                        break;
                    }
                }
            }
            $msg = __( 'الاتصال ناجح — مفتاح الـ API صالح ويمكن قراءة أرقام الحساب من Meta.', 'woo-kapso' );
            if ( ! $match && ! empty( $data ) ) {
                $msg .= ' ' . __( 'تنبيه: Phone Number ID المُدخل لا يظهر في قائمة أرقام هذا الـ WABA — راجع القيم في Kapso.', 'woo-kapso' );
            }
            return [ 'success' => true, 'message' => $msg ];
        }

        $detail = self::summarize_http_error( $code, $body, $raw );
        $extra  = self::connection_error_hint( $code, $body );

        return [
            'success' => false,
            'message' => $detail,
            'cause'   => $extra['cause'],
            'hint'    => $extra['hint'],
        ];
    }

    /**
     * Human-readable API error for admin (test connection, debugging).
     *
     * @param int               $code HTTP status.
     * @param array             $body Decoded JSON or empty.
     * @param string            $raw  Raw response body.
     */
    public static function summarize_http_error( int $code, array $body, string $raw ): string {
        $parts = [];
        if ( $code > 0 ) {
            $parts[] = sprintf( /* translators: %d: HTTP status code */ __( 'HTTP %d', 'woo-kapso' ), $code );
        }

        if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
            $parts[] = $body['error'];
        } elseif ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
            $e = $body['error'];
            if ( ! empty( $e['message'] ) ) {
                $parts[] = (string) $e['message'];
            }
            if ( ! empty( $e['error_user_msg'] ) ) {
                $parts[] = (string) $e['error_user_msg'];
            }
            if ( ! empty( $e['type'] ) ) {
                $parts[] = 'type: ' . (string) $e['type'];
            }
        } elseif ( ! empty( $body['message'] ) && is_string( $body['message'] ) ) {
            $parts[] = $body['message'];
        } elseif ( $raw !== '' && strlen( $raw ) < 400 ) {
            $parts[] = trim( wp_strip_all_tags( $raw ) );
        }

        return implode( ' — ', array_unique( array_filter( $parts ) ) );
    }

    /**
     * شرح عربي لأخطاء اختبار الاتصال (WABA / مفتاح / صلاحيات).
     *
     * @return array{cause:string,hint:string}
     */
    public static function connection_error_hint( int $code, array $body ): array {
        $msg = '';
        if ( isset( $body['error']['message'] ) ) {
            $msg = (string) $body['error']['message'];
        }
        $lower = strtolower( $msg );

        switch ( $code ) {
            case 401:
                return [
                    'cause' => __( 'الخادم رفض المصادقة (401).', 'woo-kapso' ),
                    'hint'  => __( 'انسخ مفتاح المشروع من Kapso: Project Settings → API Keys (ليس توكن Meta يدويًا إن كان المشروع يستخدم مفتاح Kapso فقط). تأكد أن المفتاح كامل ولم تنتهِ صلاحيته.', 'woo-kapso' ),
                ];
            case 403:
                return [
                    'cause' => __( 'ممنوع الوصول (403) — المفتاح لا يملك صلاحية لهذا الحساب.', 'woo-kapso' ),
                    'hint'  => __( 'تحقق في Kapso أن المشروع مربوط بنفس حساب WhatsApp Business، وأن الـ WABA صحيح.', 'woo-kapso' ),
                ];
            case 404:
                return [
                    'cause' => __( 'المسار أو المعرف غير موجود (404).', 'woo-kapso' ),
                    'hint'  => __( 'غالبًا WhatsApp Business Account ID (WABA) خاطئ أو ليس لهذا المشروع. انسخه من Meta Business Suite → إعدادات واتساب أو من لوحة Kapso.', 'woo-kapso' ),
                ];
            default:
                if ( str_contains( $lower, 'invalid' ) && str_contains( $lower, 'credential' ) ) {
                    return [
                        'cause' => __( 'بيانات الاعتماد غير صالحة لواتساب.', 'woo-kapso' ),
                        'hint'  => __( 'حدّث API Key من Kapso، وتأكد أن رقم واتساب مفعّل في نفس المشروع.', 'woo-kapso' ),
                    ];
                }
                return [
                    'cause' => __( 'رفض الخادم الطلب.', 'woo-kapso' ),
                    'hint'  => __( 'راجع الرسالة الإنجليزية أعلاه؛ إن استمر الخطأ تواصل مع دعم Kapso مع نسخة الرسالة.', 'woo-kapso' ),
                ];
        }
    }

    /**
     * تفسير فشل إرسال رسالة (نص/قالب) للسجلات وواجهة الاختبار.
     *
     * @param array $response ما ترجعه request() بعد الإرسال.
     * @return array{message:string,cause:string,solution:string,technical:string}
     */
    public static function explain_send_failure( array $response ): array {
        $code = isset( $response['_http_status'] ) ? (int) $response['_http_status'] : 0;
        $raw  = (string) ( $response['_raw_snippet'] ?? '' );

        if ( ! empty( $response['_transport_error'] ) && ! empty( $response['error'] ) && is_string( $response['error'] ) ) {
            return [
                'message'    => $response['error'],
                'cause'      => __( 'لم يكتمل الطلب إلى Kapso (شبكة أو إعدادات الخادم).', 'woo-kapso' ),
                'solution'   => __( 'تحقق من الاتصال بالإنترنت، ومن أن الاستضافة تسمح بطلبات HTTPS الخارجية.', 'woo-kapso' ),
                'technical'  => $response['error'],
            ];
        }

        $clean = $response;
        unset( $clean['_http_status'], $clean['_raw_snippet'], $clean['_transport_error'] );

        $technical = $code > 0
            ? self::summarize_http_error( $code, is_array( $clean ) ? $clean : [], $raw )
            : wp_json_encode( $clean, JSON_UNESCAPED_UNICODE );

        if ( $technical === '[]' || $technical === '{}' ) {
            $technical = __( 'استجابة فارغة من الخادم — غالبًا رفض HTTP بدون جسم JSON.', 'woo-kapso' )
                . ( $code > 0 ? ' (' . sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d', 'woo-kapso' ), $code ) . ')' : '' );
        }

        $meta_msg = '';
        if ( isset( $response['error'] ) && is_array( $response['error'] ) && ! empty( $response['error']['message'] ) ) {
            $meta_msg = (string) $response['error']['message'];
        }

        $cause    = '';
        $solution = '';

        if ( $code === 401 || ( $meta_msg !== '' && str_contains( strtolower( $meta_msg ), 'unauthor' ) ) ) {
            $cause    = __( 'المفتاح أو التوكن غير مقبول لدى Kapso/Meta.', 'woo-kapso' );
            $solution = __( 'أعد لصق API Key من Kapso (Project Settings → API Keys). إن استخدمت توكن Meta يدويًا فتأكد أنه صالح ومرتبط بنفس رقم واتساب.', 'woo-kapso' );
        } elseif ( $code === 404 ) {
            $cause    = __( 'مسار الإرسال غير موجود أو Phone Number ID خاطئ.', 'woo-kapso' );
            $solution = __( 'راجع Phone Number ID في Kapso ← WhatsApp ← Numbers. إن غيّرت واجهة Kapso، قد تحتاج تحديث الإضافة.', 'woo-kapso' );
        } elseif ( $code === 429 ) {
            $cause    = __( 'تجاوز حد المعدل (Rate limit).', 'woo-kapso' );
            $solution = __( 'انتظر دقائق ثم أعد المحاولة؛ قلل الاختبارات المتكررة.', 'woo-kapso' );
        } elseif ( str_contains( strtolower( $meta_msg ), 'template' ) && str_contains( strtolower( $meta_msg ), 'not' ) ) {
            $cause    = __( 'اسم القالب أو لغة القالب لا تطابق ما هو معتمد في Meta.', 'woo-kapso' );
            $solution = __( 'في الإعدادات استخدم نفس اسم القالب الظاهر في Meta (مثل confirm_order). إن كان القالب بلغة Arabic (EGY) جرّب ضبط لغة القالب إلى ar_EG من فلتر wookapso_template_language_code أو من إعدادات القالب.', 'woo-kapso' );
        } elseif ( str_contains( strtolower( $meta_msg ), 'parameter' ) || str_contains( strtolower( $meta_msg ), 'variable' ) ) {
            $cause    = __( 'عدد أو أسماء متغيرات القالب لا تطابق القالب في Meta.', 'woo-kapso' );
            $solution = __( 'إن كان القالب يستخدم {{order_id}} (مسماة) فالبلجن يرسل {{1}} و{{2}} (ترتيبية) — إمّا تعديل القالب في Meta ليتوافق أو تخصيص الإرسال عبر مطور.', 'woo-kapso' );
        } elseif ( str_contains( strtolower( $meta_msg ), '24' ) && str_contains( strtolower( $meta_msg ), 'hour' ) ) {
            $cause    = __( 'نافذة المحادثة 24 ساعة: الرسالة النصية المجانية غير مسموحة.', 'woo-kapso' );
            $solution = __( 'للاختبار خارج نافذة 24 ساعة استخدم قالبًا معتمدًا من القائمة بدل «رسالة نصية بسيطة».', 'woo-kapso' );
        } elseif ( $code >= 500 ) {
            $cause    = __( 'خطأ من جانب Kapso أو Meta.', 'woo-kapso' );
            $solution = __( 'أعد المحاولة لاحقًا؛ إن تكرر الخطأ راجع حالة الخدمة أو الدعم.', 'woo-kapso' );
        } else {
            $cause    = __( 'الإرسال رُفض — راجع الرسالة التقنية أدناه.', 'woo-kapso' );
            $solution = __( 'فعّل وضع WP_DEBUG مؤقتًا أو راجع سجل الرسائل بعد هذا التحديث لعرض تفاصيل الاستجابة.', 'woo-kapso' );
        }

        $line = $meta_msg !== '' ? $meta_msg : $technical;

        return [
            'message'    => $line,
            'cause'      => $cause,
            'solution'   => $solution,
            'technical'  => $technical,
        ];
    }

    /* ─────────────────────────────────────────
       Private Helpers
    ───────────────────────────────────────── */

    private function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->phone_number_id );
    }

    /**
     * Normalise Egyptian / Arab phone numbers to E.164
     * Examples handled:
     *   01012345678   → +201012345678
     *   201012345678  → +201012345678
     *   +201012345678 → +201012345678
     *   00201012345678 → +201012345678
     */
    public function format_phone( $phone ) {
        // Strip everything except digits and leading +
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        $phone = ltrim( $phone, '+' );

        // Remove leading 00
        if ( strpos( $phone, '00' ) === 0 ) {
            $phone = substr( $phone, 2 );
        }

        // Egyptian numbers starting with 0
        if ( strpos( $phone, '0' ) === 0 ) {
            $phone = '20' . substr( $phone, 1 );
        }

        return '+' . $phone;
    }

    /** ترويسات إرسال الرسائل — يُفضّل Kapso استخدام X-API-Key مع Bearer. */
    private function headers() {
        return [
            'Content-Type'  => 'application/json',
            'X-API-Key'     => $this->api_key,
            'Authorization' => 'Bearer ' . $this->api_key,
        ];
    }

    /**
     * Headers for Kapso Meta Proxy v24 (templates); X-API-Key موصى به في الوثائق.
     */
    private function meta_proxy_headers(): array {
        $h = [
            'Content-Type'  => 'application/json',
            'X-API-Key'     => $this->api_key,
            'Authorization' => 'Bearer ' . $this->api_key,
        ];
        return $h;
    }

    /** API key + WhatsApp Business Account ID (required for template list/create/delete on v24). */
    private function templates_ready(): bool {
        return ! empty( $this->api_key ) && $this->business_account_id !== '';
    }

    private function templates_endpoint(): string {
        return self::META_V24_BASE . '/' . rawurlencode( $this->business_account_id ) . '/message_templates';
    }

    private function request( $endpoint, $body ) {
        $response = wp_remote_post( "{$this->base_url}/{$endpoint}", [
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[WooKapso] WP_Error: ' . $response->get_error_message() );
            return [
                'error'            => $response->get_error_message(),
                '_transport_error' => true,
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = [];
        }

        if ( ! isset( $decoded['messages'][0]['id'] ) ) {
            $decoded['_http_status'] = $code;
            if ( $raw !== '' ) {
                $decoded['_raw_snippet'] = strlen( $raw ) <= 2000 ? $raw : substr( $raw, 0, 2000 ) . '…';
            }
        }

        return $decoded;
    }

    // ── Template CRUD (Kapso Meta Proxy v24.0 — WABA path) ─────────────────

    /**
     * جلب كل القوالب من Meta عبر Kapso.
     * يتطلب WhatsApp Business Account ID (ليس Phone Number ID).
     */
    public function get_templates(): array {
        if ( ! $this->templates_ready() ) {
            return [
                'error' => __( 'أضف مفتاح API و WhatsApp Business Account ID في الإعدادات — مسار القوالب القديم (Phone Number ID) لم يعد مدعوماً وقد يعيد 404.', 'woo-kapso' ),
            ];
        }
        $url      = $this->templates_endpoint();
        $response = wp_remote_get( $url, [
            'headers' => $this->meta_proxy_headers(),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        if ( $code >= 400 ) {
            $detail = self::summarize_http_error( $code, is_array( $body ) ? $body : [], $raw );
            return [ 'error' => $detail ];
        }
        return is_array( $body ) ? $body : [];
    }

    /** إنشاء قالب جديد وإرساله لـ Meta للمراجعة */
    public function create_template( array $payload ): array {
        if ( ! $this->templates_ready() ) {
            return [
                'error' => __( 'أضف WhatsApp Business Account ID في تبويب الإعدادات — إنشاء القوالب يستخدم مسار Kapso v24.0.', 'woo-kapso' ),
            ];
        }
        $url      = $this->templates_endpoint();
        $response = wp_remote_post( $url, [
            'headers' => $this->meta_proxy_headers(),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }
        $code    = wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true ) ?? [];
        if ( $code >= 400 ) {
            $detail = self::summarize_http_error( $code, is_array( $decoded ) ? $decoded : [], $raw );
            if ( $code === 404 ) {
                $detail .= ' — ' . __( 'تحقق من صحة Business Account ID (WABA) في Meta Business Settings.', 'woo-kapso' );
            }
            return [ 'error' => $detail, 'raw' => $decoded ];
        }
        return $decoded;
    }

    /**
     * حذف قالب — يُمرَّر hsm_id (معرّف القالب من القائمة) أو الاسم عبر واجهة أخرى.
     */
    public function delete_template( string $template_id ): array {
        if ( ! $this->templates_ready() ) {
            return [
                'error' => __( 'أضف WhatsApp Business Account ID في الإعدادات.', 'woo-kapso' ),
            ];
        }
        $url = add_query_arg( 'hsm_id', $template_id, $this->templates_endpoint() );
        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => $this->meta_proxy_headers(),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        if ( $code >= 400 ) {
            return [ 'error' => self::summarize_http_error( $code, is_array( $body ) ? $body : [], $raw ) ];
        }
        return [ 'deleted' => true ];
    }
}
