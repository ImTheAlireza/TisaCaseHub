<?php
defined( 'ABSPATH' ) || exit;
class TCC_Repository {
    public static function table( $part ) { global $wpdb; return $wpdb->prefix . 'tcc_' . $part; }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $a = self::table( 'attempts' ); $s = self::table( 'submissions' ); $e = self::table( 'events' );
        dbDelta( "CREATE TABLE $a (
            order_id bigint(20) unsigned NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            status varchar(24) NOT NULL DEFAULT 'awaiting',
            amount decimal(26,8) NOT NULL,
            currency varchar(12) NOT NULL,
            card longtext NOT NULL,
            latest_id bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            access_seed varchar(64) NOT NULL,
            access_hash varchar(64) NOT NULL,
            access_expires bigint(20) unsigned NOT NULL,
            reminded tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            KEY status_order (status,order_id),
            KEY deadline (status,expires_at)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $s (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            request_key varchar(64) NOT NULL,
            file_key varchar(80) NOT NULL DEFAULT '',
            mime varchar(32) NOT NULL DEFAULT '',
            file_hash varchar(64) NOT NULL DEFAULT '',
            file_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            reference varchar(80) NOT NULL DEFAULT '',
            last4 varchar(4) NOT NULL DEFAULT '',
            customer_note text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY retry (order_id,request_key),
            KEY order_history (order_id,id),
            KEY file_hash (file_hash),
            KEY reference (reference)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $e (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(32) NOT NULL,
            actor bigint(20) unsigned NOT NULL DEFAULT 0,
            public_reason text NOT NULL,
            private_note text NOT NULL,
            created_at datetime NOT NULL,
            notify_admin tinyint(1) NOT NULL DEFAULT 0,
            notify_customer tinyint(1) NOT NULL DEFAULT 0,
            notify_attempts int(10) unsigned NOT NULL DEFAULT 0,
            next_notify bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY order_history (order_id,id),
            KEY notify_pending (notify_admin,notify_customer,id)
        ) ENGINE=InnoDB $charset;" );
        $ready = true;
        foreach ( array( $a, $s, $e ) as $table ) { $ready = $ready && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; }
        if ( $ready ) { update_option( 'tcc_db_version', TCC_VERSION, false ); }
        TCC_Permissions::restore();
        if ( ! wp_next_scheduled( 'tcc_maintenance' ) ) { wp_schedule_event( time() + 300, 'hourly', 'tcc_maintenance' ); }
    }
    public function attempt( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'attempts' ) . ' WHERE order_id=%d', $id ), ARRAY_A ); }
    public function create( $row ) { global $wpdb; if ( false === $wpdb->insert( self::table( 'attempts' ), $row ) ) { TCC_Helpers::failure( 'ثبت مسیر پرداخت ناموفق بود.' ); } return $this->attempt( $row['order_id'] ); }
    public function submission( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'submissions' ) . ' WHERE id=%d', $id ), ARRAY_A ); }
    public function retry( $order, $key ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'submissions' ) . ' WHERE order_id=%d AND request_key=%s', $order, $key ), ARRAY_A ); }
    public function history( $order, $kind = 'events' ) {
        global $wpdb; $kind = 'submissions' === $kind ? 'submissions' : 'events';
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( $kind ) . ' WHERE order_id=%d ORDER BY id DESC LIMIT 30', $order ), ARRAY_A );
    }
    public function event( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'events' ) . ' WHERE id=%d', $id ), ARRAY_A ); }
    public function duplicate( $submission ) {
        global $wpdb;
        if ( ! $submission ) { return false; }
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'submissions' ) . " WHERE order_id<>%d AND ((file_hash<>'' AND file_hash=%s) OR (reference<>'' AND reference=%s)) LIMIT 1", $submission['order_id'], $submission['file_hash'], $submission['reference'] ) );
    }
    public function total_count( $order ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'submissions' ) . ' WHERE order_id=%d', $order ) ); }
    public function recent_count( $order ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table( 'submissions' ) . ' WHERE order_id=%d AND created_at>%s', $order, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); }
    /** State + event (+ optional submission) commit together; WC hooks run outside this transaction. */
    public function change( $a, $changes, $action, $reason = '', $note = '', $submission = null ) {
        global $wpdb;
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { TCC_Helpers::failure( 'تراکنش دیتابیس در دسترس نیست؛ تغییری انجام نشد.' ); }
        try {
            if ( $submission ) {
                if ( false === $wpdb->insert( self::table( 'submissions' ), $submission ) ) { throw new RuntimeException( 'ثبت رسید ناموفق بود.' ); }
                $changes['latest_id'] = $wpdb->insert_id;
            }
            $changes['revision'] = (int) $a['revision'] + 1; $changes['updated_at'] = gmdate( 'Y-m-d H:i:s' );
            if ( 1 !== $wpdb->update( self::table( 'attempts' ), $changes, array( 'order_id' => $a['order_id'], 'revision' => $a['revision'] ) ) ) { throw new RuntimeException( 'وضعیت تغییر کرده است؛ صفحه را دوباره باز کنید.' ); }
            $row = array( 'order_id' => $a['order_id'], 'submission_id' => isset( $changes['latest_id'] ) ? $changes['latest_id'] : $a['latest_id'], 'action' => $action, 'actor' => get_current_user_id(), 'public_reason' => $reason, 'private_note' => $note, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
            if ( false === $wpdb->insert( self::table( 'events' ), $row ) ) { throw new RuntimeException( 'ثبت سابقه مالی ناموفق بود.' ); }
            $event = $wpdb->insert_id;
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'نهایی‌سازی تغییر ناموفق بود؛ وضعیت را دوباره بررسی کنید.' ); }
        } catch ( Throwable $error ) { $wpdb->query( 'ROLLBACK' ); throw $error; }
        return $event;
    }
}
