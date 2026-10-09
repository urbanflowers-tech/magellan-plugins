# Magellan WordPress plugin v3 — build and backend handoff

**Build:** 3.0.0-alpha.5 · **Protocol/schema:** 3 / 3.0.0 · **Date:** 9 October 2026

This is the alpha.5 review candidate for a measurement-only live pilot, incorporating verified findings from two external code reviews. See [REVIEW-RESPONSE-alpha.5.md](REVIEW-RESPONSE-alpha.5.md) in the source repository for the point-by-point assessment. Alpha.5 has not been installed or activated on Urbanflowers staging or live. The last verified hosted staging candidate was alpha.3, disconnected. Alpha.4 remains available under its original GitHub tag. Publishing code or a tag does not deploy the plugin or paired receiver.

The optional `collection_mode: "measurement_only"` keeps the existing legacy sender, pixel, connection settings, cron jobs and queued order work running. The paired backend stores v3 observations and links to already imported orders; it skips commerce imports, WooCommerce API fetches and operational cart projection. Both sides must agree on the mode during the signed challenge. A mismatched or older receiver cannot silently activate this mode.

The existing full mode remains compatible and replaces the legacy producer as before. This pilot does not assert that every planned report or the whole storefront is certified. Broader backend tests have known failures reproduced on the unchanged parent revision; the live release still requires the repository's exact-candidate review and release controls.

Magellan remains the autonomous operator and owner of inventory, canonical orders, payments and finance. The plugin collects channel evidence. It neither reserves stock nor books revenue, settles payments, computes attribution, sends advertisements, or edits storefront pages autonomously.

## What is implemented

| Area | Implementation |
|---|---|
| Installation | Store/environment-specific configuration, HTTPS API validation, 32-byte decoded signing key, signed connection challenge, clone-origin/environment checks, key rotation and authenticated status/capabilities routes |
| Delivery | Indexed MySQL outbox, immutable event IDs/bodies/hashes, leases, explicit retry/backoff, bounded batches, per-event receipts, authentication pause, conflict quarantine, replay, circuit delay, capacity reservations and bounded accepted-record cleanup |
| Runner | One unique Action Scheduler drain; continuation after completion; five-minute maintenance on the same runner; WordPress cron fallback and CLI operations |
| Browser | Direct Magellan intake, consent before optional tracking, store-namespaced host-only cookies, 30-minute sessions, entry-source evidence, page/product observations, registered interactions, checkout observations, bounded retries and cross-tab consent withdrawal |
| Commerce | Actual WooCommerce order/line/amount snapshots; independent status/refund/payment observations; server-read carts with empty/converted lifecycles; Classic, processed-order express fallback and Store API context hooks; snapshot deduplication and bounded reconciliation |
| Privacy | Explicit consent event API plus Cookiebot adapter; GPC restricts advertising; advertising IDs suppressed when that purpose is not granted; local export/erasure hooks and backend erasure requests |
| Presentation | Coalesced page/product presentation-change hints and channel tombstones; page-type override in the WordPress editor; no stock data in presentation hints |
| Diagnostics | Connection, capture, receipts, runner execution, backlog, gaps and recent errors are separate; a receipt does not claim report application |
| Shared contracts | JSON Schemas, valid/invalid fixtures, PHP/Node signature vectors, receiver-side signature reference and captured-event byte/hash fixtures |

In full mode, the previous producer remains available until the v3 connection challenge succeeds, then its old sender and browser scripts are not initialized. In measurement-only mode, both remain available and the backend does not run a second commerce import. Existing legacy connection settings and `_mgln_*` history are preserved for a coordinated rollback. V2/v3 normalization and downstream deduplication must exist in Magellan before migration.

## Magellan work required before connecting

The existing `/pixel/event` receiver accepts the older commerce contract. It is not a receiver for these v3 events. The new plugin will refuse to claim Connected until the backend confirms the v3 contract through a signed challenge.

The paired local backend implements these endpoints while retaining v2 ingestion. Deployment and a real website-to-report connection remain unverified:

1. **Signed challenge** — validate the signature and credential binding, then return the exact challenge, site, installation and environment plus `schema_version: "3.0.0"` and `durable_intake_ready: true`. Return true only when event intake actually exists. This is an additional setup endpoint; it is not an analytics event.
2. **Signed WordPress event intake** — validate the raw request signature, scope and every event, commit a durable inbox and processing obligation, then return per-event receipts. Route source evidence into existing Magellan order/payment/refund/cart services under their ownership rules.
3. **Public browser event intake** — accept only browser observation types; validate site/installation/environment, origin, schema, size, time, consent, rate and abuse controls. Browser order, price or payment claims never become canonical commerce records.
4. **Processing and reports** — build sessions and joins, canonical source models and the agreed reports. The plugin does not write reporting tables or calculate paid revenue.
5. **Privacy processing** — consume `privacy_erasure_requested`, retain replay-suppression tombstones before replay/import, report completion separately from durable acceptance, and coordinate required commerce retention.

