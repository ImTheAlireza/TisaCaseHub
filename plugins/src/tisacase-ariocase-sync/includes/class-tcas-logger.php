<?php
/** @package TisaCase_ArioCase_Sync */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Logger' ) ) {
	final class TCAS_Logger {
		const MAX = 100;

		public static function add( $event, $code, $message ) {
			$logs   = get_option( TCAS_Core::OPTION_LOGS, array() );
			$logs   = is_array( $logs ) ? $logs : array();
			$logs[] = array(
				'time'    => time(),
				'user_id' => get_current_user_id(),
				'event'   => sanitize_key( $event ),
				'code'    => sanitize_text_field( $code ),
				'message' => TCAS_Api::redact( sanitize_text_field( $message ) ),
			);
			if ( count( $logs ) > self::MAX ) {
				$logs = array_slice( $logs, -self::MAX );
			}
			update_option( TCAS_Core::OPTION_LOGS, $logs, false );
		}

		public static function all() {
			$logs = get_option( TCAS_Core::OPTION_LOGS, array() );
			return is_array( $logs ) ? array_reverse( $logs ) : array();
		}

		public static function clear() {
			return update_option( TCAS_Core::OPTION_LOGS, array(), false );
		}
	}
}
