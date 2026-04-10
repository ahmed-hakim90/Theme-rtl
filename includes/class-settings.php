<?php
/**
 * WooKapso_Settings — Admin UI كامل
 * تبويبات: الإعدادات | إدارة التيمبلتات | السجلات
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WooKapso_Settings {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX
        add_action( 'wp_ajax_wookapso_test_conn',      [ $this, 'ajax_test_conn' ] );
        add_action( 'wp_ajax_wookapso_test_msg',        [ $this, 'ajax_test_msg' ] );
        add_action( 'wp_ajax_wookapso_fetch_templates', [ $this, 'ajax_fetch_templates' ] );
        add_action( 'wp_ajax_wookapso_create_template', [ $this, 'ajax_create_template' ] );
        add_action( 'wp_ajax_wookapso_delete_template', [ $this, 'ajax_delete_template' ] );
        add_action( 'wp_ajax_wookapso_clear_logs',      [ $this, 'ajax_clear_logs' ] );
    }

    // ── Menu ────────────────────────────────────────────────────────────────

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'WooKapso — WhatsApp',
            __( 'WhatsApp Kapso', 'woo-kapso' ),
            WooKapso_Capabilities::menu_capability(),
            'wookapso',
            [ $this, 'render_page' ]
        );
    }

    // ── Settings Fields ──────────────────────────────────────────────────────

    public function register_settings(): void {
        $fields = [
            'wookapso_api_key', 'wookapso_phone_number_id', 'wookapso_business_account_id', 'wookapso_webhook_secret',
            // تفعيل
            'wookapso_enable_new_order', 'wookapso_enable_processing',
            'wookapso_enable_shipped',   'wookapso_enable_cancelled',
            // التيمبلتات المختارة لكل حدث
            'wookapso_tpl_new_order',  'wookapso_tpl_processing',
            'wookapso_tpl_shipped',    'wookapso_tpl_cancelled',
        ];
        foreach ( $fields as $f ) {
            register_setting( 'wookapso_group', $f, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }

        register_setting(
            'wookapso_group',
            'wookapso_failure_note_template',
            [
                'sanitize_callback' => 'sanitize_textarea_field',
                'default'           => '',
            ]
        );
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wookapso' ) === false ) return;

        wp_enqueue_style(
            'wookapso-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cairo:wght@400;600;700;800&display=swap',
            [],
            null
        );
        wp_enqueue_style(
            'wookapso-tokens',
            WOOKAPSO_URL . 'assets/css/wookapso-tokens.css',
            [],
            WOOKAPSO_VERSION
        );
        wp_enqueue_style(
            'wookapso-admin',
            WOOKAPSO_URL . 'assets/admin.css',
            [ 'wookapso-tokens', 'wookapso-fonts' ],
            WOOKAPSO_VERSION
        );
        wp_enqueue_script( 'wookapso-admin', WOOKAPSO_URL . 'assets/admin.js', [ 'jquery' ], WOOKAPSO_VERSION, true );
        wp_localize_script(
            'wookapso-admin',
            'wookapso',
            [
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'nonce'               => wp_create_nonce( 'wookapso_nonce' ),
                'confirmation_preset' => class_exists( 'WooKapso_Template_Manager' )
                    ? WooKapso_Template_Manager::get_order_confirmation_preset()
                    : [],
                'i18n'                => [
                    'delete_tpl_confirm' => __( 'حذف التيمبلت "%s"؟ لا يمكن التراجع.', 'woo-kapso' ),
                    'delete_tpl_fail'    => __( 'تعذّر حذف التيمبلت.', 'woo-kapso' ),
                    'clear_logs_title'   => __( 'مسح كل سجلات الرسائل؟', 'woo-kapso' ),
                    'clear_logs_detail'  => __( 'لن يمكن استرجاع البيانات بعد الحذف.', 'woo-kapso' ),
                    'clear_logs_yes'     => __( 'نعم، احذف الكل', 'woo-kapso' ),
                    'clear_logs_cancel'  => __( 'إلغاء', 'woo-kapso' ),
                    'delete_tpl_cancel'  => __( 'إلغاء', 'woo-kapso' ),
                    'delete_tpl_yes'     => __( 'حذف نهائي', 'woo-kapso' ),
                ],
            ]
        );
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajax_test_conn(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }
        $api    = new WooKapso_API();
        $result = $api->test_connection();
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success(
                [
                    'message' => $result['message'] ?? __( 'Connected.', 'woo-kapso' ),
                ]
            );
        }
        wp_send_json_error(
            [
                'message' => $result['message'] ?? __( 'Connection failed.', 'woo-kapso' ),
                'cause'   => $result['cause'] ?? '',
                'hint'    => $result['hint'] ?? '',
            ]
        );
    }

    public function ajax_test_msg(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }

        $phone    = sanitize_text_field( $_POST['phone'] ?? '' );
        $template = sanitize_text_field( $_POST['template'] ?? '' );

        if ( empty( $phone ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'أدخل رقم الهاتف' ] );
        }

        $api = new WooKapso_API();

        if ( ! empty( $template ) ) {
            // إرسال Template اختبار
            $response = $api->send_template_with_buttons( $phone, $template, [
                'عميل تجريبي', '99999', '199 ج.م',
            ], [
                [ 'index' => 0, 'payload' => 'CONFIRM_ORDER_99999' ],
                [ 'index' => 1, 'payload' => 'CANCEL_ORDER_99999'  ],
            ], 0 );
        } else {
            // رسالة نصية بسيطة
            $response = $api->send_text( $phone,
                '👋 مرحباً! هذه رسالة تجريبية من WooKapso. الإعداد يعمل بشكل صحيح ✅', 0 );
        }

        $ok = isset( $response['messages'][0]['id'] );
        if ( $ok ) {
            wp_send_json_success(
                [
                    'message' => __( '✅ تم الإرسال بنجاح!', 'woo-kapso' ),
                ]
            );
        }

        $expl = WooKapso_API::explain_send_failure( is_array( $response ) ? $response : [] );
        wp_send_json_error(
            [
                'message'    => $expl['message'] ?? __( 'فشل الإرسال.', 'woo-kapso' ),
                'cause'      => $expl['cause'] ?? '',
                'solution'   => $expl['solution'] ?? '',
                'technical'  => $expl['technical'] ?? '',
            ]
        );
    }

    public function ajax_fetch_templates(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }
        $r = WooKapso_Template_Manager::fetch_all_response();
        if ( isset( $r['error'] ) ) {
            wp_send_json_error( [ 'message' => $r['error'] ] );
        }
        wp_send_json_success( $r['templates'] ?? [] );
    }

    public function ajax_create_template(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }

        $name     = sanitize_key( $_POST['name']     ?? '' );
        $body     = sanitize_textarea_field( $_POST['body'] ?? '' );
        $category = sanitize_text_field( $_POST['category'] ?? 'UTILITY' );
        $language = sanitize_text_field( $_POST['language'] ?? 'ar' );

        // الأزرار كـ JSON — لا تستخدم sanitize_text_field (تفسد الأحرف والاقتباسات).
        $buttons_raw = isset( $_POST['buttons'] ) ? wp_unslash( (string) $_POST['buttons'] ) : '[]';
        $buttons     = json_decode( $buttons_raw, true );
        if ( ! is_array( $buttons ) ) {
            $buttons = [];
        }

        if ( empty( $name ) || empty( $body ) ) {
            wp_send_json_error( [ 'message' => 'الاسم والنص مطلوبان' ] );
        }

        $result = WooKapso_Template_Manager::create( compact( 'name', 'body', 'category', 'language', 'buttons' ) );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error(
                [
                    'message' => $result['error'],
                    'detail'  => isset( $result['raw'] ) && is_array( $result['raw'] ) ? wp_json_encode( $result['raw'], JSON_UNESCAPED_UNICODE ) : '',
                ]
            );
        }

        wp_send_json_success( [
            'message'  => __( '✅ تم إرسال التيمبلت لـ Meta للمراجعة!', 'woo-kapso' ),
            'template' => $result,
        ] );
    }

    public function ajax_delete_template(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }

        $id     = sanitize_text_field( $_POST['template_id'] ?? '' );
        $result = WooKapso_Template_Manager::delete( $id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( [ 'message' => '🗑️ تم الحذف' ] );
    }

    public function ajax_clear_logs(): void {
        check_ajax_referer( 'wookapso_nonce', 'nonce' );
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die();
        }
        WooKapso_Logger::prune( 0 ); // احذف الكل
        wp_send_json_success();
    }

    // ── Render Page ──────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! WooKapso_Capabilities::can_manage_plugin() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-kapso' ) );
        }
        $tab = sanitize_key( $_GET['tab'] ?? 'settings' );
        ?>
        <div class="wrap wookapso-wrap wkp-admin-premium">

            <header class="wkp-header" role="banner">
                <div class="wkp-header-inner">
                    <div class="wkp-header-brand">
                        <div class="wkp-logo">
                            <span class="wkp-icon" aria-hidden="true">📱</span>
                            <div>
                                <p class="wkp-eyebrow"><?php esc_html_e( 'WooCommerce', 'woo-kapso' ); ?></p>
                                <h1>WooKapso</h1>
                                <p class="wkp-tagline"><?php esc_html_e( 'WhatsApp via Kapso — settings & logs', 'woo-kapso' ); ?></p>
                            </div>
                        </div>
                        <?php if ( WooKapso_Capabilities::can_manage_plugin() ) : ?>
                        <a class="wkp-header-cta" href="<?php echo esc_url( admin_url( 'admin.php?page=wookapso-dashboard' ) ); ?>">
                            <?php esc_html_e( 'Analytics dashboard', 'woo-kapso' ); ?>
                            <span class="dashicons dashicons-chart-line" aria-hidden="true"></span>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php
                    $stats  = WooKapso_Logger::stats();
                    $orders = class_exists( 'WooKapso_Order_Metrics' ) ? WooKapso_Order_Metrics::get_counts() : null;
                    ?>
                    <div class="wkp-stat-sections">
                        <div class="wkp-stat-section">
                            <p class="wkp-stat-section__label"><?php esc_html_e( 'Message log', 'woo-kapso' ); ?></p>
                            <div class="wkp-stats-grid">
                                <div class="wkp-stat wkp-stat--total">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $stats['total'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Rows', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--sent">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $stats['sent'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Sent', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--failed">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $stats['failed'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Failed', 'woo-kapso' ); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if ( is_array( $orders ) ) : ?>
                        <div class="wkp-stat-section">
                            <p class="wkp-stat-section__label"><?php esc_html_e( 'Orders (cached)', 'woo-kapso' ); ?></p>
                            <div class="wkp-stats-grid wkp-stats-grid--dense">
                                <div class="wkp-stat wkp-stat--total">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['total'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Total', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--sent">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['confirmed'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Processing / On-hold', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--failed">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['cancelled'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Cancelled', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--rate">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['shipped'] ); ?></span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Completed', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--sent">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['confirmation_rate'] ); ?>%</span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Progress rate', 'woo-kapso' ); ?></span>
                                </div>
                                <div class="wkp-stat wkp-stat--failed">
                                    <span class="wkp-stat-num"><?php echo esc_html( (string) $orders['cancellation_rate'] ); ?>%</span>
                                    <span class="wkp-stat-label"><?php esc_html_e( 'Cancellation rate', 'woo-kapso' ); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <nav class="wkp-tabs">
                <?php
                $tabs = [
                    'settings'  => '⚙️ الإعدادات',
                    'templates' => '📝 التيمبلتات',
                    'test'      => '🧪 اختبار',
                    'bosta'     => '📦 Bosta',
                    'logs'      => '📋 السجلات',
                ];
                foreach ( $tabs as $key => $label ) :
                ?>
                <a href="?page=wookapso&tab=<?php echo esc_attr( $key ); ?>"
                   class="wkp-tab <?php echo $tab === $key ? 'active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div class="wkp-content">
                <?php
                match ( $tab ) {
                    'settings'  => $this->tab_settings(),
                    'templates' => $this->tab_templates(),
                    'test'      => $this->tab_test(),
                    'bosta'     => $this->tab_bosta(),
                    'logs'      => $this->tab_logs(),
                    default     => $this->tab_settings(),
                };
                ?>
            </div>

        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TAB: Settings
    // ═══════════════════════════════════════════════════════════════════════

    private function tab_settings(): void {
        // جيب التيمبلتات المعتمدة من الكاش أو AJAX
        $approved = $this->get_cached_approved_templates();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wookapso_group' ); ?>

            <!-- API Credentials -->
            <div class="wkp-card">
                <h2 class="wkp-card-title">🔑 بيانات Kapso API</h2>
                <div class="wkp-field">
                    <label>API Key</label>
                    <input type="password" name="wookapso_api_key"
                        value="<?php echo esc_attr( get_option( 'wookapso_api_key' ) ); ?>"
                        placeholder="kap_live_..." autocomplete="new-password" />
                    <p class="wkp-hint"><a href="https://app.kapso.ai" target="_blank">app.kapso.ai</a> ← Settings ← API Keys</p>
                </div>
                <div class="wkp-field">
                    <label>Phone Number ID</label>
                    <input type="text" name="wookapso_phone_number_id"
                        value="<?php echo esc_attr( get_option( 'wookapso_phone_number_id' ) ); ?>"
                        placeholder="12345678901234" />
                    <p class="wkp-hint"><?php esc_html_e( 'Kapso Dashboard ← WhatsApp ← Numbers — مطلوب لإرسال الرسائل.', 'woo-kapso' ); ?></p>
                </div>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'WhatsApp Business Account ID (WABA)', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_business_account_id" dir="ltr" class="large-text"
                        value="<?php echo esc_attr( get_option( 'wookapso_business_account_id' ) ); ?>"
                        placeholder="123456789012345" />
                    <p class="wkp-hint">
                        <?php esc_html_e( 'مطلوب لإنشاء القوالب وعرضها من لوحة التحكم (مسار Kapso v24.0). من Meta Business Suite أو Kapso — معرّف حساب واتساب للأعمال، وليس Phone Number ID.', 'woo-kapso' ); ?>
                        <a href="https://docs.kapso.ai/api/meta/whatsapp/templates/create-message-template" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'وثائق Kapso', 'woo-kapso' ); ?></a>
                    </p>
                </div>
                <button type="button" id="wkp-test-connection" class="wkp-btn wkp-btn--secondary">🔌 اختبر الاتصال</button>
                <span id="wkp-connection-result"></span>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title">🔗 Webhook (ردود واتساب)</h2>
                <p class="wkp-hint">الصق الرابط في Kapso (WhatsApp → Webhooks). إن وضعت سرًا أدناه، يجب أن يرسل Kapso نفس القيمة في الترويسة <code>X-WooKapso-Secret</code>.</p>
                <div class="wkp-field">
                    <label>Webhook URL</label>
                    <input type="text" readonly class="large-text" style="direction:ltr;font-family:monospace;"
                        value="<?php echo esc_attr( rest_url( 'wookapso/v1/inbound' ) ); ?>"
                        onclick="this.select();" />
                </div>
                <div class="wkp-field">
                    <label>سر Webhook <span style="color:#6B7280;font-weight:400;">(اختياري — موصى به للإنتاج)</span></label>
                    <input type="password" name="wookapso_webhook_secret"
                        value="<?php echo esc_attr( get_option( 'wookapso_webhook_secret' ) ); ?>"
                        placeholder="نص عشوائي طويل — نفسه في Kapso إن أمكن" autocomplete="new-password" />
                </div>
            </div>

            <!-- Notifications + Template Selection -->
            <div class="wkp-card">
                <h2 class="wkp-card-title">🔔 الإشعارات والتيمبلتات</h2>

                <?php if ( empty( $approved ) ) : ?>
                <div class="wkp-info-box wkp-info-box--yellow">
                    <strong>⚠️ لا توجد تيمبلتات معتمدة بعد</strong><br>
                    روح <a href="?page=wookapso&tab=templates">تبويب التيمبلتات</a> واعمل تيمبلتات جديدة وانتظر موافقة Meta.
                </div>
                <?php endif; ?>

                <?php
                $events = [
                    'new_order'  => [ 'label' => '🛒 أوردر جديد',    'default' => 'order_confirmation_buttons_ar' ],
                    'processing' => [ 'label' => '⚙️ جاري التجهيز', 'default' => 'order_processing_ar' ],
                    'shipped'    => [ 'label' => '🚚 تم الشحن',       'default' => 'order_shipped_ar' ],
                    'cancelled'  => [ 'label' => '❌ تم الإلغاء',    'default' => 'order_cancelled_ar' ],
                ];
                foreach ( $events as $key => $cfg ) :
                    $enabled_opt = 'wookapso_enable_' . $key;
                    $tpl_opt     = 'wookapso_tpl_' . $key;
                    $selected    = get_option( $tpl_opt, $cfg['default'] );
                ?>
                <div class="wkp-event-row">
                    <div class="wkp-event-left">
                        <label class="wkp-switch">
                            <input type="checkbox" name="<?php echo esc_attr( $enabled_opt ); ?>"
                                value="1" <?php checked( 1, get_option( $enabled_opt ) ); ?> />
                            <span class="wkp-slider"></span>
                        </label>
                        <span class="wkp-event-label"><?php echo esc_html( $cfg['label'] ); ?></span>
                    </div>
                    <div class="wkp-event-right">
                        <select name="<?php echo esc_attr( $tpl_opt ); ?>" class="wkp-tpl-select">
                            <!-- الاختيار اليدوي دائماً متاح -->
                            <option value="<?php echo esc_attr( $cfg['default'] ); ?>"
                                <?php selected( $selected, $cfg['default'] ); ?>>
                                <?php echo esc_html( $cfg['default'] ); ?> (افتراضي)
                            </option>
                            <?php if ( ! empty( $approved ) ) : ?>
                                <?php foreach ( $approved as $tpl ) :
                                    if ( $tpl['name'] === $cfg['default'] ) continue; // فعلاً موجود فوق
                                ?>
                                <option value="<?php echo esc_attr( $tpl['name'] ); ?>"
                                    <?php selected( $selected, $tpl['name'] ); ?>>
                                    ✅ <?php echo esc_html( $tpl['name'] ); ?>
                                    <?php if ( ! empty( $tpl['buttons'] ) ) echo ' [أزرار]'; ?>
                                </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="wkp-btn wkp-btn--xs wkp-refresh-templates" title="تحديث القائمة">🔄</button>
                    </div>
                </div>
                <?php endforeach; ?>

                <p class="wkp-hint" style="margin-top:14px;">
                    💡 اضغط 🔄 لتحديث القائمة بعد ما تنشئ تيمبلت جديد ويتعتمد من Meta
                </p>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title">⚠️ قالب ملاحظة الفشل (على الطلب)</h2>
                <p class="wkp-hint">
                    عند فشل إرسال واتساب أو إنشاء شحنة Bosta، تُسجَّل ملاحظة على الطلب بهذا القالب.
                    استخدم المتغيرات بين <code>{{</code> و <code>}}</code> كما في الجدول أدناه.
                </p>
                <div class="wkp-field">
                    <label for="wookapso_failure_note_template">نص القالب</label>
                    <textarea id="wookapso_failure_note_template" name="wookapso_failure_note_template" class="large-text" rows="14" dir="ltr" style="font-family:ui-monospace,monospace;text-align:left;"><?php echo esc_textarea( get_option( 'wookapso_failure_note_template', '' ) ); ?></textarea>
                    <p class="wkp-hint">اتركه فارغاً لاستخدام <strong>القالب الافتراضي</strong> الجاهز.</p>
                </div>
                <table class="widefat striped" style="max-width:720px;margin-top:12px;">
                    <thead>
                        <tr>
                            <th>المتغير</th>
                            <th>المعنى</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>{{event_label}}</code></td><td>اسم الحدث (عربي)</td></tr>
                        <tr><td><code>{{order_id}}</code></td><td>رقم الطلب</td></tr>
                        <tr><td><code>{{customer_name}}</code></td><td>اسم العميل (الفوترة)</td></tr>
                        <tr><td><code>{{phone}}</code></td><td>هاتف الفوترة</td></tr>
                        <tr><td><code>{{template_name}}</code></td><td>اسم تيمبلت واتساب أو مصدر العملية</td></tr>
                        <tr><td><code>{{error_detail}}</code></td><td>شرح الخطأ من API (الأهم)</td></tr>
                        <tr><td><code>{{error_code_line}}</code></td><td>سطر رمز الخطأ إن وُجد (يُملأ تلقائياً مع <code>error_code</code>)</td></tr>
                        <tr><td><code>{{date_time}}</code></td><td>تاريخ ووقت الموقع</td></tr>
                        <tr><td><code>{{site_name}}</code></td><td>اسم الموقع</td></tr>
                    </tbody>
                </table>
                <p class="wkp-hint" style="margin-top:10px;">يمكنك إضافة متغيرات مخصصة عبر الفلتر <code>wookapso_failure_note_vars</code> من الكود.</p>
            </div>

            <?php submit_button( '💾 حفظ الإعدادات', 'primary wkp-save-btn', 'submit', false ); ?>
        </form>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TAB: Templates Manager
    // ═══════════════════════════════════════════════════════════════════════

    private function tab_templates(): void {
        $confirm_preset = class_exists( 'WooKapso_Template_Manager' )
            ? WooKapso_Template_Manager::get_order_confirmation_preset()
            : [
                'name'    => 'order_confirmation_buttons_ar',
                'body'    => '',
                'buttons' => [],
            ];
        $btn0 = $confirm_preset['buttons'][0]['text'] ?? '';
        $btn1 = $confirm_preset['buttons'][1]['text'] ?? '';
        ?>
        <div class="wkp-templates-layout">

            <!-- ── CREATE FORM ── -->
            <div class="wkp-card wkp-create-form">
                <h2 class="wkp-card-title">➕ إنشاء تيمبلت جديد</h2>
                <p class="wkp-desc">بعد الإنشاء هيتبعت لـ Meta للمراجعة (1-3 أيام)</p>

                <?php if ( trim( (string) get_option( 'wookapso_business_account_id', '' ) ) === '' ) : ?>
                <div class="wkp-info-box wkp-info-box--yellow" style="margin-bottom:18px;">
                    <strong><?php esc_html_e( 'مطلوب: WhatsApp Business Account ID', 'woo-kapso' ); ?></strong><br>
                    <?php esc_html_e( 'أضف حقل WABA في تبويب الإعدادات (فوق). بدونه، مسار إنشاء/عرض القوالب على Kapso يعيد خطأ 404.', 'woo-kapso' ); ?>
                </div>
                <?php endif; ?>

                <div class="wkp-info-box wkp-info-box--blue" style="margin-bottom:18px;">
                    <strong><?php esc_html_e( 'قالب تأكيد جاهز', 'woo-kapso' ); ?></strong><br>
                    <?php esc_html_e( 'الحقول مملوءة بقالب تأكيد طلب كامل (نص + زرّين). إن غيّرتها، اضغط «استعادة القالب الجاهز».', 'woo-kapso' ); ?>
                    <button type="button" id="btn-load-confirmation-preset" class="wkp-btn wkp-btn--secondary wkp-btn--xs" style="margin-top:10px;">
                        <?php esc_html_e( 'استعادة القالب الجاهز للتأكيد', 'woo-kapso' ); ?>
                    </button>
                </div>

                <div class="wkp-field">
                    <label>اسم التيمبلت <span class="wkp-required">*</span></label>
                    <input type="text" id="tpl-name" placeholder="order_confirmation_buttons_ar"
                        style="direction:ltr;"
                        value="<?php echo esc_attr( $confirm_preset['name'] ); ?>" />
                    <p class="wkp-hint">بالحروف الصغيرة والـ underscore فقط — بدون مسافات. نفس الاسم الافتراضي في الإعدادات إن لم تغيّره.</p>
                </div>

                <div class="wkp-field wkp-two-col">
                    <div>
                        <label>الفئة</label>
                        <select id="tpl-category">
                            <option value="UTILITY" selected>Utility (تأكيد/إشعار)</option>
                            <option value="MARKETING">Marketing (تسويق)</option>
                        </select>
                    </div>
                    <div>
                        <label>اللغة</label>
                        <select id="tpl-language">
                            <option value="ar" selected>العربية (ar)</option>
                            <option value="en_US">English (en_US)</option>
                        </select>
                    </div>
                </div>

                <div class="wkp-field">
                    <label>نص الرسالة (Body) <span class="wkp-required">*</span></label>
                    <textarea id="tpl-body" rows="8"
                        placeholder="<?php echo esc_attr( 'أهلاً {{1}}! تم استلام طلبك رقم {{2}} بنجاح. المبلغ: {{3}}.' ); ?>"><?php echo esc_textarea( $confirm_preset['body'] ); ?></textarea>
                    <p class="wkp-hint">
                        <?php esc_html_e( '{{1}} = الاسم الأول للعميل — {{2}} = رقم الطلب — {{3}} = المبلغ + العملة (البلجن يملأهم تلقائياً عند الإرسال).', 'woo-kapso' ); ?>
                    </p>
                </div>

                <!-- Buttons Builder -->
                <div class="wkp-field">
                    <label>أزرار Quick Reply <span style="color:#6B7280;font-weight:400;">(موصى به لتأكيد/إلغاء الطلب)</span></label>
                    <div id="tpl-buttons-list" class="wkp-buttons-list">
                        <div class="wkp-btn-row">
                            <input type="text" placeholder="<?php esc_attr_e( '✅ تأكيد الطلب', 'woo-kapso' ); ?>" class="tpl-btn-text" value="<?php echo esc_attr( $btn0 ); ?>" />
                            <button type="button" class="wkp-btn wkp-btn--danger wkp-btn--xs remove-btn-row">✕</button>
                        </div>
                        <div class="wkp-btn-row">
                            <input type="text" placeholder="<?php esc_attr_e( '❌ إلغاء الطلب', 'woo-kapso' ); ?>" class="tpl-btn-text" value="<?php echo esc_attr( $btn1 ); ?>" />
                            <button type="button" class="wkp-btn wkp-btn--danger wkp-btn--xs remove-btn-row">✕</button>
                        </div>
                    </div>
                    <button type="button" id="add-btn-row" class="wkp-btn wkp-btn--secondary wkp-btn--xs" style="margin-top:8px;">
                        ＋ إضافة زرار
                    </button>
                    <p class="wkp-hint">اتركها فاضية لو مش عايز أزرار</p>
                </div>

                <!-- Preview -->
                <div class="wkp-preview-box" id="tpl-preview" style="display:none;">
                    <div class="wkp-preview-label">👁️ معاينة</div>
                    <div class="wkp-wa-bubble">
                        <div id="preview-body"></div>
                        <div id="preview-buttons" class="wkp-preview-btns"></div>
                        <div class="wa-time">الآن ✓✓</div>
                    </div>
                </div>

                <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;">
                    <button type="button" id="btn-preview-tpl" class="wkp-btn wkp-btn--secondary">👁️ معاينة</button>
                    <button type="button" id="btn-create-tpl" class="wkp-btn wkp-btn--primary">🚀 إنشاء وإرسال لـ Meta</button>
                </div>

                <div id="create-tpl-result" class="wkp-result-box" style="display:none;margin-top:14px;"></div>
            </div>

            <!-- ── TEMPLATES LIST ── -->
            <div class="wkp-card wkp-templates-list-wrap">
                <div class="wkp-card-title-row">
                    <h2 class="wkp-card-title" style="margin:0;">📋 التيمبلتات الموجودة</h2>
                    <button type="button" id="btn-refresh-list" class="wkp-btn wkp-btn--secondary wkp-btn--xs">🔄 تحديث</button>
                </div>

                <div id="wkp-tpl-delete-confirm" class="wkp-result-box error" style="display:none;margin-bottom:14px;" role="region" aria-labelledby="wkp-tpl-delete-msg">
                    <p id="wkp-tpl-delete-msg" class="wkp-tpl-delete-confirm__text"></p>
                    <p class="wkp-tpl-delete-confirm__actions" style="margin:10px 0 0;">
                        <button type="button" id="wkp-tpl-delete-cancel" class="wkp-btn wkp-btn--secondary wkp-btn--xs"></button>
                        <button type="button" id="wkp-tpl-delete-confirm-btn" class="wkp-btn wkp-btn--danger wkp-btn--xs" style="margin-inline-start:8px;"></button>
                    </p>
                </div>

                <div id="tpl-list-loading" class="wkp-loading">⏳ جاري التحميل...</div>
                <div id="tpl-list" class="wkp-tpl-list"></div>
            </div>

        </div>

        <!-- Template Card HTML (injected by JS) -->
        <script type="text/template" id="tpl-card-tmpl">
            <div class="wkp-tpl-card" data-id="{{ID}}">
                <div class="wkp-tpl-card-header">
                    <div>
                        <span class="wkp-tpl-name">{{NAME}}</span>
                        <span class="wkp-badge wkp-status-{{STATUS_CLASS}}">{{STATUS}}</span>
                    </div>
                    <button class="wkp-btn wkp-btn--danger wkp-btn--xs wkp-delete-tpl" data-id="{{ID}}" data-name="{{NAME}}">🗑️</button>
                </div>
                <div class="wkp-tpl-meta">
                    <span>{{CATEGORY}}</span>
                    <span>{{LANGUAGE}}</span>
                    {{BUTTONS_TAG}}
                </div>
                <div class="wkp-tpl-body">{{BODY}}</div>
                <button class="wkp-btn wkp-btn--secondary wkp-btn--xs wkp-use-tpl" data-name="{{NAME}}" style="margin-top:10px;">
                    📋 نسخ الاسم
                </button>
            </div>
        </script>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TAB: Test
    // ═══════════════════════════════════════════════════════════════════════

    private function tab_test(): void {
        $approved = $this->get_cached_approved_templates();
        ?>
        <div class="wkp-card" style="max-width:560px;">
            <h2 class="wkp-card-title">🧪 إرسال رسالة اختبار</h2>
            <p class="wkp-desc">ابعت رسالة تجريبية لأي رقم للتأكد من إن كل حاجة شغّالة</p>

            <div class="wkp-field">
                <label>رقم الموبايل</label>
                <input type="text" id="wkp-test-phone" placeholder="01012345678" />
                <p class="wkp-hint">الأرقام المصرية بتتحول تلقائياً لـ +20</p>
            </div>

            <div class="wkp-field">
                <label>نوع الرسالة</label>
                <select id="wkp-test-type">
                    <option value="">رسالة نصية بسيطة (مش محتاج Template)</option>
                    <?php foreach ( $approved as $tpl ) : ?>
                    <option value="<?php echo esc_attr( $tpl['name'] ); ?>">
                        ✅ Template: <?php echo esc_html( $tpl['name'] ); ?>
                        <?php if ( ! empty( $tpl['buttons'] ) ) echo ' [أزرار]'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="wkp-hint"><?php esc_html_e( 'اختر قالبًا معتمدًا لاختبار الأزرار. الرسالة النصية تعمل فقط داخل نافذة 24 ساعة مع العميل.', 'woo-kapso' ); ?></p>
                <p class="wkp-hint"><?php esc_html_e( 'إن كان القالب في Meta بلغة «Arabic (EGY)» استخدم الفلتر البرمجي wookapso_template_language_code لإرجاع ar_EG بدل ar.', 'woo-kapso' ); ?></p>
            </div>

            <button type="button" id="wkp-send-test" class="wkp-btn wkp-btn--primary">📤 إرسال اختبار</button>
            <div id="wkp-test-result" class="wkp-result-box" style="display:none;margin-top:14px;"></div>
        </div>

        <div class="wkp-card" style="max-width:560px;">
            <h2 class="wkp-card-title">🔗 Webhook URL</h2>
            <p class="wkp-desc">أضفه في Kapso لاستقبال ردود الأزرار من العملاء</p>
            <div class="wkp-webhook-url">
                <code id="wkp-webhook-url"><?php echo esc_url( rest_url( 'wookapso/v1/inbound' ) ); ?></code>
                <button type="button" id="wkp-copy-url" class="wkp-btn wkp-btn--secondary wkp-btn--xs">📋 نسخ</button>
            </div>
            <div class="wkp-info-box wkp-info-box--blue" style="margin-top:14px;">
                <strong>المسار في Kapso:</strong><br>
                WhatsApp → Webhooks → Add → Event: <code>whatsapp.message.received</code>
            </div>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TAB: Logs
    // ═══════════════════════════════════════════════════════════════════════

    private function tab_logs(): void {
        $page  = max( 1, (int) ( $_GET['logpage'] ?? 1 ) );
        $per   = 30;
        $logs  = WooKapso_Logger::get_logs( $per, $page );
        $total = WooKapso_Logger::count();
        $pages = max( 1, (int) ceil( $total / $per ) );
        ?>
        <div class="wkp-card">
            <div class="wkp-logs-header">
                <h2 class="wkp-card-title">📋 سجل الرسائل</h2>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span style="font-size:13px;color:#6B7280;">إجمالي: <strong><?php echo esc_html( $total ); ?></strong></span>
                    <button type="button" id="wkp-clear-logs" class="wkp-btn wkp-btn--danger wkp-btn--xs">🗑️ مسح الكل</button>
                </div>
            </div>

            <div id="wkp-logs-clear-confirm" class="wkp-result-box error" style="display:none;margin-bottom:16px;" role="region">
                <p id="wkp-logs-clear-confirm-title" style="margin:0 0 6px;font-weight:700;"></p>
                <p id="wkp-logs-clear-confirm-detail" style="margin:0 0 12px;font-size:13px;opacity:0.95;"></p>
                <button type="button" id="wkp-logs-clear-yes" class="wkp-btn wkp-btn--danger wkp-btn--xs"></button>
                <button type="button" id="wkp-logs-clear-no" class="wkp-btn wkp-btn--secondary wkp-btn--xs" style="margin-inline-start:8px;"></button>
            </div>

            <?php if ( empty( $logs ) ) : ?>
            <div class="wkp-empty"><span>📭</span><p>لا توجد سجلات بعد.</p></div>
            <?php else : ?>
            <div class="wkp-table-wrap">
                <table class="wkp-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>أوردر</th>
                            <th>هاتف</th>
                            <th>تيمبلت</th>
                            <th>الحالة</th>
                            <th><?php esc_html_e( 'تفاصيل الخطأ / المعرف', 'woo-kapso' ); ?></th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) :
                            $ok  = ( $log['status'] ?? '' ) === 'sent';
                            $oid = (int) ( $log['order_id'] ?? 0 );
                            $o   = $oid ? wc_get_order( $oid ) : null;
                            $o_url = $o ? $o->get_edit_order_url() : ( $oid ? admin_url( 'post.php?post=' . $oid . '&action=edit' ) : '' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $log['id'] ); ?></td>
                            <td>
                                <?php if ( $oid && $o_url ) : ?>
                                <a href="<?php echo esc_url( $o_url ); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html( (string) $oid ); ?></a>
                                <?php else : echo '<em>اختبار</em>'; endif; ?>
                            </td>
                            <td dir="ltr"><?php echo esc_html( $log['phone'] ?? '' ); ?></td>
                            <td><code><?php echo esc_html( mb_strimwidth( $log['template'] ?? '', 0, 40, '…' ) ); ?></code></td>
                            <td>
                                <span class="wkp-badge wkp-badge--<?php echo $ok ? 'sent' : 'failed'; ?>">
                                    <?php echo $ok ? esc_html__( 'Sent', 'woo-kapso' ) : esc_html__( 'Failed', 'woo-kapso' ); ?>
                                </span>
                            </td>
                            <td class="wkp-log-detail-cell"><?php $this->render_log_note_cell( (string) ( $log['note'] ?? '' ), $ok ); ?></td>
                            <td><?php echo esc_html( $log['created_at'] ?? '' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( $pages > 1 ) : ?>
            <div style="margin-top:16px;display:flex;gap:6px;justify-content:center;">
                <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
                <a href="?page=wookapso&tab=logs&logpage=<?php echo esc_attr( $i ); ?>"
                   class="wkp-btn <?php echo $i === $page ? 'wkp-btn--primary' : 'wkp-btn--secondary'; ?> wkp-btn--xs">
                   <?php echo esc_html( $i ); ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TAB: Bosta
    // ═══════════════════════════════════════════════════════════════════════

    private function tab_bosta(): void {
        $enabled = (string) get_option( WooKapso_Bosta::OPTION_ENABLED, '0' );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wookapso_group' ); ?>

            <div class="wkp-card">
                <h2 class="wkp-card-title"><?php esc_html_e( 'Bosta API', 'woo-kapso' ); ?></h2>
                <p class="wkp-hint"><?php esc_html_e( 'Create deliveries when the customer confirms the order on WhatsApp (requires pickup address). Adjust payload via filter wookapso_bosta_delivery_payload if the API rejects the request.', 'woo-kapso' ); ?></p>

                <input type="hidden" name="<?php echo esc_attr( WooKapso_Bosta::OPTION_ENABLED ); ?>" value="0" />
                <div class="wkp-field">
                    <label class="wkp-switch">
                        <input type="checkbox" name="<?php echo esc_attr( WooKapso_Bosta::OPTION_ENABLED ); ?>"
                            value="1" <?php checked( '1', $enabled ); ?> />
                        <span class="wkp-slider"></span>
                    </label>
                    <span class="wkp-event-label"><?php esc_html_e( 'Auto-create Bosta shipment on WhatsApp confirm', 'woo-kapso' ); ?></span>
                </div>

                <div class="wkp-field">
                    <label><?php esc_html_e( 'API key', 'woo-kapso' ); ?></label>
                    <input type="password" name="<?php echo esc_attr( WooKapso_Bosta::OPTION_API_KEY ); ?>"
                        value="<?php echo esc_attr( (string) get_option( WooKapso_Bosta::OPTION_API_KEY, '' ) ); ?>"
                        autocomplete="new-password" class="large-text" style="direction:ltr;" />
                </div>

                <div class="wkp-field">
                    <label><?php esc_html_e( 'Environment', 'woo-kapso' ); ?></label>
                    <select name="<?php echo esc_attr( WooKapso_Bosta::OPTION_ENVIRONMENT ); ?>">
                        <option value="live" <?php selected( 'live', (string) get_option( WooKapso_Bosta::OPTION_ENVIRONMENT, 'live' ) ); ?>><?php esc_html_e( 'Live', 'woo-kapso' ); ?></option>
                        <option value="sandbox" <?php selected( 'sandbox', (string) get_option( WooKapso_Bosta::OPTION_ENVIRONMENT, 'live' ) ); ?>><?php esc_html_e( 'Sandbox', 'woo-kapso' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title"><?php esc_html_e( 'Pickup (sender) address', 'woo-kapso' ); ?></h2>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'First line', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_bosta_pickup_first_line" class="large-text"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_pickup_first_line', '' ) ); ?>" />
                </div>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'Second line', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_bosta_pickup_second_line" class="large-text"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_pickup_second_line', '' ) ); ?>" />
                </div>
                <div class="wkp-field wkp-two-col">
                    <div>
                        <label><?php esc_html_e( 'City', 'woo-kapso' ); ?></label>
                        <input type="text" name="wookapso_bosta_pickup_city"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_pickup_city', '' ) ); ?>" />
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Zone', 'woo-kapso' ); ?></label>
                        <input type="text" name="wookapso_bosta_pickup_zone"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_pickup_zone', '' ) ); ?>" />
                    </div>
                </div>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'Pickup district ID (if required by Bosta)', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_bosta_pickup_district_id" class="large-text" style="direction:ltr;"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_pickup_district_id', '' ) ); ?>" />
                </div>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title"><?php esc_html_e( 'Drop-off defaults', 'woo-kapso' ); ?></h2>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'Drop-off district ID (optional fallback)', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_bosta_dropoff_district_id" class="large-text" style="direction:ltr;"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_dropoff_district_id', '' ) ); ?>" />
                </div>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'Zone fallback if order has no state', 'woo-kapso' ); ?></label>
                    <input type="text" name="wookapso_bosta_dropoff_zone_fallback"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_dropoff_zone_fallback', '' ) ); ?>" />
                </div>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title"><?php esc_html_e( 'Package & tracking link', 'woo-kapso' ); ?></h2>
                <div class="wkp-field wkp-two-col">
                    <div>
                        <label><?php esc_html_e( 'Package type', 'woo-kapso' ); ?></label>
                        <input type="text" name="wookapso_bosta_package_type"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_package_type', 'Parcel' ) ); ?>" />
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Package size', 'woo-kapso' ); ?></label>
                        <input type="text" name="wookapso_bosta_package_size"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_package_size', 'SMALL' ) ); ?>" />
                    </div>
                </div>
                <div class="wkp-field">
                    <label><?php esc_html_e( 'Public tracking URL base (query ?tracking-number= appended)', 'woo-kapso' ); ?></label>
                    <input type="url" name="wookapso_bosta_tracking_url_base" class="large-text" style="direction:ltr;"
                        value="<?php echo esc_attr( (string) get_option( 'wookapso_bosta_tracking_url_base', 'https://business.bosta.co/track-shipment' ) ); ?>" />
                </div>
            </div>

            <div class="wkp-card">
                <h2 class="wkp-card-title"><?php esc_html_e( 'Pending confirmation (WP-Cron)', 'woo-kapso' ); ?></h2>
                <p class="wkp-hint"><?php esc_html_e( 'Runs when the new-order WhatsApp template is sent. Requires WP-Cron (or system cron hitting wp-cron.php).', 'woo-kapso' ); ?></p>
                <div class="wkp-field wkp-two-col">
                    <div>
                        <label><?php esc_html_e( 'Reminder after (hours)', 'woo-kapso' ); ?></label>
                        <input type="number" min="1" name="wookapso_reminder_hours" style="max-width:100px;"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_reminder_hours', '2' ) ); ?>" />
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Auto-cancel after (hours)', 'woo-kapso' ); ?></label>
                        <input type="number" min="2" name="wookapso_auto_cancel_hours" style="max-width:100px;"
                            value="<?php echo esc_attr( (string) get_option( 'wookapso_auto_cancel_hours', '24' ) ); ?>" />
                    </div>
                </div>
                <input type="hidden" name="wookapso_auto_cancel_unconfirmed" value="0" />
                <div class="wkp-field">
                    <label class="wkp-switch">
                        <input type="checkbox" name="wookapso_auto_cancel_unconfirmed" value="1"
                            <?php checked( '1', (string) get_option( 'wookapso_auto_cancel_unconfirmed', '1' ) ); ?> />
                        <span class="wkp-slider"></span>
                    </label>
                    <span class="wkp-event-label"><?php esc_html_e( 'Auto-cancel if still unconfirmed when timer fires', 'woo-kapso' ); ?></span>
                </div>
            </div>

            <?php submit_button( __( 'Save Bosta settings', 'woo-kapso' ), 'primary wkp-save-btn', 'submit', false ); ?>
        </form>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * عرض عمود التفاصيل في سجل الرسائل (نجاح = معرف الرسالة، فشل = سبب + حل).
     */
    private function render_log_note_cell( string $note, bool $sent_ok ): void {
        if ( $sent_ok ) {
            if ( $note !== '' ) {
                echo '<code class="wkp-log-msg-id" dir="ltr">' . esc_html( mb_strimwidth( $note, 0, 48, '…' ) ) . '</code>';
            } else {
                echo '<span class="wkp-muted">—</span>';
            }
            return;
        }
        if ( $note === '' ) {
            echo '<span class="wkp-muted">' . esc_html__( 'لا توجد تفاصيل (سجلات قديمة)', 'woo-kapso' ) . '</span>';
            return;
        }
        $decoded = json_decode( $note, true );
        if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
            echo '<div class="wkp-log-err">';
            echo '<div class="wkp-log-err-summary">' . esc_html( (string) $decoded['message'] ) . '</div>';
            if ( ! empty( $decoded['cause'] ) ) {
                echo '<div class="wkp-log-err-sub"><strong>' . esc_html__( 'السبب:', 'woo-kapso' ) . '</strong> ' . esc_html( (string) $decoded['cause'] ) . '</div>';
            }
            if ( ! empty( $decoded['solution'] ) ) {
                echo '<div class="wkp-log-err-sub"><strong>' . esc_html__( 'الحل المقترح:', 'woo-kapso' ) . '</strong> ' . esc_html( (string) $decoded['solution'] ) . '</div>';
            }
            if ( ! empty( $decoded['technical'] ) && (string) $decoded['technical'] !== (string) $decoded['message'] ) {
                echo '<details class="wkp-log-err-details"><summary>' . esc_html__( 'تفاصيل تقنية', 'woo-kapso' ) . '</summary>';
                echo '<pre class="wkp-log-pre" dir="ltr">' . esc_html( mb_strimwidth( (string) $decoded['technical'], 0, 2000, '…' ) ) . '</pre></details>';
            }
            echo '</div>';
            return;
        }
        echo '<details class="wkp-log-err-details"><summary>' . esc_html__( 'عرض الاستجابة الخام', 'woo-kapso' ) . '</summary>';
        echo '<pre class="wkp-log-pre" dir="ltr">' . esc_html( mb_strimwidth( $note, 0, 2000, '…' ) ) . '</pre></details>';
    }

    /**
     * جيب التيمبلتات المعتمدة مع transient cache (5 دقايق)
     */
    private function get_cached_approved_templates(): array {
        $cache = get_transient( 'wookapso_approved_templates' );
        if ( $cache !== false ) return $cache;

        $templates = WooKapso_Template_Manager::approved();
        set_transient( 'wookapso_approved_templates', $templates, 5 * MINUTE_IN_SECONDS );
        return $templates;
    }
}
