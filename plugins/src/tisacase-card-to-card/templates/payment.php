<?php defined( 'ABSPATH' ) || exit; ?>
<section class="tcc-panel tcc-payment" dir="rtl" aria-label="پرداخت کارت به کارت">
    <header class="tcc-heading"><span class="tcc-eyebrow">کارت به کارت تیساکیس</span><h2>پرداخت سفارش <?php echo esc_html( $order->get_order_number() ); ?></h2><?php echo TCC_Helpers::display_badge( $order, $a ); ?></header>
    <ol class="tcc-steps"><li>۱. واریز وجه</li><li>۲. ارسال رسید</li><li>۳. بررسی فروشگاه</li></ol>
    <?php if ( $flash ) { ?><p role="status" class="tcc-message <?php echo $flash['error'] ? 'tcc-error' : ''; ?>"><?php echo esc_html( $flash['message'] ); ?></p><?php } ?>
    <?php if ( ! TCC_Helpers::matches( $order, $a ) ) { ?><p class="tcc-message tcc-error">مبلغ یا ارز سفارش تغییر کرده است. قبل از واریز یا ارسال رسید با فروشگاه تطبیق دهید.</p><?php } ?>
    <?php if ( $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) ) ) { ?>
        <p class="tcc-message tcc-error">این سفارش برای واریز جدید باز نیست. اگر قبلاً واریز کرده‌اید با پشتیبانی تماس بگیرید؛ دوباره واریز نکنید.</p>
    <?php } elseif ( $order->is_paid() || 'approved' === $a['status'] ) { ?>
        <p class="tcc-message">پرداخت این سفارش در ووکامرس ثبت شده است. ارسال رسید تازه لازم نیست.</p>
    <?php } else { ?>
        <?php if ( $latest ) { ?><p class="tcc-message">شما قبلاً اعلام واریز کرده‌اید. رد یا اصلاح رسید به معنی نیاز به واریز دوباره نیست؛ پیش از هر انتقال تازه با فروشگاه تطبیق دهید.</p><details><summary>اطلاعات مقصد و مبلغ ثبت‌شده</summary><?php } else { ?><p><?php echo nl2br( esc_html( $settings['instructions'] ) ); ?></p><?php } ?>
        <div class="tcc-transfer">
            <div class="tcc-money"><span>مبلغ قابل پرداخت</span><strong><?php echo wp_kses_post( wc_price( $a['amount'], array( 'currency' => $a['currency'] ) ) ); ?></strong><button type="button" class="tcc-btn tcc-subtle" data-tcc-copy="<?php echo esc_attr( wc_format_decimal( $a['amount'], 8, true ) ); ?>">کپی مبلغ</button></div>
            <div class="tcc-bank"><span><?php echo esc_html( $card['bank'] ); ?></span><strong dir="ltr" class="tcc-card-number"><?php echo esc_html( implode( ' ', str_split( $card['number'], 4 ) ) ); ?></strong><span>به نام <?php echo esc_html( $card['holder'] ); ?></span><button type="button" class="tcc-btn" data-tcc-copy="<?php echo esc_attr( $card['number'] ); ?>">کپی شماره کارت</button></div>
        </div>
        <?php if ( $latest ) { echo '</details>'; } ?>
        <p class="tcc-muted">واحد مبلغ مطابق سفارش است؛ اگر اپ بانکی ریال می‌خواهد و سفارش به تومان است، واحد را قبل از انتقال بررسی کنید.</p>
        <p class="tcc-muted">مهلت اعلام رسید: <?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $a['expires_at'] . ' UTC' ) ) ); ?>. گذشتن مهلت، اثبات عدم واریز نیست.</p>
        <?php if ( 'under_review' === $a['status'] ) { ?><p class="tcc-message">رسید ثبت شده و منتظر بررسی فروشگاه است؛ پرداخت هنوز تأیید نشده. دوباره واریز نکنید. <?php echo esc_html( $settings['review_text'] ); ?></p><?php } ?>
    <?php } ?>
    <?php if ( $reason && in_array( $a['status'], array( 'rejected', 'correction', 'reconcile' ), true ) ) { ?><p class="tcc-message tcc-error"><strong>پیام فروشگاه:</strong> <?php echo esc_html( $reason ); ?></p><?php } ?>
    <?php if ( $latest ) { ?><details class="tcc-history"><summary>رسید و اطلاعات ارسال‌شده شما</summary><p>کد پیگیری: <bdi><?php echo esc_html( $latest['reference'] ?: 'ثبت نشده' ); ?></bdi></p><p><?php echo esc_html( $latest['customer_note'] ); ?></p><?php if ( $latest['file_key'] ) { ?><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( TCC_Access::file_url( $order, $latest, $a ) ); ?>">مشاهده تصویر رسید</a><?php } ?></details><?php } ?>
    <?php if ( $editable ) { ?>
        <details class="tcc-upload" <?php if ( 'under_review' !== $a['status'] ) { echo 'open'; } ?>><summary><?php echo $latest ? 'ارسال رسید اصلاح‌شده' : 'واریز کردم؛ ارسال رسید'; ?></summary>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="tcc-upload-form" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <input type="hidden" name="action" value="tcc_submit"><input type="hidden" name="order_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="tcc_token" value="<?php echo esc_attr( TCC_Access::token( $order, $a['access_seed'] ) ); ?>"><input type="hidden" name="request_key" value="<?php echo esc_attr( bin2hex( random_bytes( 16 ) ) ); ?>">
            <?php wp_nonce_field( 'tcc_submit_' . $id ); ?>
            <label class="tcc-field">تصویر رسید <?php echo 'yes' === $settings['receipt_required'] ? '(الزامی)' : '(اختیاری؛ بدون تصویر، کد پیگیری لازم است)'; ?><input name="receipt" type="file" accept="image/jpeg,image/png,image/webp" <?php if ( 'yes' === $settings['receipt_required'] ) { echo 'required'; } ?>></label>
            <small>JPG، PNG یا WebP؛ حداکثر <?php echo esc_html( size_format( min( $settings['max_mb'] * 1024 * 1024, wp_max_upload_size() ) ) ); ?>. رمز کارت، CVV2 و رمز پویا را ارسال نکنید.</small>
            <img class="tcc-preview" hidden alt="پیش‌نمایش رسید انتخاب‌شده">
            <div class="tcc-fields"><label class="tcc-field">کد پیگیری <?php echo 'yes' === $settings['reference_required'] ? '(الزامی)' : '(اختیاری)'; ?><input name="reference" maxlength="80" dir="ltr" <?php if ( 'yes' === $settings['reference_required'] ) { echo 'required'; } ?>></label><label class="tcc-field">چهار رقم آخر کارت مبدأ (اختیاری)<input name="last4" inputmode="numeric" maxlength="4" dir="ltr"></label></div>
            <label class="tcc-field">توضیح (اختیاری)<textarea name="note" maxlength="1000" rows="3"></textarea></label>
            <progress class="tcc-progress" max="100" value="0" hidden aria-label="پیشرفت آپلود"></progress><p class="tcc-feedback" role="status" aria-live="polite"></p>
            <button type="submit" class="tcc-btn tcc-primary">ارسال رسید برای بررسی</button>
        </form></details>
    <?php } ?>
    <?php if ( $settings['support'] ) { ?><p class="tcc-support">پشتیبانی: <?php echo nl2br( esc_html( $settings['support'] ) ); ?></p><?php } ?>
    <p class="tcc-feedback tcc-copy-feedback" aria-live="polite"></p>
</section>
