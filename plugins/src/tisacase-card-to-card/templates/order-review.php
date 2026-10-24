<?php defined( 'ABSPATH' ) || exit; ?>
<div class="tcc-panel tcc-order-box" dir="rtl" data-tcc-order="<?php echo esc_attr( $order->get_id() ); ?>">
    <div class="tcc-order-heading"><span class="tcc-order-method"><?php echo TCC_Admin::icon( 'card' ); ?><?php echo esc_html( TCC_TITLE ); ?></span><?php echo TCC_Helpers::display_badge( $order, $a ); ?></div>
    <?php if ( ! $matches ) { ?><p class="tcc-message tcc-error">مبلغ یا ارز سفارش تغییر کرده؛ تأیید تا تطبیق مالی مسدود است.</p><?php } ?>
    <?php if ( 'approving' === $a['status'] ) { ?><p class="tcc-message tcc-error">تأیید قبلی ناتمام است؛ ورود وجه و وضعیت سفارش را تطبیق دهید. تأیید سفارش پرداخت‌نشده تکرار نمی‌شود.</p><?php } ?>
    <?php if ( $order->is_paid() && 'approved' !== $a['status'] ) { ?><p class="tcc-message">ووکامرس پرداخت را ثبت کرده؛ فقط سابقه تأیید ناتمام تکمیل می‌شود، نه پرداخت دوباره.</p><?php } ?>
    <div class="tcc-admin-grid">
        <div>
            <dl class="tcc-payment-data">
                <div class="tcc-amount-data"><dt>مبلغ سفارش</dt><dd><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></dd></div>
                <?php if ( ! $matches ) { ?><div><dt>مبلغ ثبت‌شده پرداخت</dt><dd><?php echo wp_kses_post( wc_price( $a['amount'], array( 'currency' => $a['currency'] ) ) ); ?></dd></div><?php } ?>
                <div class="tcc-destination-data"><dt>کارت مقصد</dt><dd><bdi><?php echo esc_html( implode( ' ', str_split( $card['number'], 4 ) ) ); ?></bdi><small><?php echo esc_html( $card['bank'] . ' · ' . $card['holder'] ); ?></small></dd></div>
                <?php if ( $s ) { ?>
                    <div><dt>کد پیگیری</dt><dd><bdi><?php echo esc_html( $s['reference'] ?: '—' ); ?></bdi></dd></div>
                    <div><dt>چهار رقم مبدأ</dt><dd><bdi><?php echo esc_html( $s['last4'] ?: '—' ); ?></bdi></dd></div>
                    <div><dt>زمان ارسال</dt><dd><bdi><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $s['created_at'] . ' UTC' ) ) ); ?></bdi></dd></div>
                <?php } ?>
            </dl>
            <?php if ( $s && $s['customer_note'] ) { ?><div class="tcc-customer-note"><span>توضیح مشتری</span><p><?php echo esc_html( $s['customer_note'] ); ?></p></div><?php } ?>
            <?php if ( $s && $repo->duplicate( $s ) ) { ?><p class="tcc-message tcc-error">فایل یا کد پیگیری در سفارش دیگری هم ثبت شده؛ تطبیق لازم است، نه قضاوت خودکار.</p><?php } ?>
            <?php if ( ! $s ) { ?><p class="tcc-muted">هنوز رسیدی ارسال نشده است.</p><?php } ?>
        </div>
        <div class="tcc-admin-image">
            <?php if ( $s && $s['file_key'] ) { $url = TCC_Access::file_url( $order, $s, $a ); ?>
                <div class="tcc-receipt-toolbar"><span>رسید مشتری</span><div><button type="button" class="tcc-rotate tcc-text-button">چرخش</button><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $url ); ?>">تصویر اصلی ↗</a></div></div>
                <div class="tcc-receipt-stage"><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $url ); ?>"><img src="<?php echo esc_url( $url ); ?>" alt="تصویر رسید سفارش" loading="lazy"></a></div>
            <?php } else { ?><div class="tcc-receipt-empty"><?php echo TCC_Admin::icon( 'receipt' ); ?><span>تصویری ثبت نشده</span></div><?php } ?>
        </div>
    </div>
    <?php if ( $can_decide ) { ?>
        <div class="tcc-decision">
            <div class="tcc-decision-fields"><label class="tcc-field"><span>علت آماده</span><select class="tcc-reason-preset"><option value="">انتخاب علت…</option>
                <?php foreach ( explode( "\n", TCC_Settings::get()['reject_reasons'] ) as $reason ) { if ( trim( $reason ) ) { ?><option><?php echo esc_html( trim( $reason ) ); ?></option><?php } } ?>
            </select></label><label class="tcc-field"><span>پیام مشتری <small>برای رد، اصلاح یا تطبیق</small></span><textarea name="reason" form="<?php echo esc_attr( $form ); ?>" rows="2" maxlength="1000"></textarea></label></div>
            <details class="tcc-private-note"><summary>یادداشت داخلی</summary><label class="tcc-field"><span class="tcc-sr-only">یادداشت داخلی</span><textarea name="private_note" form="<?php echo esc_attr( $form ); ?>" rows="2" maxlength="1000"></textarea></label></details>
            <div class="tcc-decision-footer"><label class="tcc-check"><input type="checkbox" name="confirmed" value="yes" form="<?php echo esc_attr( $form ); ?>"> ورود وجه به حساب مقصد را بررسی کرده‌ام.</label>
                <div class="tcc-actions"><button type="submit" name="decision" value="approve" form="<?php echo esc_attr( $form ); ?>" class="tcc-btn tcc-primary"><?php echo TCC_Admin::icon( 'check' ); ?><?php echo 'approving' === $a['status'] ? 'تکمیل سابقه تأیید' : 'تأیید پرداخت'; ?></button>
                    <?php if ( 'approving' !== $a['status'] ) { foreach ( array( 'reject' => 'رد رسید', 'correct' => 'اصلاح رسید', 'reconcile' => 'تطبیق بیشتر' ) as $value => $label ) { ?><button type="submit" name="decision" value="<?php echo esc_attr( $value ); ?>" form="<?php echo esc_attr( $form ); ?>" class="tcc-btn <?php echo 'reject' === $value ? 'tcc-danger' : 'tcc-secondary'; ?>"><?php echo esc_html( $label ); ?></button><?php } } ?>
                </div>
            </div>
        </div>
    <?php } ?>
    <p class="tcc-feedback" role="status" aria-live="polite"></p>
    <details class="tcc-history"><summary>سوابق رسید و تصمیم‌ها</summary>
        <?php foreach ( $repo->history( $order->get_id(), 'submissions' ) as $old ) { ?>
            <div class="tcc-submission-entry"><span>رسید #<?php echo esc_html( $old['id'] ); ?></span><time dir="ltr"><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $old['created_at'] . ' UTC' ) ) ); ?></time><?php if ( $old['file_key'] ) { ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( TCC_Access::file_url( $order, $old, $a ) ); ?>">مشاهده ↗</a><?php } ?></div>
        <?php } ?>
        <?php foreach ( $repo->history( $order->get_id() ) as $event ) { $actor = $event['actor'] ? get_userdata( $event['actor'] ) : false; ?>
            <article class="tcc-event"><div><strong><?php echo esc_html( TCC_Helpers::event_label( $event['action'] ) ); ?></strong><span><?php echo esc_html( $actor ? $actor->display_name : 'مشتری/سیستم' ); ?></span><time dir="ltr"><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $event['created_at'] . ' UTC' ) ) ); ?></time></div>
                <?php if ( $event['public_reason'] ) { ?><p>پیام مشتری: <?php echo esc_html( $event['public_reason'] ); ?></p><?php } ?>
                <?php if ( $event['private_note'] ) { ?><p>یادداشت داخلی: <?php echo esc_html( $event['private_note'] ); ?></p><?php } ?>
            </article>
        <?php } ?>
    </details>
    <details class="tcc-history"><summary>پیگیری مشتری</summary>
        <div class="tcc-tracking-heading"><span class="tcc-muted">اعتبار: <bdi><?php echo esc_html( wp_date( 'Y/m/d H:i', $a['access_expires'] ) ); ?></bdi></span><button type="submit" form="<?php echo esc_attr( $form ); ?>" name="decision" value="renew" class="tcc-btn">تمدید لینک</button></div>
        <label class="tcc-field"><span>لینک محرمانه صاحب سفارش</span><input type="text" dir="ltr" readonly value="<?php echo esc_attr( TCC_Access::url( $order ) ); ?>"></label><p class="tcc-muted">تمدید، لینک قبلی را باطل می‌کند.</p>
    </details>
    <?php if ( current_user_can( 'manage_options' ) ) { ?><a class="tcc-settings-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tisacase-card-to-card' ) ); ?>">تنظیمات درگاه ↗</a><?php } ?>
</div>
