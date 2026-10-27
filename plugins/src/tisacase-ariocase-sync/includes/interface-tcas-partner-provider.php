<?php
/** Contract for inventory partner integrations.
 *
 * @package TisaCase_Inventory_Sync
 */

defined( 'ABSPATH' ) || exit;

interface TCAS_Partner_Provider {
	/** Stable, lowercase identifier; do not change after release. */
	public function id();
	/** Human-readable partner name. */
	public function label();
	/** Provider-specific defaults; common stock settings are supplied by the host plugin. */
	public function defaults();
	/** Optional generic admin fields: key => label/type/placeholder/required/options metadata. */
	public function settings_fields();
	/** Sanitize provider-specific settings, preserving secrets when omitted. */
	public function sanitize_settings( $input, $current );
	/** Whether this provider has enough configuration to make requests. */
	public function is_configured( $settings );
	/** Remove credentials from any diagnostic string before it reaches logs or the UI. */
	public function redact( $message, $settings );
	/** Resolve an SKU, ID, or provider-supported URL to the normalized product contract. */
	public function fetch_reference( $reference, $settings );
	/** Fetch and normalize stock to ['quantity' => number|null, 'status' => string, ...]. */
	public function fetch_stock( $reference, $identifiers, $settings );
	/** Return an optional variable product resolution for variation matching, or WP_Error. */
	public function resolve_link( $reference, $settings );
}
