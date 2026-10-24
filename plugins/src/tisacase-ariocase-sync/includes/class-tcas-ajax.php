<?php
/**
 * AJAX مدیریتی؛ هر Request فقط یک جفت را بررسی یا متصل می‌کند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Ajax' ) ) {

	final class TCAS_Ajax {
		public static function init() {
			$actions = array( 'preview_start', 'preview_step', 'connect_start', 'connect_step', 'test_connection', 'add_mapping', 'sync_mapping', 'disconnect_mapping', 'clear_logs' );
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

		private static function pair_label( $pair ) {
			return trim( (string) $pair['local_code'] . ' - ' . (string) $pair['remote_code'] );
		}

		public static function preview_start() {
			self::auth();
			$pairs = TCAS_Core::parse_pairs( isset( $_POST['pairs'] ) ? wp_unslash( $_POST['pairs'] ) : '' );
			if ( ! $pairs ) {
				wp_send_json_error( array( 'message' => 'حداقل یک جفت با قالب «SKU تیساکیس - کد ArioCase» وارد کنید.' ), 400 );
			}
			$token = wp_generate_password( 32, false, false );
			$job   = array(
				'user_id' => get_current_user_id(),
				'mode'    => 'preview',
				'pairs'   => array_values( $pairs ),
				'offset'  => 0,
				'results' => array(),
			);
			self::save_job( $token, $job );
			wp_send_json_success( array( 'token' => $token, 'total' => count( $pairs ) ) );
		}

		public static function preview_step() {
			self::auth();
			list( $token, $job ) = self::get_job( 'preview' );
			if ( $job['offset'] >= count( $job['pairs'] ) ) {
				wp_send_json_success( array( 'done' => true, 'processed' => count( $job['pairs'] ), 'total' => count( $job['pairs'] ) ) );
			}
			$index   = $job['offset'];
			$pair    = $job['pairs'][ $index ];
			$preview = TCAS_Pairing::preview( $pair );
			$preview['row']['index'] = $index;
			$job['results'][ $index ]  = $preview;
			$job['offset']++;
			self::save_job( $token, $job );
			TCAS_Logger::add( 'pair_preview', self::pair_label( $pair ), $preview['row']['message'] );
			wp_send_json_success( array(
				'token'     => $token,
				'done'      => $job['offset'] >= count( $job['pairs'] ),
				'processed' => $job['offset'],
				'total'     => count( $job['pairs'] ),
				'row'       => $preview['row'],
			) );
		}

		public static function connect_start() {
			self::auth();
			list( $token, $job ) = self::get_job( 'preview' );
			$raw_indexes = isset( $_POST['pairs'] ) ? (array) wp_unslash( $_POST['pairs'] ) : array();
			$valid       = array();
			foreach ( $raw_indexes as $raw_index ) {
				if ( ! is_scalar( $raw_index ) || ! ctype_digit( (string) $raw_index ) ) {
					continue;
				}
				$index = (int) $raw_index;
				if ( isset( $job['results'][ $index ]['row']['selectable'] ) && $job['results'][ $index ]['row']['selectable'] ) {
					$valid[ $index ] = $index;
				}
			}
			if ( ! $valid ) {
				wp_send_json_error( array( 'message' => 'جفت قابل‌اتصالی انتخاب نشده است.' ), 400 );
			}
			$job['mode']            = 'connect';
			$job['connect_indexes'] = array_values( $valid );
			$job['offset']          = 0;
			$job['connect_result']  = array();
			self::save_job( $token, $job );
			wp_send_json_success( array( 'token' => $token, 'total' => count( $valid ) ) );
		}

		public static function connect_step() {
			self::auth();
			list( $token, $job ) = self::get_job( 'connect' );
			$total = count( $job['connect_indexes'] );
			if ( $job['offset'] >= $total ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total, 'total' => $total ) );
			}
			$index   = $job['connect_indexes'][ $job['offset'] ];
			$pair    = $job['pairs'][ $index ];
			// فقط دادهٔ تازه؛ Snapshot پیش‌نمایش برای اتصال نهایی استفاده نمی‌شود.
			$preview = TCAS_Pairing::preview( $pair );
			$result  = TCAS_Pairing::connect( $preview );
			$row     = array( 'local_code' => $pair['local_code'], 'remote_code' => $pair['remote_code'] );
			if ( is_wp_error( $result ) ) {
				$row['status']  = 'error';
				$row['message'] = $result->get_error_message();
			} else {
				$row['status']     = 'done';
				$row['message']    = $result['message'];
				$row['product_id'] = $result['product_id'];
				$row['edit_url']   = admin_url( 'post.php?post=' . absint( $result['product_id'] ) . '&action=edit' );
			}
			$job['connect_result'][ $index ] = $row;
			$job['offset']++;
			self::save_job( $token, $job );
			TCAS_Logger::add( 'pair_connect', self::pair_label( $pair ), $row['message'] );
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
			$pair = array(
				'local_code'  => isset( $_POST['sku'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sku'] ) ) ) : '',
				'remote_code' => isset( $_POST['remote_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['remote_code'] ) ) ) : '',
				'error'       => '',
			);
			$result = TCAS_Pairing::connect( TCAS_Pairing::preview( $pair ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			TCAS_Logger::add( 'pair_add', self::pair_label( $pair ), 'اتصال ساخته و فقط موجودی به‌روز شد.' );
			wp_send_json_success( array( 'message' => 'جفت متصل و موجودی به‌روزرسانی شد.', 'reload' => true ) );
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
