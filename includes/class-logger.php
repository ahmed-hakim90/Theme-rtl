<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WooKapso_Logger {

    const TABLE = 'wookapso_logs';

    /* ── Create table on activation ── */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            phone       VARCHAR(30)     NOT NULL DEFAULT '',
            template    VARCHAR(100)    NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT '',
            note        TEXT,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ── Write a log entry ── */
    public static function log( $order_id, $phone, $template, $status, $note = '' ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'order_id' => (int) $order_id,
                'phone'    => sanitize_text_field( $phone ),
                'template' => sanitize_text_field( $template ),
                'status'   => sanitize_text_field( $status ),
                'note'     => sanitize_textarea_field( $note ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    /* ── Fetch paginated logs ── */
    public static function get_logs( $per_page = 20, $page = 1 ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $offset = ( $page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
    }

    /* ── Total count ── */
    public static function count() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /* ── Clear all logs ── */
    public static function clear() {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::TABLE );
    }

    /**
     * Dashboard header stats (sent = template status `sent`, failed = `error`).
     *
     * @return array{total:int,sent:int,failed:int}
     */
    public static function stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'sent' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'error' ) );

        return [
            'total'  => $total,
            'sent'   => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * احذف سجلات أقدم من N يوم. إذا كان $days = 0 احذف الكل (مثل clear).
     */
    public static function prune( int $days = 0 ): void {
        if ( $days <= 0 ) {
            self::clear();
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
