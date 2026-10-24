<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' );
$root = dirname( __DIR__ );
foreach ( array( 'helpers', 'settings', 'repository', 'storage', 'access' ) as $part ) { require_once $root . '/includes/class-tcc-' . $part . '.php'; }
require_once __DIR__ . '/stubs.php';
$pass = 0; $fail = 0;
function t( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function rejects( $name, $fn ) { try { $fn(); t( $name, false ); } catch ( RuntimeException $e ) { t( $name, true ); } }
$mode = $argv[1] ?? 'auto';
$workspace = getenv( 'TCC_TEST_OUTPUT' ); if ( ! $workspace ) { throw new RuntimeException( 'TCC_TEST_OUTPUT must point to an ignored local test directory.' ); }
$parent = $workspace . '/storage-' . $mode; wp_mkdir_p( $parent . '/public' ); $_SERVER['DOCUMENT_ROOT'] = realpath( $parent . '/public' );
$storage = new TCC_Storage();
if ( 'unsafe' === $mode ) {
    define( 'TCC_PRIVATE_DIR', $_SERVER['DOCUMENT_ROOT'] . '/uploads/receipts' );
    rejects( 'Explicit public storage is refused', function () use ( $storage ) { $storage->base(); } );
    t( 'Public folder not created as a fallback', ! is_dir( TCC_PRIVATE_DIR ) );
} elseif ( 'symlink' === $mode ) {
    wp_mkdir_p( $parent . '/public/target' ); $link = $parent . '/alias';
    if ( ! is_link( $link ) ) { symlink( realpath( $parent . '/public/target' ), $link ); }
    define( 'TCC_PRIVATE_DIR', $link );
    rejects( 'Symlink into public root is refused', function () use ( $storage ) { $storage->base(); } );
} elseif ( 'missing' === $mode ) {
    $_SERVER['DOCUMENT_ROOT'] = '';
    rejects( 'Unknown public root does not fall back to uploads or system tmp', function () use ( $storage ) { $storage->base(); } );
} else {
    $base = $storage->base();
    t( 'Auto storage created outside actual public root', is_dir( $base ) && ! TCC_Storage::within( $base, $_SERVER['DOCUMENT_ROOT'] ) );
    t( 'Public filesystem root cannot be bypassed by trimming slashes', TCC_Storage::within( '/any/private/file', '/' ) );
    $key = str_repeat( 'a', 48 ) . '.png'; file_put_contents( $base . '/' . $key, 'synthetic fixture only' );
    t( 'Receipt lookup confined to private directory', $storage->path( $key ) === realpath( $base . '/' . $key ) );
    rejects( 'Non-HTTP local file cannot masquerade as an uploaded receipt', function () use ( $storage, $base, $key ) { $storage->receive( array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $base . '/' . $key, 'name' => 'receipt.png' ) ); } );
    rejects( 'Partial PHP upload error refused', function () use ( $storage ) { $storage->receive( array( 'error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '', 'name' => 'receipt.png' ) ); } );
    $outside = $parent . '/outside.png'; file_put_contents( $outside, 'outside' ); $alias = str_repeat( 'b', 48 ) . '.png';
    if ( ! is_link( $base . '/' . $alias ) ) { symlink( realpath( $outside ), $base . '/' . $alias ); }
    rejects( 'Receipt filename symlink cannot escape private directory', function () use ( $storage, $alias ) { $storage->path( $alias ); } );
    $storage->discard( $key ); t( 'Explicit cleanup removes only a validated private file', ! is_file( $base . '/' . $key ) && is_file( $outside ) );
}
echo "\nResult: $pass storage checks passed, $fail failed ($mode)\n"; exit( $fail ? 1 : 0 );
