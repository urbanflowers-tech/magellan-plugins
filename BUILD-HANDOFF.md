# Magellan WordPress plugin v3 — build and backend handoff

**Build:** 3.0.0-alpha.1 · **Protocol/schema:** 3 / 3.0.0 · **Date:** 8 October 2026

This is an installable development candidate built from the existing `magellan-for-woocommerce` 2.5.0 repository. It has been exercised on an isolated WordPress/WooCommerce/MySQL installation and in an isolated Chrome context. Nothing has been installed on Urbanflowers, deployed to Magellan, pushed to GitHub, or published as an automatic update. Urbanflowers staging access has not yet been verified in this build; fitting the candidate to that storefront is the next integration step.

Magellan remains the autonomous operator and owner of inventory, canonical orders, payments and finance. The plugin collects channel evidence. It neither reserves stock nor books revenue, settles payments, computes attribution, sends advertisements, or edits storefront pages autonomously.

## What is implemented

| Area | Implementation |
|---|---|
| Installation | Store/environment-specific configuration, HTTPS API validation, 32-byte decoded signing key, signed connection challenge, clone-origin/environment checks, key rotation and authenticated status/capabilities routes |
| Delivery | Indexed MySQL outbox, immutable event IDs/bodies/hashes, leases, explicit retry/backoff, bounded batches, per-event receipts, authentication pause, conflict quarantine, replay, circuit delay, capacity reservations and bounded accepted-record cleanup |
| Runner | One unique Action Scheduler drain; continuation after the action is marked complete; WordPress cron fallback; CLI drain and maintenance for low-traffic sites |
| Browser | Direct Magellan intake, consent before optional tracking, store-namespaced host-only cookies, 30-minute sessions, entry-source evidence, page/product observations, registered interactions, checkout observations, bounded retries and cross-tab consent withdrawal |
| Commerce | Actual WooCommerce order/line/amount snapshots; independent status/refund/payment observations; server-read carts with empty/converted lifecycles; Classic and Store API context hooks; snapshot deduplication and bounded reconciliation |
| Privacy | Explicit consent event API plus Cookiebot adapter; GPC restricts advertising; advertising IDs suppressed when that purpose is not granted; local export/erasure hooks and backend erasure requests |
| Presentation | Coalesced page/product presentation-change hints and channel tombstones; page-type override in the WordPress editor; no stock data in presentation hints |
| Diagnostics | Connection, capture, receipts, runner execution, backlog, gaps and recent errors are separate; a receipt does not claim report application |
| Shared contracts | JSON Schemas, valid/invalid fixtures, PHP/Node signature vectors, receiver-side signature reference and captured-event byte/hash fixtures |

The previous producer remains available until the v3 connection challenge succeeds. Once v3 is active, the old producer and browser scripts are not initialized. Existing legacy connection settings and `_mgln_*` history are preserved for a coordinated rollback. V2/v3 normalization and downstream deduplication must exist in Magellan before migration.

## Magellan work required before connecting

The existing `/pixel/event` receiver accepts the older commerce contract. It is not a receiver for these v3 events. The new plugin will refuse to claim Connected until the backend confirms the v3 contract through a signed challenge.

Implement these independently configured endpoints, retaining existing v2 ingestion:

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

The challenge body contains `schema_version`, `installation_id`, `site_id`, `environment`, `origin` and a random `challenge`. Its response repeats those binding fields and the challenge. The API challenge and events URLs must share an HTTPS origin; a dedicated HTTPS browser collector may use another origin. Redirects are disabled. Production storefront origins must be HTTPS. Changing `home_url` or the WordPress environment pauses sending until reconnect.

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

* WooCommerce amounts describe the observed checkout: subtotal and discount excluding tax, shipping excluding tax, tax, grand total, fee lines and line values. Currency and exponent travel with integer minor-unit strings. Ambiguous precision becomes a visible capture gap. No FX calculation occurs in WordPress.
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

It can also dispatch `magellan:consent` with that object. A known initial state can be placed in `window.MagellanConsent` by the site's consent adapter in the browser, not in shared personalized cached HTML. The included Cookiebot adapter waits for an actual response. It does not equate cookie marketing consent with email/SMS marketing permission. Unknown/denied analytics creates no new persistent analytics identity. Withdrawal purges queued browser events and identifiers and broadcasts the change to other tabs. A minimal consent-control notice is sent best effort; central privacy controls must not depend solely on successful browser delivery.