Use the existing Magellan truth-layer, order upsert, cart upsert, payment-provider ingestion and financial policy services. Map the installation credential directly to the account/workspace/store connection. Do not select the oldest workspace, invent another stock ledger, or overwrite canonical money because a plugin signature is valid.

## Provisioning

An authorized WordPress administrator or Application Password integration POSTs this configuration to `/wp-json/magellan/v3/configure`. The same object can be pasted into WooCommerce → Magellan v3. Production and test credentials must be separate.

```json
{
  "site_id": "site_example",
  "installation_id": "install_example",
  "environment": "test",
  "collection_mode": "measurement_only",
  "origin": "https://store.example.com",
  "events_url": "https://api.example.com/api/v1/wordpress/events",
  "collect_url": "https://collect.example.com/api/v1/collect/events",
  "challenge_url": "https://api.example.com/api/v1/wordpress/challenge",
  "key_id": "key_example_1",
  "signing_key": "BASE64_OF_32_RANDOM_BYTES_ISSUED_BY_MAGELLAN",
  "analytics_enabled": true,
  "policy_version": "1"
}
```

These are illustrative addresses and an intentionally invalid placeholder key. The backend must issue the real configuration; the plugin does not create a tenant from an account ID. Never put the signing key in JavaScript or shared page HTML. Settings/configuration access currently requires `manage_options`; routine event push needs no WordPress administrator session.

The challenge body contains `schema_version`, `installation_id`, `site_id`, `environment`, `origin`, `collection_mode` and a random `challenge`. Its response repeats those binding fields and the challenge. Older full-mode responses may omit the mode; a measurement-only response must confirm it explicitly. The API challenge and events URLs must share an HTTPS origin; a dedicated HTTPS browser collector may use another origin. Redirects are disabled. Production storefront origins must be HTTPS. Changing `home_url` or the WordPress environment pauses sending until reconnect.

## Wire contract

Both intake bodies are `{"events": [event, ...]}`. See `contracts/v3/event.schema.json`; every type has a payload schema and producer/evidence-class restriction. Events have UTC occurrence/capture times, source revision, stable UUID, store binding, nullable visitor/session/cart links, purpose state and source provenance. A source revision is not a transport event ID.

The signed request headers are:

```text
X-Magellan-Protocol: 3
X-Magellan-Installation: install_example
X-Magellan-Key-Id: key_example_1
X-Magellan-Timestamp: <Unix seconds>
X-Magellan-Nonce: <fresh UUID>
X-Magellan-Signature: <lowercase HMAC-SHA256 hex>
Content-Type: application/json
```

HMAC input is `v3`, uppercase method, canonical request target, installation ID, decimal timestamp, nonce and lowercase SHA-256 of the exact HTTP body, joined by LF without a trailing LF. Decode the 32-byte base64 key first. Canonical query pairs use RFC3986 encoding, lexical ordering, retained duplicate keys, and literal `+` rather than form-decoded space. Enforce ±300 seconds, key binding and nonce replay protection. Retried events keep their IDs and bodies but get fresh transport timestamps/nonces. Support bounded server-side key rotation overlap.

`signature-reference.cjs` is executable reference code, not a complete backend. The backend must supply durable replay protection, authorization, rate limiting, schema validation and transactional inbox behavior.

An accepted response is HTTP 202; completed duplicate acceptance may use 200. A mixed result can use 207. Example:

```json
{
  "results": [{
    "event_id": "11111111-1111-4111-8111-111111111111",
    "body_hash": "SHA256_OF_THE_COMPACT_EVENT_JSON",
    "status": "accepted",
    "receipt_id": "receipt_example",
    "received_at": "2026-10-08T10:00:00Z",
    "code": null
  }]
}
```

`body_hash` refers to the individual event, not the batch. Preserve the received object/list distinction and property order and hash before JSONB storage or normalization. These producers emit compact JSON, unescaped Unicode/slashes and safe integer numeric fields; money is a decimal string. PHP producer bytes and Node `JSON.stringify` hashes are checked by `tests/contracts.cjs`. A receiver accepting other serialization must extract/hash exact event bytes or negotiate a different canonicalization contract; it must not hash a re-ordered database object.

