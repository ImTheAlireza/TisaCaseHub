<?php
/**
 * نویسندهٔ سبک PDF — بدون هیچ کتابخانهٔ بیرونی.
 *
 * نکتهٔ کلیدی: PDF جدول xref را **پایان** فایل می‌خواهد، پس نمی‌شود خروجی را همان‌طور
 * که ساخته می‌شود به مرورگر فرستاد… ولی می‌شود اگر خودمان شمارندهٔ بایت داشته باشیم:
 * هر شیء درست سر جایش نوشته می‌شود، آفستش ثبت می‌شود و شماره‌ها را از قبل «رزرو»
 * می‌کنیم تا ارجاع‌های رو به جلو (Catalog/Pages) هم بی‌نیاز به برگشت در فایل حل شوند.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Pdf_Writer' ) ) {

	final class TisaCase_Exporter_Pdf_Writer {

		/** مقصد نوشتن. */
		private $handle;

		/** شمارندهٔ بایت (آفست شیء بعدی). */
		private $pointer = 0;

		/** آفست هر شیء: شماره → بایت. */
		private $offsets = array();

		/** شمارهٔ شیء بعدی. */
		private $next = 1;

		/** شناسهٔ فایل (ID در trailer). */
		private $id;

		/**
		 * @param resource $handle مقصد (فایل یا خروجی استاندارد).
		 */
		public function __construct( $handle ) {
			$this->handle = $handle;
			$this->id     = strtoupper( md5( uniqid( 'tisacase-pdf', true ) ) );
		}

		/** سرآیند فایل. */
		public function begin() {
			$this->put( "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" );
		}

		/** رزرو شمارهٔ شیء (محتوا بعداً با emit نوشته می‌شود). */
		public function reserve() {
			return $this->next++;
		}

		/** نوشتن یک شیء ساده. */
		public function object( $body ) {
			return $this->emit( $this->next++, $body );
		}

		/** نوشتن یک شیء با شمارهٔ مشخص (رزروشده). */
		public function emit( $number, $body ) {
			$number                  = (int) $number;
			$this->offsets[ $number ] = $this->pointer;
			$this->put( $number . " 0 obj\n" . $body . "\nendobj\n" );

			return $number;
		}

		/** شیء جریانی با محتوای درون‌حافظه. */
		public function stream( $extra, $body ) {
			$body = (string) $body;
			$dict = '<< /Length ' . strlen( $body ) . ( '' !== trim( (string) $extra ) ? ' ' . trim( (string) $extra ) : '' ) . ' >>';

			return $this->object( $dict . "\nstream\n" . $body . "\nendstream" );
		}

		/** شیء جریانی با محتوای یک فایل (قلم). */
		public function file_stream( $extra, $path ) {
			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- مسیر داخلی افزونه.
			$dict = '<< /Length ' . $size . ( '' !== trim( (string) $extra ) ? ' ' . trim( (string) $extra ) : '' ) . ' >>';
			$num  = $this->next++;

			$this->offsets[ $num ] = $this->pointer;
			$this->put( $num . " 0 obj\n" . $dict . "\nstream\n" );

			$in = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- مسیر داخلی افزونه.

			if ( $in ) {
				while ( ! feof( $in ) ) {
					$chunk = fread( $in, 65536 );

					if ( false === $chunk || '' === $chunk ) {
						break;
					}

					$this->put( $chunk );
				}

				fclose( $in );
			}

			$this->put( "\nendstream\nendobj\n" );

			return $num;
		}

		/** نوشتن جدول xref و trailer و پایان فایل. */
		public function finish( $root, $info = 0 ) {
			$start = $this->pointer;
			$size  = $this->next;
			$table = "xref\n0 " . $size . "\n0000000000 65535 f \n";

			for ( $i = 1; $i < $size; $i++ ) {
				$offset = isset( $this->offsets[ $i ] ) ? $this->offsets[ $i ] : 0;
				$table .= sprintf( "%010d 00000 n \n", $offset );
			}

			$trailer = "trailer\n<< /Size " . $size . ' /Root ' . (int) $root . ' 0 R';

			if ( $info > 0 ) {
				$trailer .= ' /Info ' . (int) $info . ' 0 R';
			}

			$trailer .= ' /ID [<' . $this->id . '> <' . $this->id . '>] >>';

			$this->put( $table . $trailer . "\nstartxref\n" . $start . "\n%%EOF\n" );
		}

		/** آفست جاری. */
		public function pointer() {
			return $this->pointer;
		}

		/** نوشتن خام + شمارش بایت (fwrite ممکن است ناقص بنویسد). */
		private function put( $bytes ) {
			$bytes = (string) $bytes;
			$len   = strlen( $bytes );

			if ( $len > 0 && is_resource( $this->handle ) ) {
				$written = 0;

				while ( $written < $len ) {
					$chunk = @fwrite( $this->handle, substr( $bytes, $written ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- مقصد می‌تواند قطع شود.

					if ( false === $chunk || 0 === $chunk ) {
						break;
					}

					$written += $chunk;
				}
			}

			$this->pointer += $len;
		}

		/** رشتهٔ PDF با فراردهی پرانتز و بک‌اسلش (فقط متن ASCII). */
		public static function string( $value ) {
			return '(' . str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), (string) $value ) . ')';
		}
	}
}
