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
            'payment_footnote' => array( 'title' => 'توضیح زیر دکمه پرداخت', 'type' => 'textarea', 'default' => 'پس از پرداخت، این صفحه را ببندید یا به فروشگاه برگردید. ثبت سفارش به‌تنهایی به معنی تأیید پرداخت نیست.' ),
            'payment_url' => array( 'title' => 'لینک پرداخت اسنپ‌پی', 'type' => 'url', 'default' => '', 'description' => 'لینک سوشال اسنپ‌پی که مشتری برای پرداخت وارد آن می‌شود.' ),
            'fee_percent' => array( 'title' => 'درصد کارمزد اسنپ‌پی', 'type' => 'number', 'default' => '12', 'custom_attributes' => array( 'min' => '0', 'max' => '100', 'step' => '0.01' ), 'description' => 'کارمزد روی مبلغ کل سفارش، پس از تخفیف و با احتساب ارسال و مالیات محاسبه می‌شود.' ),
            'fee_label' => array( 'title' => 'عنوان ردیف کارمزد', 'type' => 'text', 'default' => 'کارمزد اسنپ‌پی' ),
            'warning' => array( 'title' => 'هشدار پیش از پرداخت', 'type' => 'textarea', 'default' => 'لطفاً در لینک اسنپ‌پی دقیقاً مبلغ اعلام‌شده را وارد کنید. در صورت پرداخت مبلغی غیر از مبلغ اعلام‌شده، تیساکیس مسئولیتی بابت برگشت مبلغ ندارد.' ),
            'acknowledgement' => array( 'title' => 'متن تأیید هشدار', 'type' => 'text', 'default' => 'هشدار مبلغ را خواندم و متوجه شدم.' ),
            'checkout_button_text' => array( 'title' => 'متن دکمه ثبت سفارش', 'type' => 'text', 'default' => 'ثبت سفارش و ادامه به اسنپ‌پی' ),
            'payment_button_text' => array( 'title' => 'متن دکمه رفتن به درگاه', 'type' => 'text', 'default' => 'رفتن به صفحه پرداخت اسنپ‌پی' ),
            'pending_message' => array( 'title' => 'پیام انتظار بررسی', 'type' => 'textarea', 'default' => 'پس از پرداخت در لینک اسنپ‌پی، سفارش شما برای بررسی دستی در انتظار تأیید خواهد بود. وضعیت سفارش بعد از بررسی فروشگاه به‌روزرسانی می‌شود.' ),
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
        $warning = $this->setting_text( 'warning' );
        $rate = $this->fee_rate();
        if ( $description ) { echo '<div class="tspay-description">' . wp_kses_post( wpautop( $description ) ) . '</div>'; }
        if ( $rate > 0 && $this->setting_text( 'fee_hint' ) ) { echo '<p class="tspay-fee-hint">' . esc_html( str_replace( '%s', $this->format_rate( $rate ), $this->setting_text( 'fee_hint' ) ) ) . '</p>'; }
        if ( $warning ) { echo '<div class="tspay-warning" role="alert"><strong>هشدار پیش از پرداخت</strong><div>' . wp_kses_post( wpautop( $warning ) ) . '</div><label class="tspay-ack"><input type="checkbox" name="tspay_ack" value="1" required> <span>' . esc_html( $this->setting_text( 'acknowledgement' ) ) . '</span></label></div>'; }
    }

    public function validate_fields() {
        if ( $this->setting_text( 'warning' ) && empty( $_POST['tspay_ack'] ) ) {
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
            $order->update_status( 'on-hold', 'در انتظار پرداخت از طریق لینک اسنپ‌پی و بررسی دستی فروشگاه.' );
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
        $button = $this->setting_text( 'payment_button_text' ) ?: 'رفتن به صفحه پرداخت اسنپ‌پی';
        $can_pay = ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) );
        $message = $order->is_paid() ? $this->setting_text( 'approved_message' ) : ( $can_pay ? $this->setting_text( 'pending_message' ) : '' );
        $warning = $can_pay ? $this->setting_text( 'warning' ) : '';
        $button_color = sanitize_hex_color( $this->get_option( 'button_color', '#2874FC' ) ) ?: '#2874FC';
        $text_color = sanitize_hex_color( $this->get_option( 'button_text_color', '#FFFFFF' ) ) ?: '#FFFFFF';
        $style = '--tspay-button:' . $button_color . ';--tspay-button-text:' . $text_color . ';';
        echo '<section class="tspay-panel" dir="rtl">';
        if ( $message ) { echo '<div class="tspay-pending" role="status"><span class="tspay-pending-icon" aria-hidden="true">✓</span><div>' . wp_kses_post( wpautop( $message ) ) . '</div></div>'; }
        echo '<h3>' . esc_html( $this->setting_text( 'amount_heading' ) ?: 'مبلغ قابل پرداخت در اسنپ‌پی' ) . '</h3><dl class="tspay-amounts">';
        echo '<div><dt>' . esc_html( $this->setting_text( 'order_amount_label' ) ?: 'مبلغ سفارش' ) . '</dt><dd>' . wp_kses_post( wc_price( $base, array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>';
        if ( $fee > 0 ) { echo '<div><dt>کارمزد اسنپ‌پی (' . esc_html( $this->format_rate( $rate ) ) . '٪)</dt><dd>' . wp_kses_post( wc_price( $fee, array( 'currency' => $order->get_currency() ) ) ) . '</dd></div>'; }
        echo '<div class="tspay-total"><dt>' . esc_html( $this->setting_text( 'amount_due_label' ) ?: 'مبلغی که باید در لینک وارد کنید' ) . '</dt><dd>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</dd></div></dl>';
        if ( $warning ) { echo '<div class="tspay-warning" role="alert"><strong>هشدار مهم</strong><div>' . wp_kses_post( wpautop( $warning ) ) . '</div></div>'; }
        if ( $can_pay ) {
            echo '<a class="tspay-button" style="' . esc_attr( $style ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $button ) . '<span aria-hidden="true"> ←</span></a>';
            if ( $this->setting_text( 'payment_footnote' ) ) { echo '<p class="tspay-footnote">' . wp_kses_post( wpautop( $this->setting_text( 'payment_footnote' ) ) ) . '</p>'; }
        }
        echo '</section>';
    }

    private function fee_rate() {
        $rate = (float) $this->get_option( 'fee_percent', '12' );
        return max( 0, min( 100, $rate ) );
    }
    private function format_rate( $rate ) { return rtrim( rtrim( number_format_i18n( (float) $rate, 2 ), '0' ), (string) wc_get_price_decimal_separator() ); }
    private function setting_text( $key ) { return trim( (string) $this->get_option( $key, '' ) ); }

    public function admin_options() {
        echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
        echo '<p>این درگاه به درگاه رسمی/API اسنپ‌پی متصل نیست. مشتری از لینک سوشال وارد می‌شود و واردکردن مبلغ و بررسی پرداخت به‌صورت دستی انجام می‌شود. لطفاً پیش از فعال‌سازی، لینک معتبر و متن‌های پرداخت را تنظیم کنید.</p>';
        parent::admin_options();
    }
}
