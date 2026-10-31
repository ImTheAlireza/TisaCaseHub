<?php
defined( 'ABSPATH' ) || exit;

class TSPAY_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = TSPAY_GATEWAY;
        $this->method_title       = 'پرداخت اقساطی با اسنپ‌پی';
        $this->method_description = 'پرداخت از طریق لینک اسنپ‌پی با ثبت کارمزد و بررسی دستی سفارش.';
        $this->has_fields         = true;
        $this->supports           = array( 'products' );
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option( 'enabled', 'no' );
        $this->title   = $this->get_option( 'title', 'پرداخت اقساطی با اسنپ‌پی' );
        $this->description = $this->get_option( 'description', 'پرداخت را در صفحه اسنپ‌پی انجام دهید. سفارش پس از بررسی فروشگاه تأیید می‌شود.' );
        $this->order_button_text = $this->get_option( 'checkout_button_text', 'ثبت سفارش و ادامه به اسنپ‌پی' );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details' ) );
        add_action( 'admin_post_tspay_report_paid', array( __CLASS__, 'report_paid' ) );
        add_action( 'admin_post_nopriv_tspay_report_paid', array( __CLASS__, 'report_paid' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array( 'title' => 'فعال‌سازی', 'type' => 'checkbox', 'label' => 'فعال‌کردن پرداخت اقساطی با لینک اسنپ‌پی', 'default' => 'no' ),
            'title' => array( 'title' => 'عنوان روش پرداخت', 'type' => 'text', 'default' => 'پرداخت اقساطی با اسنپ‌پی', 'desc_tip' => true, 'description' => 'عنوان نمایش‌داده‌شده در فهرست روش‌های پرداخت.' ),
            'description' => array( 'title' => 'توضیح روش پرداخت', 'type' => 'textarea', 'default' => 'پرداخت را در صفحه اسنپ‌پی انجام دهید. سفارش پس از بررسی فروشگاه تأیید می‌شود.' ),
            'fee_hint' => array( 'title' => 'متن کارمزد در تسویه‌حساب', 'type' => 'text', 'default' => 'کارمزد اسنپ‌پی (%s٪) به مبلغ کل سفارش افزوده می‌شود.', 'description' => 'برای نمایش درصد، جای‌نگهدار %s را در متن قرار دهید.' ),
            'amount_heading' => array( 'title' => 'عنوان بخش مبلغ پرداخت', 'type' => 'text', 'default' => 'مبلغ قابل پرداخت در اسنپ‌پی' ),
            'order_amount_label' => array( 'title' => 'عنوان مبلغ سفارش', 'type' => 'text', 'default' => 'مبلغ سفارش' ),
            'amount_due_label' => array( 'title' => 'عنوان مبلغ نهایی', 'type' => 'text', 'default' => 'مبلغی که باید در لینک وارد کنید' ),
            'payment_footnote' => array( 'title' => 'توضیح زیر دکمه پرداخت', 'type' => 'textarea', 'default' => 'لینک پرداخت در صفحه‌ای جدا باز می‌شود. مبلغ را با واحد نمایش‌داده‌شده وارد کنید.' ),
            'before_payment_message' => array( 'title' => 'پیام پیش از پرداخت', 'type' => 'textarea', 'default' => 'پس از پرداخت در اسنپ‌پی، کد رهگیری نمایش‌داده‌شده را یادداشت کنید و در همین صفحه وارد کنید. با زدن دکمه ثبت، سفارش برای بررسی دستی فروشگاه ارسال می‌شود.' ),
            'copy_button_text' => array( 'title' => 'متن دکمه کپی مبلغ', 'type' => 'text', 'default' => 'کپی مبلغ نهایی' ),
            'copy_success_message' => array( 'title' => 'پیام پس از کپی مبلغ', 'type' => 'text', 'default' => 'مبلغ کپی شد؛ آن را بدون تغییر در لینک اسنپ‌پی وارد کنید.' ),
            'tracking_field_label' => array( 'title' => 'عنوان فیلد کد رهگیری', 'type' => 'text', 'default' => 'کد رهگیری اسنپ‌پی' ),
            'tracking_placeholder' => array( 'title' => 'راهنمای فیلد کد رهگیری', 'type' => 'text', 'default' => 'کد رهگیری درج‌شده در نتیجه پرداخت' ),
            'tracking_help' => array( 'title' => 'توضیح فیلد کد رهگیری', 'type' => 'text', 'default' => 'کد را دقیقاً از صفحه نتیجه پرداخت اسنپ‌پی وارد کنید.' ),
            'tracking_reminder' => array( 'title' => 'یادآوری کد رهگیری', 'type' => 'textarea', 'default' => 'پس از پرداخت، کد رهگیری را یادداشت کنید؛ برای ارسال سفارش به بررسی دستی، واردکردن این کد لازم است.' ),
            'report_paid_button_text' => array( 'title' => 'متن دکمه ثبت پرداخت', 'type' => 'text', 'default' => 'ثبت پرداخت و ارسال کد برای بررسی' ),
            'report_paid_hint' => array( 'title' => 'توضیح ثبت پرداخت', 'type' => 'textarea', 'default' => 'ثبت این کد فقط درخواست بررسی را ارسال می‌کند؛ پرداخت پس از بررسی فروشگاه تأیید می‌شود.' ),
            'payment_url' => array( 'title' => 'لینک پرداخت اسنپ‌پی', 'type' => 'url', 'default' => '', 'description' => 'لینک سوشال اسنپ‌پی که مشتری برای پرداخت وارد آن می‌شود.' ),
            'fee_percent' => array( 'title' => 'درصد کارمزد اسنپ‌پی', 'type' => 'number', 'default' => '12', 'custom_attributes' => array( 'min' => '0', 'max' => '100', 'step' => '0.01' ), 'description' => 'کارمزد روی مبلغ کل سفارش، پس از تخفیف و با احتساب ارسال و مالیات محاسبه می‌شود.' ),
            'fee_label' => array( 'title' => 'عنوان ردیف کارمزد', 'type' => 'text', 'default' => 'کارمزد اسنپ‌پی' ),
            'warning' => array( 'title' => 'هشدار پیش از پرداخت', 'type' => 'textarea', 'default' => 'در لینک اسنپ‌پی دقیقاً مبلغ نهایی اعلام‌شده را وارد کنید. در صورت پرداخت مبلغی غیر از آن، تیساکیس مسئولیتی بابت برگشت مبلغ ندارد. پس از پرداخت، کد رهگیری اسنپ‌پی را یادداشت کنید؛ برای ثبت درخواست بررسی، واردکردن این کد الزامی است.' ),
            'acknowledgement' => array( 'title' => 'متن تأیید هشدار', 'type' => 'text', 'default' => 'هشدار مبلغ را خواندم و متوجه شدم.' ),
            'checkout_button_text' => array( 'title' => 'متن دکمه ثبت سفارش', 'type' => 'text', 'default' => 'ثبت سفارش و ادامه به اسنپ‌پی' ),
            'payment_button_text' => array( 'title' => 'متن دکمه رفتن به درگاه', 'type' => 'text', 'default' => 'رفتن به صفحه پرداخت اسنپ‌پی' ),
            'legacy_hold_message' => array( 'title' => 'پیام سفارش‌های قدیمی در انتظار بررسی', 'type' => 'textarea', 'default' => 'این سفارش پیش از اصلاح گردش پرداخت ثبت شده و وضعیت پرداخت آن از سایت قابل تشخیص نیست. برای جلوگیری از پرداخت تکراری، قبل از پرداخت دوباره با فروشگاه هماهنگ کنید. اگر قبلاً پرداخت کرده‌اید، دکمهٔ اعلام پرداخت را بزنید.' ),
            'pending_message' => array( 'title' => 'پیام انتظار بررسی', 'type' => 'textarea', 'default' => 'اعلام پرداخت شما ثبت شد و سفارش در انتظار بررسی دستی فروشگاه است. این پیام به معنی تأیید پرداخت نیست.' ),
            'approved_message' => array( 'title' => 'پیام پس از تأیید پرداخت', 'type' => 'textarea', 'default' => 'پرداخت این سفارش تأیید شده است. از خرید شما سپاسگزاریم.' ),
            'button_color' => array( 'title' => 'رنگ دکمه پرداخت', 'type' => 'text', 'default' => '#2874FC', 'description' => 'کد رنگ HEX، مانند #2874FC.' ),
            'button_text_color' => array( 'title' => 'رنگ نوشته دکمه', 'type' => 'text', 'default' => '#FFFFFF', 'description' => 'کد رنگ HEX، مانند #FFFFFF.' ),
        );
    }

    public function is_available() {
        $url = $this->get_option( 'payment_url', '' );
        if ( ! parent::is_available() || '' === trim( (string) $url ) || ! in_array( get_woocommerce_currency(), array( 'IRR', 'IRT' ), true ) ) { return false; }
        return (bool) wp_http_validate_url( $url ) && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    }

    public function payment_fields() {
        $description = $this->setting_text( 'description' );
        $warning = $this->warning_text();
        $rate = $this->fee_rate();
        if ( $description ) { echo '<div class="tspay-description">' . wp_kses_post( wpautop( $description ) ) . '</div>'; }
        if ( $rate > 0 && $this->setting_text( 'fee_hint' ) ) { echo '<p class="tspay-fee-hint">' . esc_html( str_replace( '%s', $this->format_rate( $rate ), $this->setting_text( 'fee_hint' ) ) ) . '</p>'; }
        if ( $warning ) { echo '<div class="tspay-warning" role="alert"><strong>هشدار پیش از پرداخت</strong><div>' . wp_kses_post( wpautop( $warning ) ) . '</div><label class="tspay-ack"><input type="checkbox" name="tspay_ack" value="1" required> <span>' . esc_html( $this->setting_text( 'acknowledgement' ) ) . '</span></label></div>'; }
        if ( $this->setting_text( 'tracking_reminder' ) ) { echo '<div class="tspay-tracking-reminder">' . wp_kses_post( wpautop( $this->setting_text( 'tracking_reminder' ) ) ) . '</div>'; }
    }

    public function validate_fields() {
        if ( $this->warning_text() && empty( $_POST['tspay_ack'] ) ) {
            wc_add_notice( 'برای ادامه، لطفاً هشدار پرداخت اسنپ‌پی را تأیید کنید.', 'error' );
            return false;
        }
        return true;
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { wc_add_notice( 'سفارش پیدا نشد؛ لطفاً دوباره تلاش کنید.', 'error' ); return array( 'result' => 'failure' ); }
        $url = $this->get_option( 'payment_url', '' );
        if ( ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
            wc_add_notice( 'لینک پرداخت اسنپ‌پی هنوز در تنظیمات فروشگاه ثبت نشده است.', 'error' );
            return array( 'result' => 'failure' );
        }
        try {
            $this->apply_fee_to_order( $order );
            $order->update_meta_data( '_tspay_flow_version', TSPAY_VERSION );
            $order->save();
            if ( ! $order->is_paid() && ! $order->has_status( 'pending' ) ) {
                $order->update_status( 'pending', 'در انتظار پرداخت از طریق لینک سوشال اسنپ‌پی.' );
            } else {
                $order->add_order_note( 'در انتظار پرداخت از طریق لینک سوشال اسنپ‌پی. پس از پرداخت، مشتری باید درخواست بررسی را ثبت کند.' );
            }
            if ( WC()->cart ) { WC()->cart->empty_cart(); }
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        } catch ( Throwable $e ) {
            wc_add_notice( 'ثبت کارمزد سفارش انجام نشد؛ لطفاً با پشتیبانی تماس بگیرید.', 'error' );
            return array( 'result' => 'failure' );
        }
    }

    private function apply_fee_to_order( $order ) {
        if ( 'yes' === $order->get_meta( '_tspay_fee_applied', true ) ) { return; }
        $base = (float) $order->get_total();
        $rate = $this->fee_rate();
        $decimals = wc_get_price_decimals();
        $fee = round( $base * $rate / 100, $decimals );
        $order->update_meta_data( '_tspay_base_total', wc_format_decimal( $base, $decimals ) );
        $order->update_meta_data( '_tspay_fee_rate', wc_format_decimal( $rate, 2 ) );
        $order->update_meta_data( '_tspay_fee_amount', wc_format_decimal( $fee, $decimals ) );
        $order->update_meta_data( '_tspay_fee_applied', 'yes' );
        if ( $fee > 0 ) {
            $item = new WC_Order_Item_Fee();
            $label = $this->setting_text( 'fee_label' ) ?: 'کارمزد اسنپ‌پی';
            $item->set_name( $label . ' (' . $this->format_rate( $rate ) . '%)' );
            $item->set_amount( $fee );
            $item->set_total( $fee );
            $item->set_tax_status( 'none' );
            $order->add_item( $item );
            $order->calculate_totals( false );
        }
        $order->save();
    }

    public static function report_paid() {
        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        $order_key = isset( $_POST['order_key'] ) && is_string( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
        $nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        $tracking_code = isset( $_POST['tspay_tracking_code'] ) && is_string( $_POST['tspay_tracking_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tspay_tracking_code'] ) ) ) : '';
        $order = wc_get_order( $order_id );
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) || ! $order || TSPAY_GATEWAY !== $order->get_payment_method() || '' === $order_key || ! hash_equals( (string) $order->get_order_key(), $order_key ) || ! wp_verify_nonce( $nonce, 'tspay_report_paid_' . $order_id . '_' . $order_key ) ) {
            wp_die( 'درخواست معتبر نیست. صفحه سفارش را دوباره باز کنید.', '', array( 'response' => 403 ) );
        }
        if ( '' === $tracking_code || strlen( $tracking_code ) > 480 ) {
            wp_die( 'واردکردن کد رهگیری معتبر الزامی است. به صفحه سفارش برگردید و کد را وارد کنید.', '', array( 'response' => 400 ) );
        }
        if ( ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) ) && 'yes' !== $order->get_meta( '_tspay_customer_reported_paid', true ) ) {
            $note = 'مشتری اعلام کرد پرداخت لینک اسنپ‌پی را انجام داده است. کد رهگیری اعلام‌شده: ' . $tracking_code . '. بررسی دستی مبلغ و دریافت وجه الزامی است.';
            $was_pending = $order->has_status( 'pending' );
            $order->update_meta_data( '_tspay_customer_reported_paid', 'yes' );
            $order->update_meta_data( '_tspay_customer_tracking_code', $tracking_code );
            $order->update_meta_data( '_tspay_customer_reported_at', current_time( 'mysql', true ) );
            $order->save();
            if ( $was_pending ) { $order->update_status( 'on-hold', $note ); }
            else { $order->add_order_note( $note ); }
        }
        nocache_headers();
        wp_safe_redirect( $order->get_checkout_order_received_url() );
        exit;
    }

    public function thankyou_page( $order_id ) { $this->render_payment_panel( $order_id ); }
    public function order_details( $order ) {
        if ( $order && TSPAY_GATEWAY === $order->get_payment_method() && ! is_order_received_page() ) { $this->render_payment_panel( $order->get_id() ); }
    }

    private function render_payment_panel( $order_id ) {
        static $rendered = array();
        if ( isset( $rendered[$order_id] ) ) { return; }
        $rendered[$order_id] = true;
        $order = wc_get_order( $order_id );
        if ( ! $order || TSPAY_GATEWAY !== $order->get_payment_method() ) { return; }
        $url = $this->get_option( 'payment_url', '' );
        if ( ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) { echo '<div class="woocommerce-error">لینک پرداخت اسنپ‌پی در حال حاضر در دسترس نیست؛ لطفاً با پشتیبانی تماس بگیرید.</div>'; return; }
        $base = (float) $order->get_meta( '_tspay_base_total', true );
        $fee = (float) $order->get_meta( '_tspay_fee_amount', true );
        $rate = (float) $order->get_meta( '_tspay_fee_rate', true );
        if ( 'yes' !== $order->get_meta( '_tspay_fee_applied', true ) ) { $base = (float) $order->get_total(); $fee = 0; $rate = 0; }
        $is_paid = $order->is_paid();
        $reported = 'yes' === $order->get_meta( '_tspay_customer_reported_paid', true ) && $order->has_status( 'on-hold' );
        $legacy_hold = ! $is_paid && ! $reported && $order->has_status( 'on-hold' ) && ! $order->get_meta( '_tspay_flow_version', true );
        $can_pay = ! $is_paid && ! $reported && ! $legacy_hold && $order->has_status( 'pending' );
        $can_report = $can_pay || $legacy_hold;
        $message = $is_paid ? $this->setting_text( 'approved_message' ) : ( $reported ? $this->setting_text( 'pending_message' ) : ( $legacy_hold ? $this->setting_text( 'legacy_hold_message' ) : ( $can_pay ? $this->setting_text( 'before_payment_message' ) : '' ) ) );
        $warning = $can_pay ? $this->warning_text() : '';
        $button = $this->setting_text( 'payment_button_text' ) ?: 'رفتن به صفحه پرداخت اسنپ‌پی';
        $copy_button = $this->setting_text( 'copy_button_text' ) ?: 'کپی مبلغ نهایی';
        $copy_success = $this->setting_text( 'copy_success_message' ) ?: 'مبلغ کپی شد؛ آن را بدون تغییر در لینک اسنپ‌پی وارد کنید.';
        $button_color = sanitize_hex_color( $this->get_option( 'button_color', '#2874FC' ) ) ?: '#2874FC';
        $text_color = sanitize_hex_color( $this->get_option( 'button_text_color', '#FFFFFF' ) ) ?: '#FFFFFF';
        $style = '--tspay-button:' . $button_color . ';--tspay-button-text:' . $text_color . ';';
        $copy_amount = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
        $tracking_code = $reported ? (string) $order->get_meta( '_tspay_customer_tracking_code', true ) : '';
        echo '<section class="tspay-panel" dir="rtl">';
        if ( $message ) {
            echo '<div class="tspay-status ' . ( $is_paid ? 'is-approved' : ( $reported ? 'is-review' : 'is-awaiting' ) ) . '" role="status"><span class="tspay-status-icon" aria-hidden="true">' . ( $is_paid ? '✓' : ( $reported ? '◷' : 'i' ) ) . '</span><div>' . wp_kses_post( wpautop( $message ) );
            if ( $reported && $tracking_code ) { echo '<p class="tspay-tracking-receipt">کد رهگیری ثبت‌شده: <bdi dir="ltr">' . esc_html( $tracking_code ) . '</bdi></p>'; }
            echo '</div></div>';
        }
        echo '<h3 class="tspay-heading">' . esc_html( $this->setting_text( 'amount_heading' ) ?: 'مبلغ قابل پرداخت در اسنپ‌پی' ) . '</h3>';
        echo '<dl class="tspay-breakdown">';
        echo '<div><dt>' . esc_html( $this->setting_text( 'order_amount_label' ) ?: 'مبلغ سفارش' ) . '</dt><dd>' . wp_kses_post( wc_price( $base, array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>';
        if ( $fee > 0 ) { echo '<div><dt>کارمزد اسنپ‌پی (' . esc_html( $this->format_rate( $rate ) ) . '٪)</dt><dd>' . wp_kses_post( wc_price( $fee, array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>'; }
        echo '</dl>';
        echo '<div class="tspay-payable">';
        echo '<div class="tspay-payable-label">' . esc_html( $this->setting_text( 'amount_due_label' ) ?: 'مبلغ نهایی قابل پرداخت' ) . '</div>';
        echo '<div class="tspay-payable-value" dir="auto">' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</div>';
        echo '<button type="button" class="tspay-copy-button" data-tspay-copy="' . esc_attr( $copy_amount ) . '" data-copy-success="' . esc_attr( $copy_success ) . '"><svg class="tspay-copy-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3" stroke="currentColor" stroke-width="1.8"/></svg><span data-copy-label>' . esc_html( $copy_button ) . '</span></button>';
        echo '<p class="tspay-copy-feedback" role="status" aria-live="polite">مبلغ را با همین واحد در لینک وارد کنید.</p></div>';
        if ( $warning ) { echo '<div class="tspay-warning" role="alert"><strong>هشدار مهم</strong><div>' . wp_kses_post( wpautop( $warning ) ) . '</div></div>'; }
        if ( $can_pay ) {
            echo '<a class="tspay-button" style="' . esc_attr( $style ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $button ) . '<span aria-hidden="true"> ←</span></a>';
            if ( $this->setting_text( 'payment_footnote' ) ) { echo '<p class="tspay-footnote">' . wp_kses_post( wpautop( $this->setting_text( 'payment_footnote' ) ) ) . '</p>'; }
        }
        if ( $can_report ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tspay-report-form">';
            echo '<input type="hidden" name="action" value="tspay_report_paid"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="order_key" value="' . esc_attr( $order->get_order_key() ) . '">';
            wp_nonce_field( 'tspay_report_paid_' . $order->get_id() . '_' . $order->get_order_key() );
            echo '<label class="tspay-tracking-field"><span class="tspay-tracking-label">' . esc_html( $this->setting_text( 'tracking_field_label' ) ?: 'کد رهگیری اسنپ‌پی' ) . ' <em>الزامی</em></span><input type="text" name="tspay_tracking_code" maxlength="120" required autocomplete="off" dir="ltr" placeholder="' . esc_attr( $this->setting_text( 'tracking_placeholder' ) ?: 'کد رهگیری درج‌شده در نتیجه پرداخت' ) . '"><small>' . esc_html( $this->setting_text( 'tracking_help' ) ?: 'کد را دقیقاً از صفحه نتیجه پرداخت اسنپ‌پی وارد کنید.' ) . '</small></label>';
            echo '<button type="submit" class="tspay-report-button"><span class="tspay-report-check" aria-hidden="true">✓</span>' . esc_html( $this->report_button_text() ) . '</button>';
            if ( $this->report_hint_text() ) { echo '<p>' . wp_kses_post( wpautop( $this->report_hint_text() ) ) . '</p>'; }
            echo '</form>';
        }
        echo '</section>';

    }

    private function fee_rate() {
        $rate = (float) $this->get_option( 'fee_percent', '12' );
        return max( 0, min( 100, $rate ) );
    }
    private function format_rate( $rate ) { return rtrim( rtrim( number_format_i18n( (float) $rate, 2 ), '0' ), (string) wc_get_price_decimal_separator() ); }
    private function setting_text( $key ) { return trim( (string) $this->get_option( $key, '' ) ); }
    private function warning_text() {
        $warning = $this->setting_text( 'warning' );
        $old_default = 'لطفاً در لینک اسنپ‌پی دقیقاً مبلغ اعلام‌شده را وارد کنید. در صورت پرداخت مبلغی غیر از مبلغ اعلام‌شده، تیساکیس مسئولیتی بابت برگشت مبلغ ندارد.';
        if ( $old_default === $warning ) {
            return 'در لینک اسنپ‌پی دقیقاً مبلغ نهایی اعلام‌شده را وارد کنید. در صورت پرداخت مبلغی غیر از آن، تیساکیس مسئولیتی بابت برگشت مبلغ ندارد. پس از پرداخت، کد رهگیری اسنپ‌پی را یادداشت کنید؛ برای ثبت درخواست بررسی، واردکردن این کد الزامی است.';
        }
        return $warning;
    }
    private function report_button_text() {
        $text = $this->setting_text( 'report_paid_button_text' );
        if ( 'پرداخت را انجام دادم؛ ارسال برای بررسی' === $text ) { return 'ثبت پرداخت و ارسال کد رهگیری برای بررسی'; }
        return $text ?: 'ثبت پرداخت و ارسال کد رهگیری برای بررسی';
    }
    private function report_hint_text() {
        $text = $this->setting_text( 'report_paid_hint' );
        if ( 'این دکمه فقط به فروشگاه خبر می‌دهد؛ پرداخت را خودکار تأیید نمی‌کند.' === $text ) { return 'کد رهگیری برای فروشگاه ثبت می‌شود؛ پرداخت فقط پس از بررسی فروشگاه تأیید خواهد شد.'; }
        return $text;
    }

    public function admin_options() {
        echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
        echo '<p>این درگاه به درگاه رسمی/API اسنپ‌پی متصل نیست. مشتری از لینک سوشال وارد می‌شود و واردکردن مبلغ و بررسی پرداخت به‌صورت دستی انجام می‌شود. لطفاً پیش از فعال‌سازی، لینک معتبر و متن‌های پرداخت را تنظیم کنید.</p>';
        parent::admin_options();
    }
}
