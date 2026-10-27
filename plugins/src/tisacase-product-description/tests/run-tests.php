<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
$GLOBALS['registered_actions'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['registered_actions'][] = $hook; }
function apply_filters( $hook, $value ) { return 'tisacase_desc_ajax_budget_seconds' === $hook ? 99 : $value; }
$GLOBALS['test_options'] = array();
function get_option( $key, $default = false ) { return isset( $GLOBALS['test_options'][ $key ] ) ? $GLOBALS['test_options'][ $key ] : $default; }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
require '/plugin/includes/class-tisacase-desc-core.php';
require '/plugin/includes/class-tisacase-desc-admin.php';
$passed = 0; $failed = 0;
function check( $label, $condition ) { global $passed, $failed; if ( $condition ) { $passed++; echo "PASS $label\n"; } else { $failed++; echo "FAIL $label\n"; } }
TisaCase_Desc_Core::init();
check( 'product sync uses one canonical WooCommerce save hook', in_array( 'woocommerce_after_product_object_save', $GLOBALS['registered_actions'], true ) );
check( 'duplicate per-save hooks were removed', ! in_array( 'save_post_product', $GLOBALS['registered_actions'], true ) && ! in_array( 'woocommerce_new_product', $GLOBALS['registered_actions'], true ) && ! in_array( 'woocommerce_update_product', $GLOBALS['registered_actions'], true ) );
$batch = new ReflectionMethod( 'TisaCase_Desc_Admin', 'bulk_batch_size' ); $batch->setAccessible( true );
check( 'bulk batch ceiling is 100', 100 === $batch->invoke( null, array( 'batch_size' => 5000 ) ) );
check( 'bulk batch allows smaller host-safe settings', 7 === $batch->invoke( null, array( 'batch_size' => 7 ) ) );
$budget = new ReflectionMethod( 'TisaCase_Desc_Admin', 'ajax_budget_seconds' ); $budget->setAccessible( true );
check( 'AJAX budget has a hard 10 second ceiling', 10 === $budget->invoke( null ) );
check( 'default product batch is conservative', 50 === TisaCase_Desc_Core::defaults()['batch_size'] );
$GLOBALS['test_options'][ TisaCase_Desc_Core::OPTION ] = array( 'batch_size' => 5000 );
check( 'legacy stored batch values are clamped when read', 100 === TisaCase_Desc_Core::get_options()['batch_size'] );
echo "Result: $passed passed, $failed failed\n";