Register selected interactions with `MagellanV3.registerInteraction('hero-shop')`, then use `data-magellan-component="hero-shop"` and `data-magellan-action="shop"` or call `interaction`. Unregistered clicks and form values are not sent. Checkout error messages and payment details are never scraped. The current checkout adapter honestly reports unknown error categories; richer gateway-specific stages are not certified.

Purge page/CDN caches after connection or module changes. The backend must independently enforce revocation, disabled-module policy and consent because already cached pages can retain old public configuration. `magellan_v3_served_version` is an explicit adapter hook; rendered version defaults to unknown rather than claiming that the newest database revision was served.

## Operations and recovery

Use `wp magellan status`, `wp magellan drain`, `wp magellan reconcile` and `wp magellan maintenance`. Low-traffic hosts need a real scheduled runner. The plugin exposes actual last execution separately from the chosen scheduling mechanism.

One drain leases at most 50 events and sends at most 256 KiB. HTTP timeout is eight seconds. Retry begins at 30 seconds, doubles with jitter to six hours, honors bounded Retry-After and becomes a retained dead letter after seven days. Authentication errors pause transport while capture continues. Reconnect with the same installation/key rotation resumes authentication-blocked events. Operator replay uses authenticated `POST /wp-json/magellan/v3/replay` with `event_id`; the body and ID remain unchanged. Privacy-held and incomplete-capture records cannot be replayed through that generic path.

Quota reservations atomically cap unresolved rows at 50,000 and retained event-body reservations at 100 MiB, shedding health first. Capture failure records a visible reconciliation requirement without breaking checkout. Accepted bodies are pruned after 72 hours in bounded maintenance. A process crash between quota reservation and insertion can conservatively over-reserve capacity; it cannot bypass the limit. Repair an over-reservation only with producers paused and a verified recount; do not reset a live quota while captures are in progress. No MySQL EVENT privilege, Redis or Memcached is needed.

Order reconciliation pages 25 modified orders per invocation, fixes an upper time boundary and overlaps completed windows by five minutes. It begins with the preceding day; it is recovery, not a historical full-store import. Existing Magellan API reconciliation/backfill must remain available. Capture errors keep the window from advancing. A dropped historical cart transition cannot be reconstructed reliably and remains a visible gap.

The WordPress eraser removes local order analytics links and queues a privacy request. It preserves commerce records and holds affected queued order snapshots rather than mutating their hashes. The backend must implement erasure receipts, broader identity/cart suppression and tombstones. Backend privacy completion is not certified by this local build; it is an integration gate before deployment.

## Local verification and remaining release gates

All checks below passed for this build:

| Check | Result |
|---|---|
| WooCommerce legacy order storage | 51 integration checks |
| WooCommerce HPOS order storage | The same 51 integration checks |
| Isolated Chrome browser | 20 checks |
| Cross-language contracts | 149 checks |
| JSON Schemas | 166 valid event fixtures accepted; 3 invalid fixtures rejected |
| PHP syntax | 18 files clean |

The integration runtime was WordPress 7.1.3, WooCommerce 11.2.0, PHP 8.2.29 and MySQL 8.4.0. Browser checks ran on Chrome 155.0.8059.40. The core browser script is 5,335 bytes gzipped; its checkout adapter is 645 bytes gzipped. A local fixture of 100 small event captures measured 3.89 ms at p95; this is a bounded local capture measurement, not a checkout or Core Web Vitals benchmark.

The source bundle contains the executable tests and their saved results. PHP integration tests use real WordPress, WooCommerce, MySQL and Action Scheduler, with simulated Magellan HTTP outcomes. Browser tests use a real isolated Chrome context with an intercepted collector. Signature/schema tests join these fixtures across languages.

This candidate does not certify Urbanflowers' theme, custom cart, consent manager, Omise/other gateways, caching/minification, subscription extension, multi-currency extension or multisite network activation. Storefront-specific staging, actual backend durable ingestion/report application, recovery through a real network outage, consent/erasure completion, checkout/Core Web Vitals performance, migration/rollback and production canary remain later gates. No 10,000-store capacity claim is made.

Order, cart and refund snapshots are paginated; an individually oversized source event is visibly blocked for recovery. No historical identity import, generic customer hash graph, Web Vitals, experiments, Site Model, autonomous publishing, recovery messages or ad-destination dispatch is enabled. Those capabilities are visibly false rather than empty success stubs. This implements the measurement-plugin build, not the backend reporting product or the later optional modules.

Relevant primary integration references: [WooCommerce hook compatibility](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/), [Action Scheduler API](https://actionscheduler.org/api/), and [Cookiebot API](https://www.cookiebot.com/en/developer/).
