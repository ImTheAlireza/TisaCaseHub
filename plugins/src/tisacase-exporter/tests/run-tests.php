<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
class WP_Error { private $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function apply_filters( $name, $value ) {
  $map = array(
    'tisacase_exporter_dedup_chunk_lines' => 100,
    'tisacase_exporter_dedup_merge_rows' => 100,
    'tisacase_exporter_dedup_output_rows' => 100,
    'tisacase_exporter_dedup_fan_in' => 2,
    'tisacase_exporter_dedup_chunk_bytes' => 1048576,
    'tisacase_exporter_dedup_min_free_bytes' => 8388608,
    'tisacase_exporter_dedup_max_line_bytes' => 65536,
  );
  return isset( $map[ $name ] ) ? $map[ $name ] : $value;
}
class TisaCase_Exporter {
  const PART_PREFIX = 'part-'; const WORKING_FILE = 'working.tsv'; const WRITE_BUF = 65536; const TEXT_DOMAIN = 'tests';
  public static function file_size() { return 50; }
}
class TisaCase_Exporter_Session { public static function lock_refresh( $run_id ) { return true; } }
class TisaCase_Exporter_Storage {
  public static function close_all( $handles ) { foreach ( $handles as $handle ) { if ( is_resource( $handle ) ) { fclose( $handle ); } } }
  public static function delete_files( $files ) { foreach ( $files as $file ) { if ( is_file( $file ) ) { unlink( $file ); } } }
}
class TisaCase_Exporter_Format { public static function ext( $format ) { return 'csv'; } }
require '/plugin/includes/class-tce-pipeline.php';

$passed = 0; $failed = 0;
function check( $label, $condition ) { global $passed, $failed; if ( $condition ) { $passed++; echo "PASS $label\n"; } else { $failed++; echo "FAIL $label\n"; } }
$dir = '/tmp/tce-dedup-test';
if ( is_dir( $dir ) ) { foreach ( glob( $dir . '/*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } } } else { mkdir( $dir, 0777, true ); }
$input = array(); $expected = array();
for ( $i = 0; $i < 220; $i++ ) {
  $key = sprintf( 'SKU%03d', $i % 170 );
  $row = sprintf( 'row-%03d', $i );
  $input[] = TisaCase_Exporter_Pipeline::make_line( $key, $row );
  if ( ! isset( $expected[ $key ] ) || strcmp( $row, $expected[ $key ] ) < 0 ) { $expected[ $key ] = $row; }
}
$expected = array_values( $expected ); sort( $expected, SORT_STRING );
file_put_contents( $dir . '/working.tsv', implode( "\n", $input ) . "\n" );
$state = array(
  'dir' => $dir, 'run_id' => 'test-run', 'dedup' => 'sku', 'exported' => count( $input ), 'source_exported' => count( $input ),
  'working_bytes' => filesize( $dir . '/working.tsv' ), 'working_count' => count( $input ), 'files' => array(),
  'current_file' => 1, 'current_count' => 0, 'current_bytes' => 0, 'module' => 'products', 'format' => 'csv', 'started_at' => time(),
);
$steps = 0; $result = false; $replayed = false; $output_replayed = false;
while ( false === $result && $steps < 200 ) {
  TisaCase_Exporter_Pipeline::reconcile_checkpoint( $state );
  $before = serialize( $state );
  $result = TisaCase_Exporter_Pipeline::finalize_dedup( $state );
  if ( is_wp_error( $result ) ) { echo 'ERROR ' . $result->get_error_message() . "\n"; break; }
  // Simulate a lost AJAX response after a merge append: restore the prior transient
  // while leaving the appended temp file in place, then safely replay that request.
  $prior = unserialize( $before );
  if ( ! $replayed && isset( $prior['dedup_finalize']['phase'], $prior['dedup_finalize']['job']['output_bytes'] ) && 'merge' === $prior['dedup_finalize']['phase'] && $prior['dedup_finalize']['job']['output_bytes'] > 0 && false === $result ) {
    $state = $prior;
    $result = TisaCase_Exporter_Pipeline::finalize_dedup( $state );
    $replayed = ! is_wp_error( $result );
  }
  if ( ! $output_replayed && isset( $prior['dedup_finalize']['phase'] ) && 'output' === $prior['dedup_finalize']['phase'] && false === $result && $state['dedup_finalize']['output_offset'] > $prior['dedup_finalize']['output_offset'] ) {
    $state = $prior;
    TisaCase_Exporter_Pipeline::reconcile_checkpoint( $state );
    $result = TisaCase_Exporter_Pipeline::finalize_dedup( $state );
    $output_replayed = ! is_wp_error( $result );
  }
  // Simulate state persistence between independent AJAX requests.
  $state = unserialize( serialize( $state ) );
  $steps++;
}
check( 'dedup completes in resumable bounded steps', true === $result && $steps > 1 && $steps < 200 );
check( 'merge append can be replayed after a lost state response', $replayed );
check( 'final output part append can be replayed from its checkpoint', $output_replayed );
$actual = array();
foreach ( $state['files'] as $file ) {
  $contents = file_get_contents( $dir . '/' . $file['internal'] );
  foreach ( preg_split( '/\r?\n/', trim( $contents ) ) as $line ) { if ( '' !== $line ) { $actual[] = $line; } }
}
check( 'external sort emits exactly one row per unique key', $actual === $expected );
check( 'duplicate count matches the source minus unique keys', (int) $state['duplicates'] === count( $input ) - count( $expected ) );
check( 'final output is split into bounded parts', count( $state['files'] ) > 1 && (int) $state['exported'] === count( $expected ) );
TisaCase_Exporter_Pipeline::cleanup_working( $state );
$remaining = glob( $dir . '/dedup-*' );
check( 'temporary sort/merge runs are cleaned after completion', empty( $remaining ) );
foreach ( glob( $dir . '/*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
file_put_contents( $dir . '/working.tsv', 'SKU-LARGE' . "\t" . str_repeat( 'x', 70000 ) . "\n" );
$large_state = array( 'dir' => $dir, 'run_id' => 'large-row', 'dedup' => 'sku', 'exported' => 1, 'source_exported' => 1, 'working_bytes' => filesize( $dir . '/working.tsv' ), 'files' => array() );
$large_result = TisaCase_Exporter_Pipeline::finalize_dedup( $large_state );
check( 'oversized single rows fail clearly instead of expanding memory without bound', is_wp_error( $large_result ) && false !== strpos( $large_result->get_error_message(), 'ردیف خروجی' ) );
foreach ( glob( $dir . '/*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
rmdir( $dir );
echo "Result: $passed passed, $failed failed\n";
