<?php
/** Financial records are intentionally retained; uninstall is not consent to erase evidence. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'tcc_maintenance' );
if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'tcc_notify', null, 'tisacase-card-to-card' ); }
// Keep settings, reviewer assignments, tables, files and order metadata for a safe reinstall.
// Revoke runtime review capabilities, preserving the list for administrator reconciliation.
require_once __DIR__ . '/includes/class-tcc-permissions.php';
TCC_Permissions::uninstall();
