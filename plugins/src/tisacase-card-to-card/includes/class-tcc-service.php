<?php
defined( 'ABSPATH' ) || exit;
class TCC_Service {
    private $repo; private $lock; private $storage;
    public function __construct( $repo, $lock, $storage ) { $this->repo = $repo; $this->lock = $lock; $this->storage = $storage; }
    private function order( $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || TCC_GATEWAY !== $order->get_payment_method() ) { TCC_Helpers::failure( 'سفارش کارت‌به‌کارت معتبر نیست.' ); }
        return $order;
    }
    private function editable( $order ) {
        if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) { TCC_Helpers::failure( 'این سفارش دیگر برای ارسال رسید یا تغییر تصمیم باز نیست؛ با پشتیبانی تماس بگیرید.' ); }
    }
    private function after( $order, $event ) {
        $e = $this->repo->event( $event );
        if ( ! $e ) { return; }
        try {
            $label = TCC_Helpers::event_label( $e['action'] );
            $order->add_order_note( 'کارت به کارت تیساکیس — ' . $label . '؛ مسئول: #' . $e['actor'] . '؛ رویداد: #' . $event . ( $e['public_reason'] ? "\nعلت عمومی: " . $e['public_reason'] : '' ) . ( $e['private_note'] ? "\nیادداشت داخلی: " . $e['private_note'] : '' ) );
        } catch ( Throwable $e ) { /* Ledger is authoritative; never replay the financial action for a note failure. */ }
        TCC_Notifications::enqueue( $event );
    }
    public function begin( $id ) {
        $this->lock->acquire( $id );
        try {
            $order = $this->order( $id ); $this->editable( $order );
            if ( ! in_array( $order->get_currency(), array( 'IRR', 'IRT' ), true ) || (float) $order->get_total() <= 0 ) { TCC_Helpers::failure( 'این نسخه فقط مبلغ مثبت با ارز ریال یا تومان را پشتیبانی می‌کند.' ); }
            $this->storage->base();
            $a = $this->repo->attempt( $id );
            if ( ! $a ) {
                $card = TCC_Settings::primary_card(); if ( ! $card ) { TCC_Helpers::failure( 'کارت مقصد فعالی تنظیم نشده است.' ); }
                $now = gmdate( 'Y-m-d H:i:s' );
                $a = $this->repo->create( array_merge( TCC_Access::credentials( $order ), array( 'order_id' => $id, 'revision' => 1, 'status' => 'awaiting', 'amount' => TCC_Helpers::amount( $order ), 'currency' => $order->get_currency(), 'card' => wp_json_encode( $card ), 'latest_id' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + TCC_Settings::get()['hours'] * HOUR_IN_SECONDS ), 'reminded' => 0, 'updated_at' => $now ) ) );
            }
            if ( ! TCC_Helpers::matches( $order, $a ) ) { TCC_Helpers::failure( 'مبلغ سفارش نسبت به مسیر پرداخت تغییر کرده است؛ با فروشگاه تطبیق دهید و به مقصد قبلی واریز تازه نکنید.' ); }
            $order->set_payment_method_title( TCC_TITLE );
            if ( ! $order->has_status( 'on-hold' ) ) { $order->update_status( 'on-hold', 'کارت به کارت تیساکیس: منتظر واریز و ارسال رسید؛ پرداخت هنوز تأیید نشده است.' ); }
            else { $order->save(); }
            return $a;
        } finally { $this->lock->release( $id ); }
    }
    public function submit( $id, $data, $file ) {
        $this->lock->acquire( $id ); $stored = null;
        try {
            $order = $this->order( $id ); $a = $this->repo->attempt( $id );
            if ( ! TCC_Access::can_view( $order, $a, TCC_Helpers::input( $data, 'token' ) ) ) { TCC_Helpers::failure( 'دسترسی پرداخت معتبر نیست یا مهلت لینک تمام شده است.' ); }
            $key = TCC_Helpers::input( $data, 'request_key' );
            if ( ! preg_match( '/^[a-f0-9]{32,64}$/D', $key ) ) { TCC_Helpers::failure( 'شناسه ارسال معتبر نیست؛ صفحه را دوباره باز کنید.' ); }
            if ( $this->repo->retry( $id, $key ) ) { return 'duplicate'; }
            $this->editable( $order );
            if ( ! $a || in_array( $a['status'], array( 'approving', 'approved' ), true ) ) { TCC_Helpers::failure( 'ثبت رسید برای این وضعیت ممکن نیست.' ); }
            if ( ! TCC_Helpers::matches( $order, $a ) ) { TCC_Helpers::failure( 'مبلغ یا ارز سفارش تغییر کرده است؛ قبل از ارسال با فروشگاه تطبیق دهید.' ); }
            $quota_key = 'tcc_upload_' . $id . '_' . gmdate( 'YmdH' );
            $quota = (int) get_transient( $quota_key );
            if ( $quota >= 10 ) { TCC_Helpers::failure( 'تعداد تلاش‌های ارسال زیاد است؛ یک ساعت بعد تلاش کنید.' ); }
            set_transient( $quota_key, $quota + 1, HOUR_IN_SECONDS );
            if ( $this->repo->recent_count( $id ) >= 5 ) { TCC_Helpers::failure( 'سقف پنج ارسال در یک ساعت تکمیل شده است؛ بعداً تلاش کنید.' ); }
            if ( $this->repo->total_count( $id ) >= 20 ) { TCC_Helpers::failure( 'سقف بیست نسخه رسید این سفارش تکمیل شده است؛ با پشتیبانی تماس بگیرید.' ); }
            $settings = TCC_Settings::get();
            $reference = substr( TCC_Helpers::digits( TCC_Helpers::input( $data, 'reference' ) ), 0, 80 );
            $last4 = TCC_Helpers::digits( TCC_Helpers::input( $data, 'last4' ) );
            if ( '' !== $last4 && ! preg_match( '/^[0-9]{4}$/D', $last4 ) ) { TCC_Helpers::failure( 'فقط چهار رقم آخر کارت مبدأ را وارد کنید؛ شماره کامل کارت لازم نیست.' ); }
            if ( 'yes' === $settings['reference_required'] && '' === $reference ) { TCC_Helpers::failure( 'کد پیگیری الزامی است.' ); }
            $has_file = is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
            if ( ! $has_file && ( 'yes' === $settings['receipt_required'] || '' === $reference ) ) { TCC_Helpers::failure( 'تصویر رسید را انتخاب کنید؛ در حالت رسید اختیاری، کد پیگیری لازم است.' ); }
            $stored = $has_file ? $this->storage->receive( $file ) : array( 'file_key' => '', 'mime' => '', 'file_hash' => '', 'file_bytes' => 0 );
            $row = array_merge( $stored, array( 'order_id' => $id, 'request_key' => $key, 'reference' => $reference, 'last4' => $last4, 'customer_note' => TCC_Helpers::clip( TCC_Helpers::input( $data, 'note' ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
            $event = $this->repo->change( $a, array( 'status' => 'under_review' ), 'under_review', '', '', $row );
            $stored = null; $this->after( $order, $event );
            return 'submitted';
        } catch ( Throwable $error ) {
            // A lost COMMIT acknowledgement is ambiguous: retain the private file, never delete possible evidence.
            throw $error;
        } finally { $this->lock->release( $id ); }
    }
    public function decide( $id, $data ) {
        $this->lock->acquire( $id ); $coupon_locks = array();
        try {
            $order = $this->order( $id );
            if ( ! TCC_Helpers::reviewer( $order ) ) { TCC_Helpers::failure( 'مجوز بازبینی این سفارش را ندارید.' ); }
            $a = $this->repo->attempt( $id );
            if ( ! $a || (int) TCC_Helpers::input( $data, 'revision' ) !== (int) $a['revision'] ) { TCC_Helpers::failure( 'رسید یا تصمیم تغییر کرده است؛ صفحه سفارش را دوباره باز کنید.' ); }
            $decision = TCC_Helpers::input( $data, 'decision' );
            $reason = TCC_Helpers::clip( TCC_Helpers::input( $data, 'reason' ) ); $note = TCC_Helpers::clip( TCC_Helpers::input( $data, 'private_note' ) );
            if ( 'renew' === $decision ) { $event = $this->repo->change( $a, TCC_Access::credentials( $order ), 'guest_renewed', '', $note ); $this->after( $order, $event ); return; }
            if ( TCC_Helpers::fingerprint( $order ) !== TCC_Helpers::input( $data, 'fingerprint' ) ) { TCC_Helpers::failure( 'مبلغ/ارز از زمان بازشدن فرم تغییر کرده است؛ سفارش را دوباره باز کنید.' ); }
            if ( 'approve' === $decision && ! TCC_Helpers::matches( $order, $a ) ) { TCC_Helpers::failure( 'مبلغ/ارز با زمان ثبت پرداخت متفاوت است؛ پیش از تأیید تطبیق مالی لازم است.' ); }
            if ( ! (int) $a['latest_id'] ) { TCC_Helpers::failure( 'هنوز رسیدی برای تصمیم‌گیری ثبت نشده است.' ); }
            if ( 'approving' === $a['status'] ) {
                if ( 'approve' !== $decision || ! $order->is_paid() ) { TCC_Helpers::failure( 'تأیید قبلی ناتمام است؛ بدون تطبیق حساب و وضعیت ووکامرس، عملیات را تکرار نکنید. اگر وجه قطعی است مسئول مالی باید ابتدا وضعیت سفارش را تطبیق دهد.' ); }
                $event = $this->repo->change( $a, array( 'status' => 'approved' ), 'approved', '', 'بازیابی ثبت تأیید؛ سفارش از قبل در ووکامرس پرداخت‌شده است. ' . $note );
                $this->after( $order, $event ); return;
            }
            $this->editable( $order );
            if ( 'approved' === $a['status'] ) { TCC_Helpers::failure( 'این پرداخت قبلاً تأیید شده است.' ); }
            if ( 'approve' === $decision ) {
                if ( 'yes' !== TCC_Helpers::input( $data, 'confirmed' ) ) { TCC_Helpers::failure( 'بررسی ورود وجه به حساب مقصد را صریحاً تأیید کنید.' ); }
                $codes = $order->get_coupon_codes(); sort( $codes, SORT_STRING );
                foreach ( $codes as $code ) { $key = 'coupon_' . strtolower( $code ); $this->lock->acquire( $key ); $coupon_locks[] = $key; }
                if ( is_callable( array( 'TCP_Coupon_Phones', 'validate_order' ) ) ) {
                    try { TCP_Coupon_Phones::validate_order( $order ); }
                    catch ( Throwable $e ) { TCC_Helpers::failure( 'تخفیف محدود نیازمند تطبیق است؛ پرداخت تأیید یا سفارش لغو نشد. ' . $e->getMessage() ); }
                }
                $this->repo->change( $a, array( 'status' => 'approving' ), 'approval_intent', '', $note );
                $order = $this->order( $id );
                if ( ! TCC_Helpers::matches( $order, $a ) ) { TCC_Helpers::failure( 'مبلغ هم‌زمان تغییر کرده؛ تأیید متوقف شد و تطبیق لازم است.' ); }
                if ( ! $order->is_paid() ) { $this->editable( $order ); if ( false === $order->payment_complete() ) { TCC_Helpers::failure( 'ووکامرس خطای نهایی‌سازی گزارش کرده است؛ قصد تأیید حفظ شد. وضعیت سفارش و هوک‌های آن را قبل از تکمیل سابقه بررسی کنید.' ); } }
                $fresh = $this->order( $id );
                if ( ! $fresh->is_paid() ) { TCC_Helpers::failure( 'ووکامرس پرداخت را نهایی نکرد؛ وضعیت ناتمام نگه داشته شد تا مسئول مالی بررسی کند.' ); }
                $event = $this->repo->change( $this->repo->attempt( $id ), array( 'status' => 'approved' ), 'approved', '', $note );
            } else {
                $map = array( 'reject' => 'rejected', 'correct' => 'correction', 'reconcile' => 'reconcile' );
                if ( ! isset( $map[$decision] ) ) { TCC_Helpers::failure( 'اقدام نامعتبر است.' ); }
                if ( '' === $reason ) { TCC_Helpers::failure( 'علت قابل‌نمایش برای مشتری الزامی است.' ); }
                $event = $this->repo->change( $a, array( 'status' => $map[$decision] ), $map[$decision], $reason, $note );
            }
            $this->after( $order, $event );
        } finally { foreach ( array_reverse( $coupon_locks ) as $key ) { $this->lock->release( $key ); } $this->lock->release( $id ); }
    }
    public function deadline( $id ) {
        $this->lock->acquire( $id );
        try {
            $a = $this->repo->attempt( $id ); $order = $this->order( $id );
            if ( ! $a || 'awaiting' !== $a['status'] || $a['latest_id'] || $a['reminded'] || strtotime( $a['expires_at'] . ' UTC' ) > time() || ! $order->has_status( 'on-hold' ) || $order->is_paid() ) { return; }
            $event = $this->repo->change( $a, array( 'reminded' => 1 ), 'reminder', 'مهلت ارسال رسید گذشته است؛ اگر واریز کرده‌اید با فروشگاه تماس بگیرید.' );
            $this->after( $order, $event );
            if ( 'yes' === TCC_Settings::get()['auto_cancel'] ) { $order->update_status( 'cancelled', 'مهلت ارسال رسید بدون اعلام واریز تمام شد؛ عدم ارسال، اثبات عدم واریز نیست. واریز دیرهنگام نیازمند تطبیق است.' ); }
        } finally { $this->lock->release( $id ); }
    }
}
