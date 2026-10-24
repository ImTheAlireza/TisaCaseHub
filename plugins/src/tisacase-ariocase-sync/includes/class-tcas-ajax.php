<?php
/**
 * AJAX مدیریتی؛ هر Request فقط یک محصول را پردازش می‌کند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Ajax' ) ) {

	final class TCAS_Ajax {
		public static function init() {
			$actions = array( 'preview_start', 'preview_step', 'import_start', 'import_step', 'test_connection', 'add_mapping', 'sync_mapping', 'disconnect_mapping', 'clear_logs' );
			foreach ( $actions as $action ) {
				add_action( 'wp_ajax_tcas_' . $action, array( __CLASS__, $action ) );
			}
		}

		private static function auth() {
			if ( ! TCAS_Core::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
			}
			$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, TCAS_Core::NONCE ) ) {
				wp_send_json_error( array( 'message' => 'توکن امنیتی منقضی شده است؛ صفحه را تازه‌سازی کنید.' ), 403 );
			}
		}

		private static function token() {
			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			return preg_match( '/^[a-zA-Z0-9]{32}$/', $token ) ? $token : '';
		}

		private static function get_job( $mode = '' ) {
			$token = self::token();
			if ( '' === $token ) {
				wp_send_json_error( array( 'message' => 'شناسهٔ عملیات معتبر نیست.' ), 400 );
			}
			$job = get_transient( 'tcas_job_' . $token );
			if ( ! is_array( $job ) || (int) $job['user_id'] !== get_current_user_id() || ( $mode && $job['mode'] !== $mode ) ) {
				wp_send_json_error( array( 'message' => 'عملیات منقضی شده یا متعلق به کاربر دیگری است؛ دوباره شروع کنید.' ), 409 );
			}
			return array( $token, $job );
		}

		private static function save_job( $token, $job ) {
			set_transient( 'tcas_job_' . $token, $job, HOUR_IN_SECONDS );
		}

		public static function preview_start() {
			self::auth();
			$codes = TCAS_Core::parse_codes( isset( $_POST['codes'] ) ? wp_unslash( $_POST['codes'] ) : '' );
			if ( ! $codes ) {
				wp_send_json_error( array( 'message' => 'حداقل یک کد معتبر وارد کنید.' ), 400 );
			}
			$token = wp_generate_password( 32, false, false );
			$job   = array(
				'user_id' => get_current_user_id(),
				'mode'    => 'preview',
				'codes'   => $codes,
				'offset'  => 0,
				'results' => array(),
			);
			self::save_job( $token, $job );
			wp_send_json_success( array( 'token' => $token, 'total' => count( $codes ), 'codes' => $codes ) );
		}

		public static function preview_step() {
			self::auth();
			list( $token, $job ) = self::get_job( 'preview' );
			if ( $job['offset'] >= count( $job['codes'] ) ) {
				wp_send_json_success( array( 'done' => true, 'processed' => count( $job['codes'] ), 'total' => count( $job['codes'] ) ) );
			}
			$code    = $job['codes'][ $job['offset'] ];
			$preview = TCAS_Importer::preview( $code );
			$job['results'][ $code ] = $preview;
			$job['offset']++;
			self::save_job( $token, $job );
			TCAS_Logger::add( 'preview', $code, $preview['row']['message'] );
			wp_send_json_success( array(
				'token'     => $token,
				'done'      => $job['offset'] >= count( $job['codes'] ),
				'processed' => $job['offset'],
				'total'     => count( $job['codes'] ),
				'row'       => $preview['row'],
			) );
		}

		public static function import_start() {
			self::auth();
			list( $token, $job ) = self::get_job( 'preview' );
			$codes = TCAS_Core::parse_codes( isset( $_POST['codes'] ) ? (array) wp_unslash( $_POST['codes'] ) : array() );
			$valid = array();
			foreach ( $codes as $code ) {
				if ( isset( $job['results'][ $code ]['row']['selectable'] ) && $job['results'][ $code ]['row']['selectable'] ) {
					$valid[] = $code;
				}
			}
			if ( ! $valid ) {
				wp_send_json_error( array( 'message' => 'ردیف قابل‌اجرا انتخاب نشده است.' ), 400 );
			}
			$job['mode']          = 'import';
			$job['import_codes']  = $valid;
			$job['offset']        = 0;
			$job['import_result'] = array();
			$job['options']       = array(
				'prices' => ! empty( $_POST['prices'] ),
				'images' => ! empty( $_POST['images'] ),
			);
			self::save_job( $token, $job );
			wp_send_json_success( array( 'token' => $token, 'total' => count( $valid ) ) );
		}

		public static function import_step() {
			self::auth();
			list( $token, $job ) = self::get_job( 'import' );
			$total = count( $job['import_codes'] );
			if ( $job['offset'] >= $total ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total, 'total' => $total ) );
			}
			$code = $job['import_codes'][ $job['offset'] ];
			// اجرای نهایی به Snapshot پیش‌نمایش تکیه نمی‌کند: کد، نوع و موجودی تازه می‌شوند.
			$preview = TCAS_Importer::preview( $code );
			$result  = TCAS_Importer::import( $preview, $job['options'] );
			$row     = array( 'code' => $code );
			if ( is_wp_error( $result ) ) {
				$row['status']  = 'error';
				$row['message'] = $result->get_error_message();
			} else {
				$row['status']     = 'done';
				$row['message']    = $result['message'];
				$row['product_id'] = $result['product_id'];
				$row['edit_url']   = admin_url( 'post.php?post=' . absint( $result['product_id'] ) . '&action=edit' );
				$row['warnings']   = $result['warnings'];
			}
			$job['import_result'][ $code ] = $row;
			$job['offset']++;
			self::save_job( $token, $job );
			TCAS_Logger::add( 'import', $code, $row['message'] );
			wp_send_json_success( array(
				'done'      => $job['offset'] >= $total,
				'processed' => $job['offset'],
				'total'     => $total,
				'row'       => $row,
			) );
		}

		public static function test_connection() {
			self::auth();
			$code = isset( $_POST['code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
			if ( '' === $code ) {
				wp_send_json_error( array( 'message' => 'برای تست، یک کد محصول معتبر در ArioCase وارد کنید.' ), 400 );
			}
			$product = TCAS_Api::fetch_product( $code );
			if ( is_wp_error( $product ) ) {
				TCAS_Logger::add( 'connection_error', $code, $product->get_error_message() );
				wp_send_json_error( array( 'message' => $product->get_error_message() ), 400 );
			}
			TCAS_Logger::add( 'connection_ok', $code, 'اتصال و تشخیص ساختار پاسخ موفق بود.' );
			wp_send_json_success( array(
				'message' => 'اتصال موفق بود و ساختار پاسخ شناسایی شد.',
				'product' => array( 'code' => $product['code'], 'name' => $product['name'], 'type' => $product['type'], 'stock' => $product['stock'], 'variations' => count( $product['variations'] ) ),
			) );
		}

		public static function add_mapping() {
			self::auth();
			$sku    = isset( $_POST['sku'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sku'] ) ) ) : '';
			$code   = isset( $_POST['remote_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['remote_code'] ) ) ) : '';
			$local  = TCAS_Mapping::local_by_sku( $sku );
			if ( is_wp_error( $local ) || ! $local ) {
				wp_send_json_error( array( 'message' => is_wp_error( $local ) ? $local->get_error_message() : 'محصولی با این SKU پیدا نشد.' ), 404 );
			}
			$product = wc_get_product( $local );
			if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
				wp_send_json_error( array( 'message' => 'برای محصول متغیر، کد هر Variation را جداگانه متصل کنید.' ), 400 );
			}
			$mapped = TCAS_Mapping::map( $local, $code, true );
			if ( is_wp_error( $mapped ) ) {
				wp_send_json_error( array( 'message' => $mapped->get_error_message() ), 409 );
			}
			TCAS_Logger::add( 'mapping_add', $code, sprintf( 'به محصول #%d متصل شد.', $local ) );
			wp_send_json_success( array( 'message' => 'اتصال ساخته شد. برای دریافت فوری موجودی، دکمهٔ «سینک» را بزنید.', 'reload' => true ) );
		}

		public static function sync_mapping() {
			self::auth();
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			if ( ! $product_id || ! TCAS_Mapping::is_enabled( $product_id ) ) {
				wp_send_json_error( array( 'message' => 'نگاشت فعال برای این محصول پیدا نشد.' ), 404 );
			}
			$state = TCAS_Live::refresh( $product_id );
			if ( null === $state ) {
				wp_send_json_error( array( 'message' => 'سینک زنده غیرفعال است یا این محصول برای سینک مستقیم مجاز نیست.' ), 409 );
			}
			if ( is_wp_error( $state ) ) {
				TCAS_Mapping::remember_error( $product_id, $state->get_error_message() );
				TCAS_Logger::add( 'sync_error', TCAS_Mapping::remote_code( $product_id ), $state->get_error_message() );
				wp_send_json_error( array( 'message' => $state->get_error_message() ), 400 );
			}
			TCAS_Logger::add( 'sync_ok', TCAS_Mapping::remote_code( $product_id ), 'موجودی به‌روزرسانی شد.' );
			wp_send_json_success( array(
				'message'  => 'موجودی با موفقیت به‌روزرسانی شد.',
				'quantity' => $state['quantity'],
				'status'   => TCAS_Stock::status_label( $state['status'] ),
				'time'     => current_time( 'mysql' ),
				'reload'   => true,
			) );
		}

		public static function disconnect_mapping() {
			self::auth();
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$code       = TCAS_Mapping::remote_code( $product_id );
			if ( ! $product_id || '' === $code ) {
				wp_send_json_error( array( 'message' => 'اتصال پیدا نشد.' ), 404 );
			}
			TCAS_Mapping::forget( $product_id );
			TCAS_Logger::add( 'mapping_remove', $code, sprintf( 'اتصال محصول #%d حذف شد؛ خود محصول حذف نشد.', $product_id ) );
			wp_send_json_success( array( 'message' => 'اتصال حذف شد؛ محصول و موجودی فعلی حذف نشدند.', 'reload' => true ) );
		}

		public static function clear_logs() {
			self::auth();
			TCAS_Logger::clear();
			wp_send_json_success( array( 'message' => 'گزارش‌ها پاک شدند.', 'reload' => true ) );
		}
	}
}
