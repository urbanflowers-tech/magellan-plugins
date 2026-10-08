<?php
/**
 * Uninstall handler. Runs when the plugin is deleted, not deactivated.
 * Removes plugin options. Order meta (_mgln_*) is preserved so reinstalling
 * the plugin keeps historical attribution.
 *
 * @package Magellan
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = [
	'magellan_account_id',
	'magellan_signing_secret',
	'magellan_configured_at',
	'magellan_last_event_sent',
	'magellan_last_error',
	'magellan_health_data',
	'magellan_counters',
];

foreach ( $options as $opt ) {
	delete_option( $opt );
	if ( is_multisite() ) {
		delete_site_option( $opt );
	}
}

// Clear scheduled events
wp_clear_scheduled_hook( 'magellan_historical_identity_sync' );
wp_clear_scheduled_hook( 'magellan_daily_cleanup' );
wp_clear_scheduled_hook( 'magellan_sync_check' );
wp_clear_scheduled_hook( 'magellan_health_check' );

// Remove credentials on deletion. Preserve v3 outbox evidence for explicit recovery.
delete_option('magellan_v3_config');
wp_clear_scheduled_hook('magellan_v3_drain');
wp_clear_scheduled_hook('magellan_v3_maintenance');
if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('magellan_v3_drain', [], 'magellan-v3'); }
