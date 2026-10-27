<?php
/**
 * مسیر پشتیبان REST برای همان actionهای AJAX.
 *
 * چرا؟ بعضی هاست‌های LiteSpeed/Cloudflare درخواست‌های POST به `admin-ajax.php` را
 * با صفحهٔ اختصاصی 404/418 مسدود می‌کنند، درحالی‌که `/wp-json/` همان سایت باز است.
 * این کلاس همان handlerها را از مسیر REST هم در دسترس می‌گذارد تا رابط در صورت
 * مسدودشدن admin-ajax به‌طور خودکار از این مسیر استفاده کند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Rest' ) ) {

	final class TCAS_Rest {
		const NAMESPACE_V1 = 'tcas/v1';

		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		}

		public static function routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/do/(?P<action>[a-z_]+)',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'dispatch' ),
					'permission_callback' => array( __CLASS__, 'can' ),
					'args'                => array(
						'action' => array( 'required' => true ),
					),
				)
			);
		}

		/** فقط کاربری که اجازهٔ صفحهٔ افزونه را دارد. */
		public static function can() {
			return class_exists( 'TCAS_Core' ) && TCAS_Core::can();
		}

		/** actionهای مجاز؛ همان فهرست AJAX. */
		public static function allowed() {
			return array( 'preview_start', 'preview_step', 'connect_start', 'connect_step', 'test_connection', 'resolve_link', 'search_products', 'match_variations', 'preview_pair', 'search_index', 'add_mapping', 'sync_mapping', 'disconnect_mapping', 'clear_logs', 'sweep_now' );
		}

		/**
		 * پارامترهای REST را در $_POST/$_REQUEST می‌گذارد و همان handler AJAX را صدا می‌زند.
		 * handler با wp_send_json_* پاسخ می‌دهد؛ همان بدنهٔ `{success, data}` که رابط می‌خواند.
		 *
		 * @param WP_REST_Request $request درخواست.
		 * @return WP_Error|null
		 */
		public static function dispatch( $request ) {
			$action = isset( $request['action'] ) ? sanitize_key( (string) $request['action'] ) : '';
			if ( ! in_array( $action, self::allowed(), true ) ) {
				return new WP_Error( 'tcas_rest_action', 'این عملیات از مسیر REST مجاز نیست.', array( 'status' => 404 ) );
			}
			if ( ! class_exists( 'TCAS_Ajax' ) || ! method_exists( 'TCAS_Ajax', $action ) ) {
				return new WP_Error( 'tcas_rest_handler', 'پردازندهٔ این عملیات در دسترس نیست.', array( 'status' => 501 ) );
			}
			$params = $request->get_params();
			if ( is_array( $params ) ) {
				foreach ( $params as $key => $value ) {
					if ( 'action' === $key ) {
						continue;
					}
					$_POST[ $key ]    = $value;
					$_REQUEST[ $key ] = $value;
				}
			}
			// nonce افزونه همان‌جوری که در مسیر AJAX بررسی می‌شود، از پارامتر یا هدر خوانده می‌شود.
			if ( empty( $_REQUEST['nonce'] ) ) {
				$header = $request->get_header( 'x_tcas_nonce' );
				if ( $header ) {
					$_REQUEST['nonce'] = $header;
				}
			}
			call_user_func( array( 'TCAS_Ajax', $action ) );
			return null;
		}
	}
}
