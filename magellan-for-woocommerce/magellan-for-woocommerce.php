<?php
/**
 * Plugin Name:       Magellan for WooCommerce
 * Plugin URI:        https://magellan.app
 * Description:       Consent-controlled website measurement and durable WooCommerce evidence delivery to Magellan.
 * Version:           3.0.0-alpha.4
 * Author:            Magellan
 * Author URI:        https://magellan.app
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       magellan-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.2.0
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------

define( 'MAGELLAN_VERSION',            '3.0.0-alpha.4' );
define( 'MAGELLAN_PLUGIN_FILE',        __FILE__ );
define( 'MAGELLAN_PLUGIN_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MAGELLAN_PLUGIN_URL',         plugin_dir_url( __FILE__ ) );

// Effective API base — resolved in priority order:
//   1. Stored option `magellan_api_base` — the base the BACKEND pushes at
//      provision time (Path A configure callback / Path B bootstrap). This
//      is AUTHORITATIVE: a store sends to whichever backend installed it, so
//      a dev install self-configures to the dev backend and a production
//      install to production, with no wp-config edit. This is the
//      "configures itself from the provisioning backend" contract.
//   2. `MAGELLAN_API_BASE` define()d in wp-config.php — a pre-provisioning
//      bootstrap target / manual fallback, used ONLY when no base has been
//      provisioned yet (e.g. to aim a fresh install's /bootstrap call at a
//      specific backend).
//   3. Default `https://api.magellan.app/v1/pixel`.
//
// NOTE (precedence change in 2.4.2): the provisioned option now wins over
// the wp-config constant. Previously the constant was highest, which could
// pin a dev-provisioned store to production if a stale constant was present.
// Trailing slash always stripped so concatenation with /event etc. stays
// clean. Keep in sync with Magellan_Admin::get_api_base().
if ( ! defined( 'MAGELLAN_API_BASE_EFFECTIVE' ) ) {
	$mgln_api_opt = get_option( 'magellan_api_base', '' );
	if ( is_string( $mgln_api_opt ) && $mgln_api_opt !== '' ) {
		$mgln_api_b = rtrim( $mgln_api_opt, '/' );
	} elseif ( defined( 'MAGELLAN_API_BASE' ) && is_string( MAGELLAN_API_BASE ) && MAGELLAN_API_BASE !== '' ) {
		$mgln_api_b = rtrim( (string) MAGELLAN_API_BASE, '/' );
	} else {
		$mgln_api_b = 'https://api.magellan.app/v1/pixel';
	}
	define( 'MAGELLAN_API_BASE_EFFECTIVE', $mgln_api_b );
	unset( $mgln_api_opt, $mgln_api_b );
}

define( 'MAGELLAN_ENDPOINT_EVENT',     MAGELLAN_API_BASE_EFFECTIVE . '/event' );
define( 'MAGELLAN_ENDPOINT_CART',      MAGELLAN_API_BASE_EFFECTIVE . '/cart-email' );
define( 'MAGELLAN_ENDPOINT_IDENTS',    MAGELLAN_API_BASE_EFFECTIVE . '/identities' );
define( 'MAGELLAN_ENDPOINT_HEALTH',    MAGELLAN_API_BASE_EFFECTIVE . '/health' );
define( 'MAGELLAN_ENDPOINT_BOOTSTRAP', MAGELLAN_API_BASE_EFFECTIVE . '/bootstrap' );

// ---------------------------------------------------------------------
// HPOS compatibility declaration (must run before WooCommerce inits)
// ---------------------------------------------------------------------

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MAGELLAN_PLUGIN_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------
// Boot — only after WooCommerce loads
// ---------------------------------------------------------------------

add_action(
	'plugins_loaded',
	function () {
		// Load translations. Domain Path /languages — falls back to WP's
		// global language pack location if no in-plugin .mo files ship.
		load_plugin_textdomain(
			'magellan-for-woocommerce',
			false,
			dirname( plugin_basename( MAGELLAN_PLUGIN_FILE ) ) . '/languages'
		);

		// Self-updater registers BEFORE the WooCommerce gate. It has no WC
		// dependency (only MAGELLAN_PLUGIN_FILE / MAGELLAN_VERSION), and it
		// must keep working even when WC is inactive — otherwise a WC/WP/PHP
		// change that breaks WC activation would disable the very updater
		// that would ship the fix (self-referential trap). WP rebuilds the
		// update transient from scratch twice daily, so if the filter isn't
		// registered the plugin silently drops out of the update list.
		require_once MAGELLAN_PLUGIN_DIR . 'includes/class-magellan-updater.php';
		Magellan_Updater::init();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Magellan for WooCommerce requires WooCommerce to be installed and active.', 'magellan-for-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once MAGELLAN_PLUGIN_DIR . 'includes/v3/bootstrap.php';
		if (!\Magellan\V3\Config::legacy_enabled()) { return; }

		$includes = [
			'class-magellan-admin',
			'class-magellan-identity',
			'class-magellan-pixel',
			'class-magellan-tracker',
			'class-magellan-sender',
			'class-magellan-cart',
			'class-magellan-health',
		];

		foreach ( $includes as $file ) {
			require_once MAGELLAN_PLUGIN_DIR . "includes/{$file}.php";
		}

		Magellan_Admin::init();
		Magellan_Identity::init();
		Magellan_Pixel::init();
		Magellan_Tracker::init();
		Magellan_Sender::init();
		Magellan_Cart::init();
		Magellan_Health::init();
	}
);

// ---------------------------------------------------------------------
// Activation — schedule historical identity sync
// ---------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	function () {
		// Run the historical sync 60 seconds after activation so
		// the rest of the plugin has time to register hooks.
		if ( ! wp_next_scheduled( 'magellan_historical_identity_sync' ) ) {
			wp_schedule_single_event( time() + 60, 'magellan_historical_identity_sync' );
		}

		// Daily housekeeping
		if ( ! wp_next_scheduled( 'magellan_daily_cleanup' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'magellan_daily_cleanup' );
		}

		// Webhook reliability backup — every 5 minutes
		if ( ! wp_next_scheduled( 'magellan_sync_check' ) ) {
			wp_schedule_event( time() + 300, 'magellan_5min', 'magellan_sync_check' );
		}

		// Daily health report
		if ( ! wp_next_scheduled( 'magellan_health_check' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:05' ), 'daily', 'magellan_health_check' );
		}
	}
);

// Custom 5-minute cron schedule
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['magellan_5min'] ) ) {
			$schedules['magellan_5min'] = [
				'interval' => 300,
				'display'  => 'Every 5 minutes (Magellan)',
			];
		}
		return $schedules;
	}
);

// ---------------------------------------------------------------------
// Deactivation — clean up scheduled events but keep stored data
// ---------------------------------------------------------------------

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'magellan_historical_identity_sync' );
		wp_clear_scheduled_hook( 'magellan_daily_cleanup' );
		wp_clear_scheduled_hook( 'magellan_sync_check' );
		wp_clear_scheduled_hook( 'magellan_health_check' );
		wp_clear_scheduled_hook( 'magellan_v3_drain' );
		wp_clear_scheduled_hook( 'magellan_v3_maintenance' );
		if ( function_exists('as_unschedule_all_actions') ) { as_unschedule_all_actions('magellan_v3_drain', [], 'magellan-v3'); }
	}
);

// ---------------------------------------------------------------------
// Uninstall — handled in uninstall.php (not in deactivate)
// ---------------------------------------------------------------------
