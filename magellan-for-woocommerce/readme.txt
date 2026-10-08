=== Magellan for WooCommerce ===
Contributors: magellan
Tags: analytics, woocommerce, attribution
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 3.0.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-controlled website measurement and durable channel evidence for Magellan.

== Description ==
Magellan owns inventory, canonical orders and finance. This plugin supplies website observations and WooCommerce channel facts through a durable local outbox.

This is a development candidate. The v3 Magellan backend must be implemented and verified before connection. Urbanflowers staging and deployment are separate later steps.

Features: direct browser event intake, host-only identity cookies, consent and GPC controls, page/product and checkout observations, WooCommerce order/refund/cart evidence, exact money strings, signed asynchronous delivery, per-event receipts, explicit retry, diagnostics and privacy hooks.

V2 connection settings are preserved. Until the new signed challenge succeeds, existing legacy behavior remains available. After activation of v3, its producer replaces the old producer. Reports, financial rules, advertising and stock remain in Magellan.

== Installation ==
1. Upload the ZIP through WordPress Plugins, using the existing magellan-for-woocommerce slug.
2. Open WooCommerce > Magellan v3.
3. Supply the installation configuration issued by a compatible Magellan backend.
4. The signed challenge must pass before the plugin reports Connected.
5. Configure the consent adapter and purge the storefront cache. Unknown consent disables optional analytics.
6. Verify queue receipts and the actual runner. Use real cron or WP-CLI on low-traffic sites.

== Operations ==
wp magellan status
wp magellan drain
wp magellan reconcile
wp magellan maintenance

See BUILD-HANDOFF.md in the source bundle for schemas, endpoint contracts, migration, validation results and release gates.

== Changelog ==
= 3.0.0-alpha.1 =
* Added store-scoped v3 protocol and signed challenge.
* Added durable MySQL outbox, explicit retries, receipts, capacity limits and diagnostics.
* Added consent-controlled session/page/product collector and checkout adapters.
* Added order/refund/cart lifecycle capture and bounded reconciliation.
* Preserved legacy mode until an explicit successful v3 connection.
