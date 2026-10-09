# Store-enabled analytics candidate

9 October 2026. Local candidate; not deployed or enabled by this document.

Johannes requested analytics without a new storefront prompt. `analytics_policy`
is an installation-bound setting: `consent_required` (unchanged default) or
`store_enabled` (explicitly selected by the account owner). The paired receiver
must store that choice and echo it in the signed activation challenge. An older
receiver cannot activate this mode. Public page configuration contains only the
policy name, not visitor state or credentials.

Without a configured consent bridge, store-enabled mode emits analytics with
`analytics: not_applicable`, `source: store_policy`. This records a store decision;
it never asserts that a visitor accepted a prompt. Advertising remains denied.
A configured Cookiebot or MagellanConsent bridge retains control. Explicit denial
stops collection, clears the journey identifiers and pending queue, and stores a
site-specific opt-out cookie for one year so navigation cannot undo that choice.
GPC prevents automatic store-policy collection. Explicit visitor consent can
still be supplied by the existing bridge. Store policy never grants legacy pixel
or advertising permissions.

The same provenance travels in the checkout cookie and signed order context as
`browser_asserted:store_policy`. The receiver accepts it only on the matching
store-enabled installation. Orders still come through the existing legacy feed
in measurement-only mode; this setting cannot create a second canonical sale.

No banner, stylesheet, page layout, or checkout UI changes are included. The
setting is not a claim of legal compliance for every merchant or jurisdiction.
Country enrichment remains separate and is not supplied by this change.

## Review and rollout

Backend branch: `codex/plugin-store-analytics` in `magellan-api-monorepo`.
Plugin branch: `codex/store-analytics` in `magellan-plugins` (alpha.6).
Rio must review and release the exact paired candidates before activation.

1. Deploy the reviewed receiver and its additive `analytics_policy` migration.
   Existing installations remain consent-required. Verify all API processors.
2. Back up the current plugin and configuration privately. Deploy the reviewed
   alpha.6 plugin without changing policy yet; existing behavior remains.
3. For the verified installation, the authenticated owner explicitly selects
   `store_enabled` through `POST /api/v1/wordpress/installations/:id/analytics-policy`.
   Reconfigure WordPress using its existing private configuration plus the agreed
   policy, through `Config::configure`; do not expose or rotate keys unnecessarily.
4. Verify signed challenge agreement, clear relevant storefront HTML/script
   caches, inspect the public policy, and verify actual browser receipts and
   applied observations. Do not create synthetic live orders or claim coverage
   from a successful build. Verify actual next-order linkage separately.
5. Stop automatic collection by returning the receiver and plugin policy to
   `consent_required` (or disabling v3), then clear cached HTML. Disable collection
   before rolling back to an older receiver. Keep the additive column and
   receipts; code rollback does not erase collected observations.

## Checks

`node tests/store-policy-browser.cjs`, `node tests/pixel-resilience.cjs`, `node tests/browser.cjs`,
`MAGELLAN_LEGACY_PILOT=1 node tests/browser.cjs`, `php tests/store-policy.php`,
and `node tests/contracts.cjs`. Browser suites use isolated Chrome with intercepted
synthetic storefront and collector responses; PHP policy tests use stubbed WP
functions and no database/network. Paired receiver tests use real isolated
PostgreSQL and both roles. These are not live consent or checkout certification.
The GitHub Collector contracts workflow runs the PHP/Node checks; real Chrome
and PostgreSQL verification are reported separately in the review evidence.