Only accepted/duplicate receipts with the matching event ID, request event hash, receipt ID and valid receipt time mark delivery complete. Missing items stay pending. `409 payload_conflict` must identify the incoming event and incoming body hash with `status: "payload_conflict"`; it is quarantined. A bare/in-progress 409 is retryable. Schema rejection should return a matching per-event `rejected` outcome. Rejected events remain inspectable, not silently deleted.

Browser requests use `text/plain;charset=UTF-8`, `credentials: omit`, no custom headers, and a maximum 10 events/16 KiB per request. The collector must explicitly parse this JSON content type and return appropriate CORS headers. The test browser exercises this transport. Keep origin/rate/size controls even though a simple request need not preflight. Beacon acceptance is never treated as a durable receipt.

## Meaning of the data

* WooCommerce amounts describe the observed checkout: subtotal and discount excluding tax, shipping excluding tax, tax, grand total, fee lines and line values. Currency and exponent travel with integer minor-unit strings. WooCommerce sub-minor values are rounded half-up with decimal string arithmetic; negative refunds and scientific notation are supported. Stored decimals use the unfiltered edit context where supported. Raw values remain in WooCommerce and its REST source records. Invalid/nonfinite values create a visible per-order recovery failure; they are never replaced with zero. Independently rounded lines and separately rounded order totals can differ slightly. No FX calculation or financial ledger calculation occurs in WordPress.
* `payment_fact` means WooCommerce's payment-complete hook was observed. `settlement_verified` is always false. Provider references and WooCommerce paid dates are source evidence; Magellan's existing provider and financial policies decide paid/settled state, including COD and bank transfer.
* Refund amounts are distinct positive refund observations; refund line quantities/totals preserve WooCommerce's signed reversal values. `refunded_line_id` links a refund line to the original source order line where supplied. A refund with no line allocation remains explicitly unallocated. No browser is required for refunds or cancellation.
* `cart_converted` links a server cart to a channel order created at checkout. It does not mean the order was paid. Emptying the cart and starting another cart create distinct lifecycle identities.
* Missing analytics consent or browser evidence never prevents operational order capture. Browser context is labeled browser-asserted; signing it does not make it payment truth. Native WooCommerce attribution is labeled metadata-only.
* Order, cart and refund snapshots with more than 50 lines are paged using a shared snapshot ID/revision and page count. Assemble all pages before applying the snapshot. Repeated source revisions and provider facts must remain idempotent across transport IDs, v2/v3 and existing webhooks.
* Country belongs to the visitor observation. Derive it at the trusted browser collector ingress, respecting trusted-proxy configuration. Do not use the WordPress server IP or a shipping address as visitor geography.
* Classify paid/organic/source channels in Magellan with a versioned rule. `click_id_types` preserves the presence/type of a click parameter; raw click ID values are emitted only when advertising is granted. Entry acquisition is frozen for the session; later touch evidence is separate.
* Derive actual measurement start and coverage from accepted/applied browser evidence in Magellan. The local plugin deliberately leaves `tracking_started_at` unavailable rather than inventing it from installation time.

For reports, retain separate session cohorts and order cohorts. Orders and amounts by source must include the unknown remainder and reconcile to canonical order totals. A session with two orders is one purchasing session and two orders. Product landing sessions, sessions viewing a product, and sessions buying that product need explicitly different filters and denominators, including nonbuyers. Mark v2/v3 attribution cutover dates and keep historical model versions visible.

## Consent and cache integration

The default optional analytics state is unknown. A CMP can call:

```javascript
window.MagellanV3.setConsent({
  analytics: 'granted',
  advertising: 'denied',
  email_marketing: 'unknown',
  sms_marketing: 'unknown',
  source: 'your_cmp_adapter',
  epoch: 1
});
```

It can also dispatch `magellan:consent` with that object. A known initial state can be placed in `window.MagellanConsent` by the site's consent adapter in the browser, not in shared personalized cached HTML. The included Cookiebot adapter waits for an actual response. It does not equate cookie marketing consent with email/SMS marketing permission. Unknown/denied analytics creates no new persistent analytics identity. Withdrawal purges queued browser events and identifiers and broadcasts the change to other tabs. On later navigation, persisted events whose captured permissions exceed current consent are dropped whole; immutable event bodies are never rewritten to strip fields. Queue merges also enforce the current installation/site/environment/visitor binding and 24-hour horizon. Invalid storage is discarded without preventing startup. A minimal consent-control notice is sent best effort; central privacy controls must not depend solely on successful browser delivery.

