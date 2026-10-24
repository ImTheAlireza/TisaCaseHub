<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap tcc-settings-wrap">
<main class="tcc-panel tcc-settings" dir="rtl">
    <form id="tcc-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-active-tab="<?php echo esc_attr( $active ); ?>">
        <input type="hidden" name="action" value="tcc_settings"><input type="hidden" name="ui_tab" value="<?php echo esc_attr( $active ); ?>">
        <?php wp_nonce_field( 'tcc_settings' ); ?>
        <header class="tcc-hero">
            <span class="tcc-hero-icon"><?php echo self::icon( 'card' ); ?></span>
            <div><span class="tcc-eyebrow">TISACASE <span aria-hidden="true">/</span> PAYMENTS</span><h1><?php echo esc_html( TCC_TITLE ); ?></h1></div>
            <a class="tcc-btn tcc-hero-link" href="<?php echo esc_url( add_query_arg( 'tcc_method', TCC_GATEWAY, TCC_Helpers::orders_url() ) ); ?>">سفارش‌ها <?php echo self::icon( 'arrow' ); ?></a>
        </header>
        <div class="tcc-settings-toolbar">
            <?php self::check( $s, 'enabled', 'فعال‌سازی درگاه' ); ?>
            <div class="tcc-health" aria-label="وضعیت درگاه">
                <?php foreach ( $status as $item ) { ?><span class="tcc-health-item<?php echo 'error' === $item[0] ? ' is-error' : ''; ?>"><i aria-hidden="true"></i><?php echo esc_html( $item[1] ); ?></span><?php } ?>
            </div>
        </div>
        <?php if ( isset( $_GET['saved'] ) ) { ?><p class="tcc-message tcc-saved" role="status"><?php echo self::icon( 'check' ); ?> تغییرات ذخیره شد.</p><?php } ?>
        <nav class="tcc-tabs" aria-label="تنظیمات کارت به کارت">
            <?php foreach ( $tabs as $key => $tab ) { ?><a id="tcc-nav-<?php echo esc_attr( $key ); ?>" href="#tcc-tab-<?php echo esc_attr( $key ); ?>" data-tcc-tab="<?php echo esc_attr( $key ); ?>"><?php echo self::icon( $tab[0] ); ?><?php echo esc_html( $tab[1] ); ?></a><?php } ?>
        </nav>
        <section id="tcc-tab-cards" class="tcc-tab-panel" data-tcc-panel="cards" aria-labelledby="tcc-nav-cards">
            <div class="tcc-surface">
                <div class="tcc-section-heading"><h2>کارت‌های مقصد <span class="tcc-count" id="tcc-card-count"><?php echo esc_html( max( 1, count( $s['cards'] ) ) ); ?></span></h2><button type="button" id="tcc-add-card" class="tcc-btn"><?php echo self::icon( 'plus' ); ?> افزودن کارت</button></div>
                <div id="tcc-cards">
                    <?php foreach ( $s['cards'] as $i => $card ) { self::card_row( $card, $i ); } ?>
                    <?php if ( ! $s['cards'] ) { self::card_row( array( 'active' => true ), 0 ); } ?>
                </div>
                <template id="tcc-card-template"><?php self::card_row( array( 'active' => true ), '__INDEX__' ); ?></template>
                <label class="tcc-field tcc-default-card"><span>کارت پیش‌فرض</span><select name="settings[default_card]"><option value="">اولین کارت فعال</option>
                    <?php foreach ( $s['cards'] as $card ) { ?><option value="<?php echo esc_attr( $card['id'] ); ?>" <?php selected( $s['default_card'], $card['id'] ); ?>><?php echo esc_html( ( $card['label'] ?: $card['holder'] ) . ' · ' . substr( $card['number'], -4 ) ); ?></option><?php } ?>
                </select></label>
            </div>
        </section>
        <section id="tcc-tab-payment" class="tcc-tab-panel" data-tcc-panel="payment" aria-labelledby="tcc-nav-payment">
            <div class="tcc-settings-grid">
                <div class="tcc-surface"><div class="tcc-section-heading"><h2>فرم رسید</h2></div>
                    <?php self::check( $s, 'receipt_required', 'تصویر رسید الزامی باشد' ); self::check( $s, 'reference_required', 'کد پیگیری الزامی باشد' ); ?>
                    <div class="tcc-fields tcc-three-fields"><?php self::field( $s, 'max_mb', 'حجم تصویر', 'number', 'MB' ); self::field( $s, 'hours', 'مهلت ارسال', 'number', 'ساعت' ); self::field( $s, 'guest_days', 'لینک مهمان', 'number', 'روز' ); ?></div>
                    <div class="tcc-policy"><?php self::check( $s, 'auto_cancel', 'لغو خودکار سفارش', 'فقط بدون رسید یا اعلام واریز' ); ?></div>
                </div>
                <div class="tcc-surface"><div class="tcc-section-heading"><h2>متن‌های مشتری</h2></div>
                    <?php self::field( $s, 'instructions', 'راهنمای واریز', 'textarea' ); self::field( $s, 'review_text', 'زمان بررسی' ); self::field( $s, 'support', 'پشتیبانی', 'textarea' ); ?>
                </div>
            </div>
        </section>
        <section id="tcc-tab-notifications" class="tcc-tab-panel" data-tcc-panel="notifications" aria-labelledby="tcc-nav-notifications">
            <div class="tcc-surface"><div class="tcc-section-heading"><h2>اعلان‌های ایمیلی</h2></div>
                <div class="tcc-settings-grid tcc-notification-grid"><div>
                    <?php self::check( $s, 'notify_admin', 'رسید جدید برای مسئول مالی' ); self::check( $s, 'notify_customer', 'وضعیت پرداخت برای مشتری' ); ?>
                </div><div><?php self::field( $s, 'recipients', 'گیرندگان مالی', 'text', 'با کاما جدا کنید' ); ?></div></div>
            </div>
        </section>
        <section id="tcc-tab-access" class="tcc-tab-panel" data-tcc-panel="access" aria-labelledby="tcc-nav-access">
            <div class="tcc-settings-grid">
                <div class="tcc-surface"><div class="tcc-section-heading"><h2>مسئولان بررسی</h2></div><label class="tcc-field"><span class="tcc-field-label">شناسه کاربران <small>با کاما جدا کنید</small></span><input name="reviewers" dir="ltr" inputmode="text" value="<?php echo esc_attr( implode( ',', $reviewers ) ); ?>"></label></div>
                <div class="tcc-surface"><div class="tcc-section-heading"><h2>پاسخ‌های آماده</h2></div><?php self::field( $s, 'reject_reasons', 'علت رد یا اصلاح', 'textarea', 'هر خط یک مورد' ); ?></div>
            </div>
        </section>
        <footer class="tcc-savebar"><span class="tcc-settings-feedback" role="status" aria-live="polite"></span><button type="submit" class="tcc-btn tcc-primary"><?php echo self::icon( 'check' ); ?> ذخیره تغییرات</button></footer>
    </form>
</main>
</div>
