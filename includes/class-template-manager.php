<?php
/**
 * WooKapso_Template_Manager
 * إنشاء وإدارة Templates مباشرة من WordPress → Kapso → Meta
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WooKapso_Template_Manager {

    /**
     * قالب WhatsApp جاهز لرسالة تأكيد الطلب (يتوافق مع ترتيب المتغيرات في الكود).
     * {{1}} = اسم العميل (الاسم الأول) — {{2}} = رقم الطلب — {{3}} = الإجمالي + رمز العملة
     *
     * @return array{name:string,body:string,buttons:array<int,array{text:string}>}
     */
    public static function get_order_confirmation_preset(): array {
        $preset = [
            'name' => 'order_confirmation_buttons_ar',
            'body' => __(
                "أهلاً {{1}}! تم استلام طلبك رقم {{2}} بنجاح.\n\n"
                . "💰 المبلغ الإجمالي: {{3}}\n\n"
                . "يرجى تأكيد الطلب أو إلغاؤه من الأزرار أدناه في أقرب وقت.\n\n"
                . 'شكراً لثقتك بنا.',
                'woo-kapso'
            ),
            'buttons' => [
                [ 'text' => __( '✅ تأكيد الطلب', 'woo-kapso' ) ],
                [ 'text' => __( '❌ إلغاء الطلب', 'woo-kapso' ) ],
            ],
        ];

        /**
         * تعديل القالب الجاهز قبل عرضه في لوحة التحكم أو استعادته بـ JS.
         *
         * @param array $preset Keys: name, body, buttons.
         */
        return apply_filters( 'wookapso_order_confirmation_template_preset', $preset );
    }

    /**
     * استجابة جلب القوالب من الـ API (للـ AJAX مع رسالة خطأ واضحة).
     *
     * @return array{templates?:array<int,array>, error?:string}
     */
    public static function fetch_all_response(): array {
        $api      = new WooKapso_API();
        $response = $api->get_templates();

        if ( ! is_array( $response ) || isset( $response['error'] ) ) {
            $msg = is_string( $response['error'] ?? null ) ? $response['error'] : __( 'استجابة غير صالحة من الخادم.', 'woo-kapso' );
            return [ 'error' => $msg ];
        }

        // Kapso v24.0: { data: [...] } — أو مصفوفة مباشرة في حالات قديمة
        $list = $response['data'] ?? ( isset( $response[0] ) ? $response : [] );
        if ( ! is_array( $list ) ) {
            $list = [];
        }

        $templates = array_map( function ( $tpl ) {
            if ( ! is_array( $tpl ) ) {
                return null;
            }
            return [
                'id'       => $tpl['id']     ?? '',
                'name'     => $tpl['name']   ?? '',
                'status'   => $tpl['status'] ?? 'UNKNOWN',
                'category' => $tpl['category'] ?? '',
                'language' => $tpl['language'] ?? 'ar',
                'body'     => self::extract_body( $tpl ),
                'buttons'  => self::extract_buttons( $tpl ),
            ];
        }, $list );

        return [ 'templates' => array_values( array_filter( $templates ) ) ];
    }

    /** جيب كل التيمبلتات من Kapso */
    public static function fetch_all(): array {
        $r = self::fetch_all_response();
        return $r['templates'] ?? [];
    }

    /** جيب التيمبلتات المعتمدة فقط لقوائم الاختيار */
    public static function approved(): array {
        $all = self::fetch_all();
        return array_filter( $all, fn( $t ) => strtoupper( $t['status'] ) === 'APPROVED' );
    }

    /** أنشئ تيمبلت جديد وابعته لـ Kapso → Meta */
    public static function create( array $data ): array {
        $api = new WooKapso_API();

        $components = [];

        // Body
        $components[] = [
            'type' => 'BODY',
            'text' => sanitize_textarea_field( $data['body'] ),
        ];

        // Buttons (اختياري)
        if ( ! empty( $data['buttons'] ) ) {
            $btns = [];
            foreach ( $data['buttons'] as $btn ) {
                $text = sanitize_text_field( $btn['text'] ?? '' );
                if ( $text === '' ) continue;
                $btns[] = [
                    'type' => 'QUICK_REPLY',
                    'text' => $text,
                ];
            }
            if ( ! empty( $btns ) ) {
                $components[] = [
                    'type'    => 'BUTTONS',
                    'buttons' => $btns,
                ];
            }
        }

        $payload = [
            'name'              => sanitize_key( $data['name'] ),
            'language'          => sanitize_text_field( $data['language'] ?? 'ar' ),
            'category'          => strtoupper( sanitize_text_field( $data['category'] ?? 'UTILITY' ) ),
            'parameter_format'  => 'POSITIONAL',
            'components'        => $components,
        ];

        return $api->create_template( $payload );
    }

    /** حذف تيمبلت */
    public static function delete( string $template_id ): array {
        $api = new WooKapso_API();
        return $api->delete_template( $template_id );
    }

    /** استخرج نص الـ Body من بيانات التيمبلت */
    private static function extract_body( array $tpl ): string {
        foreach ( $tpl['components'] ?? [] as $c ) {
            if ( strtoupper( $c['type'] ) === 'BODY' ) {
                return $c['text'] ?? '';
            }
        }
        return '';
    }

    /** استخرج الأزرار من التيمبلت */
    private static function extract_buttons( array $tpl ): array {
        foreach ( $tpl['components'] ?? [] as $c ) {
            if ( strtoupper( $c['type'] ) === 'BUTTONS' ) {
                return array_map( fn( $b ) => $b['text'] ?? '', $c['buttons'] ?? [] );
            }
        }
        return [];
    }
}