Register selected interactions with `MagellanV3.registerInteraction('hero-shop')`, then use `data-magellan-component="hero-shop"` and `data-magellan-action="shop"` or call `interaction`. Unregistered clicks and form values are not sent. Checkout error messages and payment details are never scraped. The current checkout adapter honestly reports unknown error categories; richer gateway-specific stages are not certified.

Purge page/CDN caches after connection or module changes. The backend must independently enforce revocation, disabled-module policy and consent because already cached pages can retain old public configuration. `magellan_v3_served_version` is an explicit adapter hook; rendered version defaults to unknown rather than claiming that the newest database revision was served.

## Operations and recovery

Use `wp magellan status`, `wp magellan drain`, `wp magellan reconcile` and `wp magellan maintenance`. Low-traffic hosts need a real scheduled runner. The plugin exposes actual last execution separately from the chosen scheduling mechanism.

One drain leases at most 50 events and sends at most 256 KiB. HTTP timeout is eight seconds. Retry begins at 30 seconds, doubles with jitter to six hours, honors bounded Retry-After and becomes a retained dead letter after seven days. Authentication errors pause transport while capture continues. Reconnect with the same installation/key rotation resumes authentication-blocked events. Operator replay uses authenticated `POST /wp-json/magellan/v3/replay` with `event_id`; the body and ID remain unchanged. Privacy-held and incomplete-capture records cannot be replayed through that generic path.

Quota reservations cap unresolved rows at 50,000 and retained body reservations at 100 MiB. Optional cart/presentation/health observations stop at 40,000 rows or 80 MiB; ordinary commerce stops at 49,000 rows or approximately 98 MiB, reserving the remainder for privacy requests. Capture failures remain visible and never break checkout. Up to 1,000 accepted rows are pruned per drain/maintenance pass. Accepted bodies are retained for at most 72 hours; above 70 MiB they can be reclaimed earlier because Magellan already accepted them. Unacknowledged bodies are never silently pruned. A crash between quota reservation and insertion can conservatively over-reserve; repair that only with producers paused and a verified recount. No MySQL EVENT privileges, Redis or Memcached are needed.

A per-event missing/invalid receipt only delays that event. Network errors, 429 and 5xx can apply store transport backoff. Authentication errors still pause the installation. Status byte usage reads the quota reservation rather than scanning payloads. State counts remain indexed table queries.

Reconciliation scans up to four pages of 25 modified orders, plus up to 10 due failed orders, per invocation. It checks a five-second budget between pages and queues a continuation when necessary; a very large individual order can exceed that target. A separate `magellan_v3_recovery` table records source failures without holding the main cursor. A connection-scoped MySQL advisory lock avoids overlapping scanner cursors. Completed windows overlap by five minutes and start with the preceding day; Magellan's existing historical import remains authoritative. A lost cart transition cannot be reliably reconstructed and stays visible as a capture gap.

The WordPress eraser queues the backend tombstone request before removing local order analytics links. It also finds older queued status/payment/refund/cart evidence without an entity key. Bounded processing removes identifying accepted/local snapshot bodies, marks orders/refunds for recapture without analytics context, and replaces retained operational transitions with new immutable event IDs. Old event IDs are never reused with redacted contents. A concurrent old receipt cannot release the privacy hold. Erasure keeps canonical WooCommerce/Magellan orders and finance; its WordPress message distinguishes queued work from completed backend erasure. The actual live privacy workflow remains an integration gate.

Operator tools (administrator shell access; exports may contain analytics identifiers):

```sh
wp magellan queue export --after=0 > /private/path/queue-page.jsonl
# Continue with --after=<last seq>; each page has at most 500 rows.
wp magellan queue discard --event-id=<uuid> --confirm-discard=<same-uuid>
wp magellan acknowledge-gap --gap-id=<current-id> --note="Reviewed source recovery and any irrecoverable gaps"
```

Export before discard. Discard accepts only an individual blocked/dead-letter event, refuses privacy holds, releases that row's quota and records a visible capture gap. Pending/leased work is not discarded through this tool. Rebinding still requires resolving/discarding old unresolved work; it does not silently move events to a new installation. Gap acknowledgement requires no failed source recovery records and retains the reviewed gap/note; it does not assert that lost observations were restored.

The authenticated Magellan admin page now offers a retain/delete preference for local v3 data on uninstall. Retain is the default. Credentials are removed either way; deleting local queue/diagnostics does not delete WooCommerce orders or Magellan records. WooCommerce attribution metadata is retained. Network-wide multisite uninstall remains unverified.

