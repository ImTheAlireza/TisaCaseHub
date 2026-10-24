<?php
/** Financial records are intentionally retained; uninstall is not consent to erase evidence. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'tcc_maintenance' );
if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( 'tcc_notify', null, 'tisacase-card-to-card' ); }
// Keep settings, reviewer assignments, tables, files and order metadata for a safe reinstall.
// Revoke runtime review capabilities, preserving the list for administrator reconciliation.
foreach ( (array) get_option( 'tcc_reviewers', array() ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $user->remove_cap( 'tcc_review_payments' ); } }
$role = get_role( 'administrator' ); if ( $role ) { $role->remove_cap( 'tcc_review_payments' ); }
