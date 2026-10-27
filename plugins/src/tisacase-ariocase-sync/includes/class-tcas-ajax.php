<?php
/**
 * AJAX مدیریتی؛ هر Request فقط یک جفت را بررسی یا متصل می‌کند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Ajax' ) ) {

	final class TCAS_Ajax {
		/** هر درخواست چند جفت را بررسی می‌کند تا تعداد درخواست‌ها به هاست کم شود. */
		const STEP_SIZE = 5;
		const STEP_MAX  = 20;

		public static function init() {
			$actions = array( 'preview_start', 'preview_step', 'connect_start', 'connect_step', 'test_connection', 'resolve_link', 'search_products', 'match_variations', 'preview_pair', 'search_index', 'add_mapping', 'sync_mapping', 'disconnect_mapping', 'clear_logs', 'sweep_now' );
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

		/**
		 * شناسه‌های لینکی از رابط با پیشوند b64: می‌آیند تا فایروال‌های هاست که وجود «://»
		 * در بدنهٔ POST را با 403/418 مسدود می‌کنند فعال نشوند. مقدار غیر b64 دست‌نخورده می‌ماند.
		 */
		private static function decode_transport( $value ) {
			$value = (string) $value;
			if ( 0 !== strpos( $value, 'b64:' ) ) {
				return $value;
			}
			$decoded = base64_decode( substr( $value, 4 ), true );
			return false === $decoded ? $value : $decoded;
		}

		/** Read URL references without stripping percent-encoded UTF-8 bytes from Persian slugs. */
		private static function posted_reference( $key ) {
			$value = isset( $_POST[ $key ] ) ? trim( self::decode_transport( wp_unslash( $_POST[ $key ] ) ) ) : '';
			return preg_match( '#^https?://#i', $value ) ? $value : trim( sanitize_text_field( $value ) );
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

		private static function pair_label( $pair, $partner_id = 'ariocase' ) {
			$partner_label = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase';
			$remote = preg_match( '#^https?://#i', (string) $pair['remote_code'] ) ? '[' . $partner_label . ' URL]' : (string) $pair['remote_code'];
			return trim( (string) $pair['local_code'] . ' - ' . $remote );
		}

		/** اندازهٔ دسته و نقطهٔ شروع را از درخواست می‌خواند («from» اجازهٔ ادامه پس از قطع ارتباط می‌دهد). */
		private static function step_window( $total, $offset ) {
			$from = isset( $_POST['from'] ) ? absint( $_POST['from'] ) : (int) $offset;
			if ( $from > $total ) {
				$from = $total;
			}
			$size = isset( $_POST['size'] ) ? absint( $_POST['size'] ) : self::STEP_SIZE;
			if ( $size < 1 ) {
				$size = 1;
			}
			if ( $size > self::STEP_MAX ) {
				$size = self::STEP_MAX;
			}
			return array( (int) $from, (int) $size );
		}

		public static function preview_start() {
			self::auth();
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase';
			$configured = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::is_configured( $partner_id ) : TCAS_Core::is_configured();
			if ( ! $configured ) {
				wp_send_json_error( array( 'message' => 'ابتدا تنظیمات اتصال همکار انتخاب‌شده را کامل کنید.' ), 422 );
			}
			$pairs = TCAS_Core::parse_pairs( isset( $_POST['pairs'] ) ? self::decode_transport( wp_unslash( $_POST['pairs'] ) ) : '' );
			if ( ! $pairs ) {
				wp_send_json_error( array( 'message' => 'حداقل یک جفت با قالب «SKU تیساکیس - مرجع محصول همکار» وارد کنید.' ), 400 );
			}
			// «فهرست مدل»: یک مرجع همکار روی چند Variation تیساکیس می‌نشیند؛ فهرست آن نمایه‌ها اینجا نگه داشته می‌شود.
			$shared = array();
			if ( isset( $_POST['shared'] ) ) {
				foreach ( (array) wp_unslash( $_POST['shared'] ) as $raw_shared ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					if ( is_scalar( $raw_shared ) && ctype_digit( (string) $raw_shared ) ) {
						$shared[ (int) $raw_shared ] = true;
					}
				}
			}
			// «انتقال با تأیید»: مرجع از Variation قبلی برداشته و به این Variation داده می‌شود.
			$reclaim = array();
			if ( isset( $_POST['reclaim'] ) ) {
				foreach ( (array) wp_unslash( $_POST['reclaim'] ) as $raw_reclaim ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					if ( is_scalar( $raw_reclaim ) && ctype_digit( (string) $raw_reclaim ) ) {
						$reclaim[ (int) $raw_reclaim ] = true;
					}
				}
			}
			$pairs = array_values( $pairs );
			foreach ( $pairs as $index => $pair ) {
				if ( isset( $shared[ $index ] ) ) {
					$pairs[ $index ]['shared_remote'] = true;
				}
				if ( isset( $reclaim[ $index ] ) ) {
					$pairs[ $index ]['reclaim'] = true;
				}
			}
			$token = wp_generate_password( 32, false, false );
			$job   = array(
				'user_id' => get_current_user_id(),
				'mode'    => 'preview',
				'partner_id' => $partner_id,
				'pairs'   => $pairs,
				'offset'  => 0,
				'results' => array(),
			);
			self::save_job( $token, $job );
			wp_send_json_success( array( 'token' => $token, 'total' => count( $pairs ) ) );
		}

		public static function preview_step() {
			self::auth();
			list( $token, $job ) = self::get_job( 'preview' );
			$total = count( $job['pairs'] );
			$partner_id = isset( $job['partner_id'] ) ? $job['partner_id'] : 'ariocase';
			list( $from, $size ) = self::step_window( $total, $job['offset'] );
			// از «from» ادامه می‌دهیم؛ اگر پاسخ درخواست قبلی در شبکه گم شده باشد، دوباره همان بازه خوانده می‌شود.
			$job['offset'] = $from;
			if ( $from >= $total ) {
				self::save_job( $token, $job );
				wp_send_json_success( array( 'token' => $token, 'done' => true, 'processed' => $total, 'total' => $total, 'rows' => array() ) );
			}
			$rows = array();
			$end  = min( $total, $from + $size );
			for ( $index = $from; $index < $end; $index++ ) {
				$pair    = $job['pairs'][ $index ];
				$preview = TCAS_Pairing::preview( $pair, $partner_id );
				$preview['row']['index']  = $index;
				$job['results'][ $index ] = $preview;
				$rows[]                   = $preview['row'];
				TCAS_Logger::add( 'pair_preview', self::pair_label( $pair, $partner_id ), $preview['row']['message'] );
			}
			$job['offset'] = $end;
			self::save_job( $token, $job );
			wp_send_json_success( array(
				'token'     => $token,
				'done'      => $end >= $total,
				'processed' => $end,
				'total'     => $total,
				'rows'      => $rows,
				'row'       => $rows ? $rows[ count( $rows ) - 1 ] : null,
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
			list( $from, $size ) = self::step_window( $total, $job['offset'] );
			$job['offset'] = $from;
			if ( $from >= $total ) {
				self::save_job( $token, $job );
				wp_send_json_success( array( 'done' => true, 'processed' => $total, 'total' => $total, 'rows' => array() ) );
			}
			$rows = array();
			$end  = min( $total, $from + $size );
			for ( $slot = $from; $slot < $end; $slot++ ) {
				$index   = $job['connect_indexes'][ $slot ];
				$pair    = $job['pairs'][ $index ];
				$partner_id = isset( $job['partner_id'] ) ? $job['partner_id'] : 'ariocase';
				// فقط دادهٔ تازه؛ Snapshot پیش‌نمایش برای اتصال نهایی استفاده نمی‌شود.
				$preview = TCAS_Pairing::preview( $pair, $partner_id );
				$result  = TCAS_Pairing::connect( $preview );
				$row     = array(
					'index'       => (int) $index,
					'local_code'  => isset( $preview['row']['local_code'] ) ? $preview['row']['local_code'] : $pair['local_code'],
					'remote_code' => isset( $preview['row']['remote_code'] ) ? $preview['row']['remote_code'] : $pair['remote_code'],
				);
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
				$rows[]                          = $row;
				TCAS_Logger::add( 'pair_connect', self::pair_label( $pair, $partner_id ), $row['message'] );
			}
			$job['offset'] = $end;
			self::save_job( $token, $job );
			wp_send_json_success( array(
				'done'      => $end >= $total,
				'processed' => $end,
				'total'     => $total,
				'rows'      => $rows,
				'row'       => $rows ? $rows[ count( $rows ) - 1 ] : null,
			) );
		}

		/**
		 * جست‌وجوی محصول محلی (نام یا SKU) و خواندن جزئیات یک محصول انتخاب‌شده.
		 * فقط از دیتابیس همین سایت می‌خواند؛ هیچ درخواستی به همکار نمی‌رود.
		 */
		public static function search_products() {
			self::auth();
			if ( ! class_exists( 'TCAS_Local_Search' ) ) {
				wp_send_json_error( array( 'message' => 'جست‌وجوی محصول در این نصب فعال نیست.' ), 501 );
			}
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			if ( $product_id ) {
				$detail = TCAS_Local_Search::detail( $product_id );
				if ( is_wp_error( $detail ) ) {
					wp_send_json_error( array( 'message' => $detail->get_error_message() ), 404 );
				}
				wp_send_json_success( $detail );
			}
			$term = isset( $_POST['term'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['term'] ) ) ) : '';
			if ( TCAS_Local_Search::too_short( $term ) ) {
				wp_send_json_error( array( 'message' => 'برای جست‌وجو دست‌کم ' . TCAS_Local_Search::MIN_TERM . ' حرف از نام محصول یا SKU را وارد کنید.' ), 400 );
			}
			$rows = TCAS_Local_Search::search( $term );
			// هر جست‌وجوی موفق لاگ نمی‌شود (نوشتن Option به‌ازای هر حرف، خودش کندی می‌ساخت)؛
			// فقط «نتیجه‌ای پیدا نشد» و حداکثر یک‌بار در هر ۱۰ دقیقه برای هر عبارت ثبت می‌شود.
			if ( ! $rows ) {
				$throttle = 'tcas_search_miss_' . md5( strtolower( $term ) );
				if ( ! get_transient( $throttle ) ) {
					set_transient( $throttle, 1, 600 );
					TCAS_Logger::add( 'product_search_empty', $term, 'نتیجه‌ای پیدا نشد (' . TCAS_Local_Search::$last_source . ').' );
				}
			}
			wp_send_json_success( array(
				'rows'   => $rows,
				'term'   => $term,
				'count'  => count( $rows ),
				'source' => TCAS_Local_Search::$last_source,
				'ms'     => round( (float) TCAS_Local_Search::$last_ms, 1 ),
			) );
		}

		/**
		 * فهرست فشردهٔ محصولات برای جست‌وجوی صفر-تأخیر در مرورگر.
		 * بعد از اولین دریافت، فیلتر در خود مرورگر انجام می‌شود و درخواستی زده نمی‌شود.
		 */
		public static function search_index() {
			self::auth();
			if ( ! class_exists( 'TCAS_Local_Search' ) || ! method_exists( 'TCAS_Local_Search', 'index' ) ) {
				wp_send_json_error( array( 'message' => 'فهرست جست‌وجو در این نصب فعال نیست.' ), 501 );
			}
			$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0;
			$data  = TCAS_Local_Search::index( $limit );
			wp_send_json_success( $data );
		}

		public static function resolve_link() {
			self::auth();
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase';
			$configured = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::is_configured( $partner_id ) : TCAS_Core::is_configured();
			if ( ! $configured ) {
				wp_send_json_error( array( 'message' => 'ابتدا تنظیمات اتصال همکار انتخاب‌شده را کامل کنید.' ), 422 );
			}
			// Keep percent-encoded UTF-8 slugs intact and try the local/legacy resolver first.
			$url = self::posted_reference( 'url' );
			if ( '' === $url ) {
				wp_send_json_error( array( 'message' => 'لینک محصول را وارد کنید.' ), 400 );
			}
			if ( 'ariocase' === $partner_id ) {
				$result = TCAS_Link_Resolver::resolve( $url );
			} else {
				// The generic provider path must never probe ArioCase as a side effect.
				$result = TCAS_Link_Resolver::resolve_local_link( $url );
				if ( is_wp_error( $result ) ) {
					$result = TCAS_Partners::resolve_link( $partner_id, $url );
				}
			}
			$parts  = parse_url( $url );
			$target = is_array( $parts ) ? ( isset( $parts['host'] ) ? $parts['host'] : '' ) . rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '' ) : '[invalid URL]';
			if ( is_wp_error( $result ) ) {
				TCAS_Logger::add( 'link_resolve_error', $target, $result->get_error_message() );
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			$debug = isset( $result['resolution_debug'] ) && is_array( $result['resolution_debug'] ) ? $result['resolution_debug'] : array();
			$message = sprintf(
				'Resolved #%d "%s" as %s with %d variations. Path ID=%d; Rewrite ID=%d; source=%s.',
				absint( isset( $result['product_id'] ) ? $result['product_id'] : 0 ),
				sanitize_text_field( isset( $result['name'] ) ? $result['name'] : '' ),
				sanitize_text_field( isset( $result['type'] ) ? $result['type'] : 'unknown' ),
				count( isset( $result['variations'] ) && is_array( $result['variations'] ) ? $result['variations'] : array() ),
				absint( isset( $debug['path_product_id'] ) ? $debug['path_product_id'] : 0 ),
				absint( isset( $debug['rewrite_product_id'] ) ? $debug['rewrite_product_id'] : 0 ),
				sanitize_text_field( isset( $debug['selected_by'] ) ? $debug['selected_by'] : 'other' )
			);
			TCAS_Logger::add( 'link_resolve_ok', $target, $message );
			wp_send_json_success( $result );
		}

		public static function match_variations() {
			self::auth();
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase';
			$configured = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::is_configured( $partner_id ) : TCAS_Core::is_configured();
			if ( ! $configured ) {
				wp_send_json_error( array( 'message' => 'ابتدا تنظیمات اتصال همکار انتخاب‌شده را کامل کنید.' ), 422 );
			}
			$local_code  = self::posted_reference( 'local_code' );
			$remote_code = self::posted_reference( 'remote_code' );
			$local_limit  = preg_match( '#^https?://#i', $local_code ) ? 2048 : 190;
			$remote_limit = preg_match( '#^https?://#i', $remote_code ) ? 2048 : 190;
			if ( '' === $local_code || '' === $remote_code || strlen( $local_code ) > $local_limit || strlen( $remote_code ) > $remote_limit ) {
				wp_send_json_error( array( 'message' => 'لینک یا مرجع هر دو محصول والد را وارد کنید.' ), 422 );
			}
			$local = preg_match( '#^https?://#i', $local_code ) ? TCAS_Link_Resolver::resolve_local_link( $local_code ) : TCAS_Link_Resolver::resolve_local_reference( $local_code );
			if ( is_wp_error( $local ) ) {
				wp_send_json_error( array( 'message' => $local->get_error_message() ), 422 );
			}
			if ( empty( $local['shop'] ) || 'tisacase' !== $local['shop'] || empty( $local['type'] ) || 'variable' !== $local['type'] || empty( $local['variations'] ) ) {
				wp_send_json_error( array( 'message' => 'مرجع TisaCase باید یک محصول متغیر با Variation باشد.' ), 422 );
			}
			$remote = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::resolve_link( $partner_id, $remote_code ) : TCAS_Link_Resolver::resolve_remote_reference( $remote_code );
			if ( is_wp_error( $remote ) ) {
				wp_send_json_error( array( 'message' => $remote->get_error_message() ), 422 );
			}
			if ( empty( $remote['shop'] ) || ( class_exists( 'TCAS_Partners' ) ? $partner_id : 'ariocase' ) !== $remote['shop'] || empty( $remote['type'] ) || 'variable' !== $remote['type'] || empty( $remote['variations'] ) ) {
				wp_send_json_error( array( 'message' => 'مرجع ' . ( class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase' ) . ' باید یک محصول متغیر با Variation باشد.' ), 422 );
			}
			$remote_variations = $remote['variations'];
			foreach ( $remote_variations as $index => $variation ) {
				$parent_id = isset( $variation['parent_id'] ) ? absint( $variation['parent_id'] ) : 0;
				$remote_id = isset( $variation['product_id'] ) ? absint( $variation['product_id'] ) : 0;
				if ( $parent_id && $remote_id ) {
					$remote_variations[ $index ]['reference'] = 'ID:' . $parent_id . '/' . $remote_id;
				}
			}
			$result = TCAS_Matcher::match( $local['variations'], $remote_variations );
			// Variationهایی که از قبل به همین مرجع وصل‌اند علامت می‌خورند تا کارتشان
			// دوباره بررسی/ارسال نشود؛ این کار فقط نگاشت داخلی را می‌خواند.
			$connected = 0;
			$skipped   = array();
			if ( class_exists( 'TCAS_Pairing' ) && method_exists( 'TCAS_Pairing', 'annotate' ) ) {
				$annotated         = TCAS_Pairing::annotate( $result['matches'], $partner_id );
				$result['matches'] = $annotated['matches'];
				$connected         = (int) $annotated['connected'];
				// مرجع‌هایی که به Variation دیگری وصل‌اند کارت نمی‌سازند تا خطای
				// «مرجع تکراری» به‌ازای هر ردیف روی صفحه نریزد.
				foreach ( $annotated['taken'] as $taken ) {
					$skipped[] = array(
						'local_code'   => isset( $taken['local_code'] ) ? (string) $taken['local_code'] : '',
						'local_label'  => isset( $taken['local_label'] ) ? (string) $taken['local_label'] : '',
						'remote_code'  => isset( $taken['remote_code'] ) ? (string) $taken['remote_code'] : '',
						'remote_label' => isset( $taken['remote_label'] ) ? (string) $taken['remote_label'] : '',
						'taken_by'     => isset( $taken['taken_by'] ) ? (int) $taken['taken_by'] : 0,
						'taken_by_name'=> isset( $taken['taken_by_name'] ) ? (string) $taken['taken_by_name'] : '',
					);
				}
			}
			$summary                   = $result['summary'];
			$summary['connected']      = $connected;
			$summary['skipped']        = count( $skipped );
			wp_send_json_success( array(
				'matches'   => $result['matches'],
				'ambiguous' => $result['ambiguous'],
				'unmatched' => $result['unmatched'],
				'summary'   => $summary,
				'connected' => $connected,
				'skipped'   => $skipped,
				'local'     => array( 'name' => $local['name'], 'reference' => $local['reference'], 'count' => count( $local['variations'] ) ),
				'remote'    => array( 'name' => $remote['name'], 'reference' => $remote['reference'], 'count' => count( $remote['variations'] ) ),
			) );
		}

		/**
		 * بررسی یک جفت به‌صورت مستقل؛ برای به‌روزرسانی زندهٔ همان کارت پس از ویرایش
		 * استفاده می‌شود و هیچ Job یا نگاشتی نمی‌سازد.
		 */
		public static function preview_pair() {
			self::auth();
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase';
			$configured = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::is_configured( $partner_id ) : TCAS_Core::is_configured();
			if ( ! $configured ) {
				wp_send_json_error( array( 'message' => 'ابتدا تنظیمات اتصال همکار انتخاب‌شده را کامل کنید.' ), 422 );
			}
			$local  = self::posted_reference( 'local_code' );
			$remote = self::posted_reference( 'remote_code' );
			$limit  = 2048;
			if ( '' === $local || '' === $remote || strlen( $local ) > $limit || strlen( $remote ) > $limit ) {
				wp_send_json_error( array( 'message' => 'شناسهٔ هر دو فروشگاه را وارد کنید.' ), 422 );
			}
			$preview = TCAS_Pairing::preview(
				array(
					'local_code'    => $local,
					'remote_code'   => $remote,
					'error'         => '',
					'shared_remote' => isset( $_POST['shared_remote'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['shared_remote'] ) ),
					'reclaim'       => isset( $_POST['reclaim'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['reclaim'] ) ),
				),
				$partner_id
			);
			wp_send_json_success( array( 'row' => $preview['row'] ) );
		}

		public static function test_connection() {
			self::auth();
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase';
			$code = self::posted_reference( 'code' );
			if ( '' === $code ) {
				wp_send_json_error( array( 'message' => 'برای تست، لینک، SKU یا ID محصول همکار را وارد کنید.' ), 400 );
			}
			$product = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::fetch_reference( $partner_id, $code ) : TCAS_Api::fetch_reference( $code );
			$label   = preg_match( '#^https?://#i', $code ) ? '[' . ( class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase' ) . ' URL]' : $code;
			if ( is_wp_error( $product ) ) {
				TCAS_Logger::add( 'connection_error', $label, $product->get_error_message() );
				wp_send_json_error( array( 'message' => $product->get_error_message() ), 400 );
			}
			$reference = ! empty( $product['code'] ) ? (string) $product['code'] : ( ! empty( $product['remote_id'] ) ? 'ID:' . absint( $product['remote_id'] ) : '' );
			TCAS_Logger::add( 'connection_ok', $label, 'اتصال و تشخیص ساختار پاسخ موفق بود.' );
			wp_send_json_success( array(
				'message' => 'اتصال موفق بود و ساختار پاسخ شناسایی شد.',
				'product' => array(
					'code'       => $product['code'],
					'reference'  => $reference,
					'product_id' => absint( $product['remote_id'] ),
					'name'       => $product['name'],
					'type'       => $product['type'],
					'stock'      => $product['stock'],
					'variations' => count( $product['variations'] ),
				),
			) );
		}

		public static function add_mapping() {
			self::auth();
			$pair = array(
				'local_code'  => isset( $_POST['sku'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sku'] ) ) ) : '',
				'remote_code' => self::posted_reference( 'remote_code' ),
				'error'       => '',
			);
			$result = TCAS_Pairing::connect( TCAS_Pairing::preview( $pair, class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase' ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}
			TCAS_Logger::add( 'pair_add', self::pair_label( $pair, class_exists( 'TCAS_Partners' ) ? TCAS_Partners::selected_id() : 'ariocase' ), 'اتصال ساخته و فقط موجودی به‌روز شد.' );
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

		/** اجرای دستی جارو؛ دفعهٔ اول فقط چند کد تا پاسخ سریع بماند، ادامه در پس‌زمینه. */
		public static function sweep_now() {
			self::auth();
			if ( ! class_exists( 'TCAS_Sweeper' ) ) {
				wp_send_json_error( array( 'message' => 'موتور همگام‌سازی پس‌زمینه در دسترس نیست.' ), 500 );
			}
			$result = TCAS_Sweeper::run( 'manual', 8, true );
			$data   = array(
				'message'   => (string) $result['message'],
				'processed' => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
				'changed'   => isset( $result['changed'] ) ? (int) $result['changed'] : 0,
				'errors'    => isset( $result['errors'] ) ? (int) $result['errors'] : 0,
				'backlog'   => isset( $result['backlog'] ) ? (int) $result['backlog'] : 0,
				'reload'    => false,
			);
			if ( empty( $result['ok'] ) && empty( $result['processed'] ) ) {
				wp_send_json_error( $data, 409 );
			}
			wp_send_json_success( $data );
		}

		public static function clear_logs() {
			self::auth();
			TCAS_Logger::clear();
			wp_send_json_success( array( 'message' => 'گزارش‌ها پاک شدند.', 'reload' => true ) );
		}
	}
}
