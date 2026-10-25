<?php
defined( 'ABSPATH' ) || exit;
$closed = $order->has_status( array( 'cancelled', 'failed', 'refunded', 'trash' ) );
$paid = ! $closed && ( $order->is_paid() || 'approved' === $a['status'] );
$waiting = ! $closed && ! $paid && 'under_review' === $a['status'] && $latest;
$approving = ! $closed && ! $paid && 'approving' === $a['status'];
$needs_action = ! $closed && ! $paid && in_array( $a['status'], array( 'rejected', 'correction', 'reconcile' ), true );
$matches = TCC_Helpers::matches( $order, $a );
$rial = TCC_Helpers::rial_amount( $a['amount'], $a['currency'] );
$logo = TCC_Helpers::bank_logo( $card['bank'] );
$step = $paid || $waiting || $approving ? 3 : ( $latest ? 2 : 1 );
$receipt_id = 'tcc-sent-' . $id;
$file_help = 'tcc-file-help-' . $id;
$file_name = 'tcc-file-name-' . $id;
$resubmit_message = 'لطفاً موارد اعلام‌شده را اصلاح کنید و مجدداً برای بررسی ثبت کنید.';
?>
<section class="tcc-panel tcc-payment" dir="rtl" aria-label="پرداخت کارت به کارت" data-state="<?php echo esc_attr( $a['status'] ); ?>">
    <header class="tcc-heading"><div><span class="tcc-eyebrow"><?php echo TCC_Helpers::ui_icon( 'card' ); ?> کارت به کارت تیساکیس</span><h2>پرداخت سفارش <bdi><?php echo esc_html( $order->get_order_number() ); ?></bdi></h2></div><?php echo TCC_Helpers::display_badge( $order, $a ); ?></header>
    <?php if ( ! $closed ) { ?><ol class="tcc-steps" aria-label="مراحل پرداخت">
        <?php foreach ( array( 1 => 'واریز وجه', 2 => 'ارسال رسید', 3 => 'بررسی فروشگاه' ) as $number => $label ) { $done = $paid || $number < $step; ?>
            <li class="<?php echo $done ? 'is-done' : ( $number === $step ? 'is-current' : '' ); ?>" <?php if ( ! $paid && $number === $step ) { echo 'aria-current="step"'; } ?>><span class="tcc-step-number" aria-hidden="true"><?php echo $done ? TCC_Helpers::ui_icon( 'check' ) : esc_html( TCC_Helpers::persian_number( (string) $number ) ); ?></span><span><?php echo esc_html( $label ); ?></span></li>
        <?php } ?>
    </ol><?php } ?>
    <?php if ( $flash && ( $flash['error'] || ! $waiting ) ) { ?><p role="status" class="tcc-message <?php echo $flash['error'] ? 'tcc-error' : ''; ?>"><?php echo esc_html( $flash['message'] ); ?></p><?php } ?>
    <?php if ( ! $matches ) { ?><p class="tcc-message tcc-error">مبلغ یا ارز سفارش تغییر کرده است؛ قبل از واریز با فروشگاه هماهنگ کنید.</p><?php } ?>
    <?php if ( $closed || $paid || $waiting || $approving || $needs_action ) { ?>
        <div class="tcc-status-card <?php echo $paid ? 'is-paid' : ( $closed || $needs_action ? 'needs-attention' : '' ); ?>">
            <span class="tcc-status-icon"><?php echo TCC_Helpers::ui_icon( $paid ? 'check' : ( $closed || $needs_action ? 'info' : 'clock' ) ); ?></span>
            <div class="tcc-status-copy">
                <?php if ( $closed ) { ?><h3>این سفارش بسته شده است</h3><p>برای پیگیری واریز قبلی، با فروشگاه تماس بگیرید؛ دوباره واریز نکنید.</p>
                <?php } elseif ( $paid ) { ?><h3>پرداخت سفارش ثبت شد</h3><p>ارسال رسید یا واریز دوباره لازم نیست.</p>
                <?php } elseif ( $waiting ) { ?><h3>رسید ثبت شد؛ در انتظار بررسی</h3><p>پرداخت هنوز تأیید نشده است. دوباره واریز نکنید.</p><?php if ( $settings['review_text'] && $settings['review_text'] !== TCC_Settings::defaults()['review_text'] ) { ?><small><?php echo esc_html( $settings['review_text'] ); ?></small><?php } ?>
                <?php } elseif ( $approving ) { ?><h3>پرداخت در حال تطبیق است</h3><p>فروشگاه نتیجه را بررسی می‌کند؛ دوباره واریز نکنید.</p>
                <?php } else { ?><h3><?php echo 'reconcile' === $a['status'] ? 'پرداخت نیاز به تطبیق دارد' : 'رسید نیاز به اصلاح دارد'; ?></h3><p><?php echo esc_html( $reason ?: $resubmit_message ); ?></p><?php if ( $reason ) { ?><small><?php echo esc_html( $resubmit_message ); ?></small><?php } ?><?php } ?>
            </div>
            <?php if ( $waiting || $approving ) { ?><button type="button" class="tcc-status-refresh" data-tcc-refresh><?php echo TCC_Helpers::ui_icon( 'refresh' ); ?><span>تازه‌سازی وضعیت</span></button><?php } ?>
        </div>
    <?php } ?>
    <?php if ( $latest ) { ?>
        <div class="tcc-sent-receipt" id="<?php echo esc_attr( $receipt_id ); ?>">
            <span class="tcc-receipt-mark"><?php echo TCC_Helpers::ui_icon( 'receipt' ); ?></span>
            <div class="tcc-sent-info"><strong>رسید شما</strong><span>کد پیگیری: <bdi><?php echo esc_html( $latest['reference'] ?: 'ثبت نشده' ); ?></bdi></span></div>
            <?php if ( $latest['file_key'] ) { ?><a class="tcc-btn tcc-view-receipt" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( TCC_Access::file_url( $order, $latest, $a ) ); ?>">مشاهده تصویر رسید</a><?php } ?>
            <?php if ( $latest['customer_note'] ) { ?><details class="tcc-sent-note"><summary>توضیح شما <?php echo TCC_Helpers::ui_icon( 'chevron' ); ?></summary><p><?php echo esc_html( $latest['customer_note'] ); ?></p></details><?php } ?>
        </div>
    <?php } ?>
    <?php if ( ! $closed && ! $paid && ! $approving ) { ?>
        <?php if ( $latest ) { ?><details class="tcc-transfer-details"><summary><span><?php echo TCC_Helpers::ui_icon( 'card' ); ?> اطلاعات واریز این سفارش</span><?php echo TCC_Helpers::ui_icon( 'chevron' ); ?></summary><?php } elseif ( $settings['instructions'] ) { ?><p class="tcc-intro"><?php echo nl2br( esc_html( $settings['instructions'] ) ); ?></p><?php } ?>
        <?php if ( '' === $rial ) { ?><p class="tcc-message tcc-error">نمایش مبلغ ریالی ممکن نیست؛ قبل از واریز با فروشگاه تماس بگیرید.</p><?php } else { ?>
        <div class="tcc-transfer">
            <div class="tcc-money"><span class="tcc-amount-label"><?php echo $latest ? 'مبلغ ثبت‌شده' : 'مبلغ قابل واریز'; ?></span><div class="tcc-amount"><strong class="tcc-rial-value"><?php echo esc_html( TCC_Helpers::persian_number( $rial ) ); ?></strong><span class="tcc-currency">ریال</span></div><button type="button" class="tcc-btn tcc-subtle" data-tcc-kind="amount" data-tcc-copy="<?php echo esc_attr( $rial ); ?>" <?php if ( ! $matches ) { echo 'disabled'; } ?>><?php echo TCC_Helpers::ui_icon( 'copy' ); ?><span data-copy-label>کپی مبلغ ریالی</span></button></div>
            <div class="tcc-bank"><div class="tcc-bank-top"><span class="tcc-bank-brand"><span class="tcc-bank-logo"><?php if ( $logo ) { ?><img src="<?php echo esc_url( TCC_URL . 'assets/banks/' . $logo ); ?>" width="34" height="34" alt="" decoding="async"><?php } else { echo TCC_Helpers::ui_icon( 'card' ); } ?></span><span><?php echo esc_html( $card['bank'] ); ?></span></span><span class="tcc-card-caption">کارت مقصد</span></div><strong dir="ltr" class="tcc-card-number"><?php echo esc_html( implode( ' ', str_split( $card['number'], 4 ) ) ); ?></strong><div class="tcc-bank-bottom"><span class="tcc-holder"><small>به نام</small><span><?php echo esc_html( $card['holder'] ); ?></span></span><button type="button" class="tcc-btn" data-tcc-kind="card" data-tcc-copy="<?php echo esc_attr( $card['number'] ); ?>" <?php if ( ! $matches ) { echo 'disabled'; } ?>><?php echo TCC_Helpers::ui_icon( 'copy' ); ?><span data-copy-label>کپی شماره کارت</span></button></div></div>
        </div>
        <?php } ?>
        <?php if ( $latest ) { ?></details><?php } else { ?><div class="tcc-payment-meta"><?php echo TCC_Helpers::ui_icon( 'clock' ); ?><span>مهلت ارسال رسید: <bdi><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $a['expires_at'] . ' UTC' ) ) ); ?></bdi></span></div><?php } ?>
    <?php } ?>
    <?php if ( $editable && ! $closed && ! $paid && ! $approving && '' !== $rial ) { ?>
        <details class="tcc-upload <?php echo $latest ? 'is-secondary' : ''; ?>" <?php if ( ! $waiting ) { echo 'open'; } ?>><summary><span><?php echo TCC_Helpers::ui_icon( 'upload' ); ?> <?php echo $latest ? 'اصلاح اطلاعات یا تصویر رسید' : 'ارسال رسید واریز'; ?></span><?php echo TCC_Helpers::ui_icon( 'chevron' ); ?></summary>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="tcc-upload-form" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <input type="hidden" name="action" value="tcc_submit"><input type="hidden" name="order_id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="tcc_token" value="<?php echo esc_attr( TCC_Access::token( $order, $a['access_seed'] ) ); ?>"><input type="hidden" name="request_key" value="<?php echo esc_attr( bin2hex( random_bytes( 16 ) ) ); ?>">
            <?php wp_nonce_field( 'tcc_submit_' . $id ); ?>
            <label class="tcc-field tcc-file-field"><span class="tcc-field-label">تصویر رسید <small><?php echo 'yes' === $settings['receipt_required'] ? 'الزامی' : 'اختیاری؛ بدون تصویر، کد پیگیری لازم است'; ?></small></span><span class="tcc-file-picker"><input name="receipt" type="file" accept="image/jpeg,image/png,image/webp" aria-label="انتخاب تصویر رسید" aria-describedby="<?php echo esc_attr( $file_help . ' ' . $file_name ); ?>" <?php if ( 'yes' === $settings['receipt_required'] ) { echo 'required'; } ?>><span class="tcc-file-action" aria-hidden="true"><?php echo TCC_Helpers::ui_icon( 'upload' ); ?><span>انتخاب تصویر</span></span><bdi class="tcc-file-name" id="<?php echo esc_attr( $file_name ); ?>" data-tcc-file-name aria-live="polite">انتخاب از گالری یا فایل‌ها</bdi></span></label>
            <small class="tcc-file-help" id="<?php echo esc_attr( $file_help ); ?>">JPG، PNG یا WebP تا <?php echo esc_html( size_format( min( $settings['max_mb'] * 1024 * 1024, wp_max_upload_size() ) ) ); ?>. رمز کارت، CVV2 و رمز پویا را نفرستید.</small>
            <img class="tcc-preview" hidden alt="پیش‌نمایش رسید انتخاب‌شده">
            <div class="tcc-fields"><label class="tcc-field">کد پیگیری <?php echo 'yes' === $settings['reference_required'] ? '(الزامی)' : '(اختیاری)'; ?><input name="reference" maxlength="80" dir="ltr" <?php if ( 'yes' === $settings['reference_required'] ) { echo 'required'; } ?>></label><label class="tcc-field">چهار رقم مبدأ (اختیاری)<input name="last4" inputmode="numeric" maxlength="4" dir="ltr"></label></div>
            <label class="tcc-field">توضیح (اختیاری)<textarea name="note" maxlength="1000" rows="2"></textarea></label>
            <progress class="tcc-progress" max="100" value="0" hidden aria-label="پیشرفت آپلود"></progress><p class="tcc-feedback" role="status" aria-live="polite"></p>
            <button type="submit" class="tcc-btn tcc-primary"><?php echo TCC_Helpers::ui_icon( 'upload' ); ?> ارسال رسید برای بررسی</button>
        </form></details>
    <?php } ?>
    <?php if ( $settings['support'] ) { ?><p class="tcc-support">پشتیبانی: <?php echo nl2br( esc_html( $settings['support'] ) ); ?></p><?php } ?>
    <p class="tcc-feedback tcc-copy-feedback" role="status" aria-live="polite"></p>
</section>
