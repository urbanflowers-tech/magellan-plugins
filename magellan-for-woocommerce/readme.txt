=== Magellan for WooCommerce ===
Contributors: magellan
Tags: analytics, woocommerce, attribution
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 3.0.0-alpha.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Consent-controlled website measurement and durable channel evidence for Magellan.

== Description ==
Magellan owns inventory, canonical orders and finance. This plugin supplies website observations and WooCommerce channel facts through a durable local outbox.

This is a development candidate. The paired Magellan backend must be deployed and verified before connection. Storefront-specific end-to-end checks and release review remain required before production deployment.

Features: direct browser event intake, host-only identity cookies, consent and GPC controls, page/product and checkout observations, WooCommerce order/refund/cart evidence, exact money strings, signed asynchronous delivery, per-event receipts, explicit retry, diagnostics and privacy hooks.

V2 connection settings are preserved. Until the new signed challenge succeeds, existing legacy behavior remains available. Full v3 mode replaces the old producer; measurement-only mode keeps the existing order feed. Both pixels now require analytics consent. WooCommerce 9.9 or newer is required. Reports, financial rules, advertising and stock remain in Magellan.

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

See the bundled BUILD-HANDOFF.md and the source repository for schemas, endpoint contracts, migration, validation results and release gates.

== Changelog ==

= 3.0.0-alpha.5 =
* Round WooCommerce sub-minor tax amounts using decimal string arithmetic.
* Isolate event retries, continue reconciliation past failed orders, and reserve queue space for orders/privacy.
* Run bounded maintenance on Action Scheduler and add queue export, explicit discard, and reviewed gap acknowledgement.
* Remove erased analytics links from queued evidence while retaining operational observations under new IDs.
* Respect consent in both pixels; fix legacy host-only cookie encoding, missed-order recovery, HTTPS transport and bounded customer import.
* Handle large landing evidence and checkout dependencies from block templates.

= 3.0.0-alpha.4 =
* Add an explicit receiver-confirmed measurement-only mode that keeps the existing sender and scheduled work running.
* Require matching collection mode during the signed connection challenge; report coexistence in diagnostics.

= 3.0.0-alpha.3 =
* Filter persisted browser evidence by current consent and installation before every send.
* Recover from malformed queue storage, validate receipt timestamps and serialize concurrent flush triggers.

= 3.0.0-alpha.2 =
* Preserve consented source/session and server-cart links for express checkout handlers that emit only the processed-order hook.
* Keep ordinary checkout conversion idempotent and preserve analytics erasure.
* Add regression tests against the actual Urbanflowers express-order handler in an isolated environment.
= 3.0.0-alpha.1 =
* Added store-scoped v3 protocol and signed challenge.
* Added durable MySQL outbox, explicit retries, receipts, capacity limits and diagnostics.
* Added consent-controlled session/page/product collector and checkout adapters.
* Added order/refund/cart lifecycle capture and bounded reconciliation.
* Preserved legacy mode until an explicit successful v3 connection.
