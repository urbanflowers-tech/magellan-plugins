# Magellan plugin v3 development candidate

This branch upgrades the existing `magellan-for-woocommerce` plugin. Magellan retains ownership of stock, canonical orders, payments and finance.

Read [BUILD-HANDOFF.md](BUILD-HANDOFF.md) for what is implemented, receiver integration, configuration and remaining release gates. The ZIP is an alpha candidate, not a production-certified release; staging deployment and mode are recorded separately in the release evidence.

- `magellan-for-woocommerce/`: installable WordPress plugin, preserving the original slug and legacy mode.
- `contracts/v3/`: JSON Schemas, valid/invalid fixtures and cross-language signature reference.
- `tests/`: real WordPress/WooCommerce integration tests, isolated browser tests and saved results.
- `tools/contracts.py`: reproducible schema/signature fixture generation.

## Validation

Use a disposable WordPress installation at `http://127.0.0.1:18783` with `WP_ENVIRONMENT_TYPE=local`, WooCommerce, this plugin and a disposable database. The integration test resets this plugin's test outbox and creates synthetic orders, refunds and products. It must never run on a merchant site.

```sh
php /path/to/wp-cli.phar --path=/path/to/disposable-wordpress eval-file /path/to/this-repository/tests/integration.php
node tests/contracts.cjs
python tests/schemas.py
node tests/browser.cjs
node tests/pixel-resilience.cjs
```

Schema tests require `jsonschema==4.25.1`. Browser tests require Playwright; `PLAYWRIGHT_PATH` and `CHROME_EXECUTABLE` can select installed dependencies and a separate browser executable. They use a fresh browser context with intercepted synthetic storefront/collector responses, not the user's browser session.

Run both WooCommerce order-storage modes, synchronizing the disposable WooCommerce tables through its supported HPOS CLI before switching. Saved runtime/result files identify what was actually exercised. Remaining storefront and backend tests are listed in the handoff.

Alpha.2 also includes `tests/custom-checkout.php`. After the integration fixture provisions the disposable site, set `MAGELLAN_UF_CHECKOUT_SOURCE` to a verified copy of Urbanflowers' `includes/class-uf-payment-request-order.php` and run it with the same WP-CLI `eval-file` command. The merchant source is intentionally not bundled with the generic plugin. This test runs the actual order-creation handler with a synthetic provider object, disables HTTP and mail, and records the merchant source hash. It verifies saved source/session/cart links, later refunds, duplicate-hook handling, ordinary checkout, administrative-order separation and erasure protection. It is not a payment-provider test and must never run on a merchant site.

Alpha.3 adds 21 deterministic pixel resilience checks with synthetic browser APIs and no network. The actual Chrome suite now has 26 checks. These cover persisted consent and installation boundaries, corrupt queue recovery, receipt validation, bounded retries/batches and simultaneous send triggers.
