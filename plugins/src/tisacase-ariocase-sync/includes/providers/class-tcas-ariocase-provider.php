<?php
/** ArioCase implementation of the generic partner contract.
 *
 * @package TisaCase_Inventory_Sync
 */

defined( 'ABSPATH' ) || exit;

final class TCAS_ArioCase_Provider implements TCAS_Partner_Provider {
	public function id() { return 'ariocase'; }
	public function label() { return 'ArioCase'; }
	public function defaults() { return TCAS_Core::defaults(); }
	public function settings_fields() { return array(); }
	public function sanitize_settings( $input, $current ) { return TCAS_Core::sanitize_settings( $input, $current ); }
	public function is_configured( $settings ) { return TCAS_Core::is_configured( $settings ); }
	public function redact( $message, $settings ) { return TCAS_Api::redact( $message, $settings ); }

	public function fetch_reference( $reference, $settings ) {
		return TCAS_Core::with_settings( $settings, function() use ( $reference ) {
			return TCAS_Api::fetch_reference( $reference );
		} );
	}

	public function fetch_stock( $reference, $identifiers, $settings ) {
		return TCAS_Core::with_settings( $settings, function() use ( $reference, $identifiers ) {
			return TCAS_Api::fetch_stock( $reference, $identifiers );
		} );
	}

	public function resolve_link( $reference, $settings ) {
		return TCAS_Core::with_settings( $settings, function() use ( $reference ) {
			return TCAS_Link_Resolver::resolve_remote_reference( $reference );
		} );
	}
}
