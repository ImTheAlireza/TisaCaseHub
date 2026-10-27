<?php
/** Provider registry, isolated settings, normalized dispatch, and error redaction. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_args( $args, $defaults ) { return array_merge( (array) $defaults, (array) $args ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['options'][ $key ] = $value; return true; }
function apply_filters( $hook, $value ) { return 'tcas_partner_providers' === $hook ? array_merge( $value, $GLOBALS['custom_providers'] ) : $value; }
class TCAS_Api {
	public static $seen_settings = array();
	public static function fetch_reference( $reference ) {
		self::$seen_settings = TCAS_Core::settings();
		return array( 'code' => $reference, 'remote_id' => 8, 'parent_remote_id' => 0, 'name' => 'Ario item', 'type' => 'simple', 'stock' => array( 'quantity' => 2, 'status' => 'instock' ), 'variations' => array() );
	}
	public static function fetch_stock( $reference, $identifiers ) { self::$seen_settings = TCAS_Core::settings(); return array( 'quantity' => 2, 'status' => 'instock' ); }
	public static function redact( $message, $settings = null ) { return str_replace( isset( $settings['api_key'] ) ? $settings['api_key'] : '', '[API-KEY]', (string) $message ); }
}
class TCAS_Link_Resolver { public static function resolve_remote_reference( $reference ) { return array( 'product_id' => 8, 'reference' => $reference, 'name' => 'Ario variable', 'type' => 'variable', 'variations' => array() ); } }
$GLOBALS['options'] = array();
$GLOBALS['custom_providers'] = array();
require_once dirname( __DIR__ ) . '/includes/class-tcas-core.php';
require_once dirname( __DIR__ ) . '/includes/interface-tcas-partner-provider.php';
require_once dirname( __DIR__ ) . '/includes/providers/class-tcas-ariocase-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-partners.php';

class TCAS_Test_Partner implements TCAS_Partner_Provider {
	public function id() { return 'sample_vendor'; }
	public function label() { return 'Sample Vendor'; }
	public function defaults() { return array( 'endpoint' => 'https://sample.invalid/api', 'token' => '' ); }
	public function settings_fields() { return array( 'endpoint' => array( 'type' => 'url', 'label' => 'Endpoint' ), 'token' => array( 'type' => 'password', 'label' => 'Token' ) ); }
	public function sanitize_settings( $input, $current ) {
		$input = (array) $input;
		return array(
			'endpoint' => isset( $input['endpoint'] ) ? esc_url_raw( $input['endpoint'] ) : $current['endpoint'],
			'token' => ! empty( $input['clear_token'] ) ? '' : ( ! empty( $input['token'] ) ? sanitize_text_field( $input['token'] ) : $current['token'] ),
		);
	}
	public function is_configured( $settings ) { return ! empty( $settings['endpoint'] ) && ! empty( $settings['token'] ); }
	public function redact( $message, $settings ) { return str_replace( $settings['token'], '[TOKEN]', (string) $message ); }
	public function fetch_reference( $reference, $settings ) {
		return 'FAIL' === $reference ? new WP_Error( 'sample_error', 'bad token=' . $settings['token'] ) : array(
			'code' => $reference, 'remote_id' => 17, 'parent_remote_id' => 0, 'name' => 'Sample item', 'type' => 'simple',
			'stock' => array( 'quantity' => 6, 'status' => 'instock' ), 'variations' => array(),
		);
	}
	public function fetch_stock( $reference, $identifiers, $settings ) { return array( 'quantity' => 6, 'status' => 'instock' ); }
	public function resolve_link( $reference, $settings ) { return array( 'product_id' => 17, 'name' => 'Sample variable', 'type' => 'variable', 'variations' => array() ); }
}
function provider_test( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "OK   {$label}\n"; }
	else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; }
}
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ] = array_merge( TCAS_Core::defaults(), array( 'api_key' => 'legacy-secret', 'active_partner_id' => 'ariocase' ) );
$GLOBALS['custom_providers']['sample_vendor'] = new TCAS_Test_Partner();
$pass = 0; $fail = 0;

provider_test( 'ArioCase remains a registered first-party provider', TCAS_Partners::get( 'ariocase' ) instanceof TCAS_Partner_Provider && 'ArioCase' === TCAS_Partners::label( 'ariocase' ) );
provider_test( 'third-party provider is discoverable with its own label', TCAS_Partners::get( 'sample_vendor' ) instanceof TCAS_Partner_Provider && 'Sample Vendor' === TCAS_Partners::label( 'sample_vendor' ) );
provider_test( 'legacy ArioCase credentials are still read from the historic option', 'legacy-secret' === TCAS_Partners::settings( 'ariocase' )['api_key'] );
$ario_settings = array_merge( TCAS_Core::defaults(), array( 'base_url' => 'https://ario.provider.test/api', 'product_endpoint' => 'items/{code}', 'api_key' => 'legacy-secret' ) );
$ario_result = ( new TCAS_ArioCase_Provider() )->fetch_reference( 'AR-1', $ario_settings );
provider_test( 'ArioCase API helpers receive provider-scoped settings and restore the prior context', is_array( $ario_result ) && 'https://ario.provider.test/api' === TCAS_Api::$seen_settings['base_url'] && 'legacy-secret' === TCAS_Api::$seen_settings['api_key'] && 'legacy-secret' === TCAS_Core::settings()['api_key'] );

$saved = TCAS_Partners::save_settings( 'sample_vendor', array( 'endpoint' => 'https://vendor.example/v2', 'token' => 'vendor-secret', 'enabled' => '1', 'live_scope' => 'purchase' ) );
$sample_settings = TCAS_Partners::settings( 'sample_vendor' );
provider_test( 'provider settings are stored separately and secret survives blank edits', true === $saved && 'https://vendor.example/v2' === $sample_settings['endpoint'] && 'vendor-secret' === $sample_settings['token'] );
provider_test( 'saving another provider leaves historic ArioCase API key untouched', 'legacy-secret' === TCAS_Partners::settings( 'ariocase' )['api_key'] );

$product = TCAS_Partners::fetch_reference( 'sample_vendor', 'V-42' );
provider_test( 'provider dispatch supplies normalized product data and provider identity', is_array( $product ) && 'V-42' === $product['code'] && 'sample_vendor' === $product['partner_id'] && 'Sample Vendor' === $product['shop_label'] );
$stock = TCAS_Partners::fetch_stock( 'sample_vendor', 'V-42', array() );
provider_test( 'provider stock dispatch validates the normalized stock contract', is_array( $stock ) && 6 === $stock['quantity'] && 'instock' === $stock['status'] );
$error = TCAS_Partners::fetch_reference( 'sample_vendor', 'FAIL' );
provider_test( 'provider API errors are credential-redacted before leaving dispatcher', is_wp_error( $error ) && false === strpos( $error->get_error_message(), 'vendor-secret' ) && false !== strpos( $error->get_error_message(), '[TOKEN]' ) );
provider_test( 'invalid provider IDs never route to a different integration', null === TCAS_Partners::get( 'missing_provider' ) && 'missing_provider' === TCAS_Partners::normalize_id( 'missing_provider' ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail && ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( 1 ); }
