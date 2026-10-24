<?php
defined( 'ABSPATH' ) || exit;
class TCC_Storage {
    public static function within( $path, $root ) {
        $path = wp_normalize_path( $path ); $root = wp_normalize_path( $root );
        if ( '\\' === DIRECTORY_SEPARATOR ) { $path = strtolower( $path ); $root = strtolower( $root ); }
        if ( '/' === $root ) { return 0 === strpos( $path, '/' ); }
        $path = rtrim( $path, '/' ); $root = rtrim( $root, '/' );
        return '' !== $root && ( $path === $root || 0 === strpos( $path . '/', $root . '/' ) );
    }
    public function base() {
        $doc_value = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? trim( $_SERVER['DOCUMENT_ROOT'] ) : '';
        $doc = '' !== $doc_value ? realpath( $doc_value ) : false;
        if ( defined( 'TCC_PRIVATE_DIR' ) ) { $path = TCC_PRIVATE_DIR; }
        elseif ( $doc && '/' !== $doc ) { $path = dirname( $doc ) . '/tisacase-private-receipts-' . substr( hash( 'sha256', home_url( '/' ) ), 0, 12 ); }
        else { TCC_Helpers::failure( 'مسیر خصوصی مشخص نیست؛ مدیر باید TCC_PRIVATE_DIR را بیرون ریشه وب در wp-config.php تنظیم کند.' ); }
        if ( ! is_string( $path ) || ! preg_match( '~^(?:/|[A-Za-z]:[\\\\/])~', $path ) ) { TCC_Helpers::failure( 'مسیر خصوصی باید مطلق باشد.' ); }
        $roots = array_filter( array( $doc, realpath( ABSPATH ), defined( 'WP_CONTENT_DIR' ) ? realpath( WP_CONTENT_DIR ) : false ) );
        $uploads = wp_upload_dir(); if ( empty( $uploads['error'] ) ) { $roots[] = realpath( $uploads['basedir'] ); }
        // Reject lexical public paths before mkdir, then re-check resolved paths against symlinks.
        foreach ( $roots as $root ) { if ( $root && self::within( $path, $root ) ) { TCC_Helpers::failure( 'مسیر رسید داخل فضای عمومی وب است؛ دریافت رسید متوقف شد.' ); } }
        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) { TCC_Helpers::failure( 'ساخت پوشه خصوصی ممکن نیست؛ مسیر بیرون وب و مجوز نوشتن را بررسی کنید.' ); }
        $resolved = realpath( $path );
        if ( ! $resolved || ! is_writable( $resolved ) ) { TCC_Helpers::failure( 'پوشه خصوصی قابل نوشتن نیست.' ); }
        foreach ( $roots as $root ) { if ( $root && self::within( $resolved, $root ) ) { TCC_Helpers::failure( 'مسیر واقعی رسید به پوشه عمومی اشاره می‌کند.' ); } }
        @chmod( $resolved, 0700 );
        return $resolved;
    }
    public function receive( $file ) {
        $settings = TCC_Settings::get();
        if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) { TCC_Helpers::failure( 'آپلود کامل نشد؛ فایل JPG، PNG یا WebP را دوباره انتخاب کنید.' ); }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) { TCC_Helpers::failure( 'فایل آپلود معتبر نیست.' ); }
        $bytes = filesize( $file['tmp_name'] );
        if ( ! $bytes || $bytes > min( $settings['max_mb'] * 1024 * 1024, wp_max_upload_size() ) ) { TCC_Helpers::failure( 'حجم فایل بیشتر از حد مجاز است یا فایل خالی است.' ); }
        $types = array( 'image/jpeg' => array( 'jpg', 'jpeg' ), 'image/png' => array( 'png' ), 'image/webp' => array( 'webp' ) );
        $info = @getimagesize( $file['tmp_name'] );
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! $info || ! isset( $types[$info['mime']] ) || ! in_array( $ext, $types[$info['mime']], true ) || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 20000000 ) { TCC_Helpers::failure( 'فقط تصویر سالم JPG، PNG یا WebP با ابعاد متعارف پذیرفته می‌شود؛ PDF و HEIC پشتیبانی نمی‌شوند.' ); }
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
        if ( empty( $checked['type'] ) || $checked['type'] !== $info['mime'] ) { TCC_Helpers::failure( 'نوع واقعی فایل با نام آن مطابقت ندارد.' ); }
        $key = bin2hex( random_bytes( 24 ) ) . '.' . $types[$info['mime']][0];
        $base = $this->base();
        $free = @disk_free_space( $base );
        if ( false !== $free && $free < 100 * 1024 * 1024 + $bytes ) { TCC_Helpers::failure( 'فضای امن کافی برای نگهداری رسید باقی نمانده است؛ با فروشگاه تماس بگیرید.' ); }
        $path = $base . '/' . $key;
        $hash = hash_file( 'sha256', $file['tmp_name'] );
        if ( ! $hash || ! move_uploaded_file( $file['tmp_name'], $path ) ) { TCC_Helpers::failure( 'ذخیره رسید ممکن نشد؛ کمی بعد دوباره تلاش کنید.' ); }
        @chmod( $path, 0600 );
        return array( 'file_key' => $key, 'mime' => $info['mime'], 'file_hash' => $hash, 'file_bytes' => $bytes );
    }
    public function path( $key ) {
        if ( ! preg_match( '/^[a-f0-9]{48}\.(jpg|png|webp)$/D', $key ) ) { TCC_Helpers::failure( 'شناسه فایل معتبر نیست.' ); }
        $base = $this->base(); $path = realpath( $base . '/' . $key );
        if ( ! $path || dirname( $path ) !== $base || ! is_file( $path ) ) { TCC_Helpers::failure( 'فایل رسید در دسترس نیست؛ با مدیر تماس بگیرید.' ); }
        return $path;
    }
    public function discard( $key ) { try { $path = $this->path( $key ); wp_delete_file( $path ); } catch ( Throwable $e ) { /* Never follow untrusted paths on cleanup. */ } }
}