Both pixels now require analytics consent from `window.MagellanConsent`, the explicit consent API/event or the included Cookiebot adapter. The legacy pixel also accepts the current v3 consent if it loads later. Withdrawal clears legacy identifiers as well as v3 identifiers, including across tabs; GPC prevents advertising permission. Optional legacy checkout-email capture is gated. Legacy order transmission still runs without analytics consent and labels missing consent as unknown. The `.co.th` legacy cookie bug is fixed with host-only cookies and percent-encoded values. The real store's CMP and cached script delivery still need verification.

Legacy transport and bootstrap require public HTTPS and refuse redirects. The missed-order scanner checks actual scheduled jobs instead of a stale scheduling flag. Historical legacy identity import processes one 100-record customer/guest page per invocation, excludes subscriber-only users and retries the same page after a delivery failure. These legacy jobs retain their existing WP-Cron runner; the v3 maintenance runner change does not replace that operational dependency.


## Local verification and remaining release gates

Read the saved results in `tests/`, including the `alpha5` matrix files, `receiver-alpha5-results.json`, browser results and syntax/schema/hash results. The install ZIP includes this handoff; runnable tests and full evidence are in the source/review bundle, not installed in WordPress.

The alpha.5 matrix covers WordPress 6.9.4/WooCommerce 9.9.5 and WordPress 7.1.3/WooCommerce 11.2.0, each with legacy order storage and HPOS. Each combination runs 51 existing integration checks, 36 review regressions, 10 actual Urbanflowers express-handler checks, 9 pilot-mode checks, 11 legacy/operator checks, a fresh-process coexistence check and 4 uninstall checks. These are disposable local WordPress/WooCommerce/MySQL/Action Scheduler installations with intercepted HTTP/mail, not actual payment-provider tests.

Browser checks run the actual shipped scripts in an isolated Chrome context with intercepted collector responses; deterministic resilience checks cover queue limits, receipts, retries, consent boundaries and large paid landing pages. Receiver compatibility validation loads the unchanged paired backend parser/validator against current plugin fixtures; it is not a deployed end-to-end test. All test counts and artifact hashes are recorded in the output evidence summary.

The existing paired backend remains commit `4cfc67eaf936e8d25b825dce36aeff789c127aaa`. Its earlier focused run passed 53 cases, including 18 real-PostgreSQL cases; migration creation/upgrade checks passed. Its broader run was 421 passed, 9 failed, 0 skipped, with all nine failures reproduced on the unchanged parent. Alpha.5 does not modify that backend or claim to fix those failures. Receiver schema 3.0.0 and the backend measurement-only migration remain required before connection.

Declared minimum WooCommerce is 9.9. Both local test environments use PHP 8.2.29/MySQL 8.4.0. Hosted staging previously used PHP 8.2.34/MariaDB 10.11.18. The runtime/version matrix is not certification of the merchant theme, payment gateway, consent manager, caches, multilingual/multi-currency extensions, subscriptions or network activation. No 10,000-store capacity or zero-risk claim is made. Checkout dependencies now include jQuery and wp-api-fetch, including template-rendered Blocks; real-site performance and cache ordering remain to be measured.

Stock, finance, reporting, a generic identity graph, Web Vitals, experiments, Site Model, autonomous publishing, recovery messaging and ad dispatch remain outside this collector's authority. The legacy customer importer remains a compatibility feature, not a new v3 capability. In full mode the paired receiver fetches WooCommerce source orders through the existing authenticated REST connection and canonical importer, including customer/address information. In measurement-only mode it skips those writes and links evidence to existing canonical orders.

Release still requires the reviewed matching backend, Rio's exact-candidate review, actual store-to-receiver/report verification and the approved rollback plan. Disable all measurement-only installations before rolling the backend back to a version without mode support. Keep its additive mode column. The stable updater deliberately skips prerelease tags; alpha builds require explicit installation. Staging/live were not changed by this review.

Verified alpha.5 totals: **488 WordPress/WooCommerce checks across four combinations; 27 isolated-browser checks; 31 coexistence-browser checks; 22 resilience checks; 206 cross-language checks; 292 valid schema/receiver event fixtures and three rejected invalid schema fixtures.** The actual receiver also matched 193 PHP event hashes. Syntax checks cover 25 PHP files (19 plugin, six test) and seven JavaScript files. The core v3 pixel is 5907 bytes gzipped; the checkout adapter is 645 bytes. See `tests/alpha5-verification-summary.json`.
