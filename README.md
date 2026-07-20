# Ashko-WP Patris Sync

Ashko-WP is the Ashko-specific WooCommerce receiver for Patris Export. It accepts the living, versionless `digitalogic.product-sync` standard while keeping Ashko branding, settings, storage, REST routes, reports, and product matching independent from Digitalogic.

## Safety model

- Run and finish the resumable dry-run before applying a production event.
- Products are matched only by an exact, case-sensitive Patris `serial` against the configured WooCommerce meta key (production default `_sku`) or `_ashko_patris_serial` written by this plugin on an earlier safe match.
- Patris Code is retained for audit/category integrity but is never an application identifier. There is no fuzzy name fallback.
- Blank Serial, duplicate source Serial, duplicate Woo Serial, and unmatched products are deferred and reported without a product write.
- Category trees, excluded Codes, sparse record hashes, source revision, event identity, event ordering, and formula integrity are validated before writes.
- Optional product keys are omitted when Patris has no source/reference value. An explicit JSON `null` is retained and hashed as an explicit source value; omission and `null` are never treated as the same payload.
- WooCommerce base currency must be `IRR`. The source contract remains canonical `IRT` for interoperability.
- External-service delivery is off unless separately implemented and explicitly configured. This release makes no outbound requests.

## Approved Ashko policy

Defaults are installed as editable settings:

- CNY reference rate: `300,000 IRR`
- Air/Express shipping: `22,000,000 IRR/kg`
- profit margin: `30%`
- saleable stock: `floor(ALLANBAR × 30%)`
- default shipping method for records with CNY: `air_express`

Production Woo price is evaluated natively in IRR and rounded half-up once, at the end:

```text
((CNY × 300000) + ((weight_g / 1000) × 22000000)) × 1.30
```

The producer's independently validated `final_price` remains IRT. Reports retain `final_price × 10`, the native IRR result, their IRR difference, and the independently rounded native IRT comparison. This avoids routing Woo pricing through an IRT rounding boundary. For example, the native prices for the three known half-ties are 65,585; 36,855; and 12,415 IRR.

Full source stock is stored in `_ashko_patris_allanbar_full`; only the floored 30% quantity is written to Woo stock. Ashko products remain visible when out of stock, and exact saleable quantity is shown on the storefront when enabled. The normal WooCommerce stock-HTML filter is authoritative; a duplicate-safe single-product fallback runs immediately after the standard add-to-cart slot so catalog-mode themes cannot silently remove synchronized quantities, including zero stock.

## REST endpoints

```text
POST /wp-json/ashko/patris/product-sync/dry-run
POST /wp-json/ashko/patris/product-sync/apply
GET  /wp-json/ashko/patris/product-sync/status
```

Authenticate with a user/application password that has `manage_woocommerce`, or the Ashko-only generated secret. Patris Export's shared secret header is accepted:

```text
X-Digitalogic-Product-Sync-Secret: <Ashko-only secret>
```

`X-Ashko-Product-Sync-Secret` is an equivalent branded alias. Secrets are never accepted in query strings. Optional `X-Patris-Contract` and `X-Patris-Event-ID` headers must match the JSON document exactly.

Source scoping is stored as an exact list of `{id,dataset}` pairs. An empty list is intended only for initial setup.

## Resumable execution

Shared hosting has a short request timeout, so report planning and Woo writes are deliberately bounded:

- a report call processes at most 200 products or about 12 seconds;
- an apply/reconcile call attempts at most 25 Woo writes or about 15 seconds;
- receiver state and the durable outbox are committed after every apply batch;
- full snapshots queue only products whose incoming `record_hash` differs from the receiver's applied hash; unchanged hashes are not written again;
- identical event calls resume the same report and then retry only pending delivery work;
- responses expose `retryable`, `pending_products`, and the durable `run_id`.

When an apply response says `report_pending`, `report_ready`, `partially_applied`, or `retry_pending`, send the identical event again. A terminal replay does not write products again.

The receiver calls WordPress's supported `wp_raise_memory_limit('admin')` before decoding and fails clearly below 192 MiB. It does not use `set_time_limit` as a correctness mechanism.

## Product fields and reports

Core Woo fields are changed only when their desired value differs: regular/active price, stale sale price, store-unit weight, manage-stock state, stock quantity, and stock status. Meta changes are counted separately, including:

- CNY, exact grams, unit, Woodmart `woodmart_price_unit_of_measure`;
- full ALLANBAR and applied stock;
- effective/source shipping, margin, FX, and formula values;
- canonical IRT, native IRR, both discrepancy measures;
- category, effective-date, catalog, source timestamp, Serial, Code, and record hash.

Each dry-run/apply has a durable run record and per-product rows with old/new values. The Persian admin page groups warnings for missing CNY, weight, unit, Serial, shipping, margin, FX, or final price; duplicate Serial; negative stock; unmatched/ambiguous Woo targets; source warnings; and formula discrepancies. CSV downloads are UTF-8 and include Gregorian and Jalali effective dates.

## ACF and currency integration

Ashko-WP owns the CNY reference rate and canonical product meta integration; a third-party currency switcher is not required and should remain inactive on the IRR storefront. If ACF is available, Ashko-WP registers and bidirectionally mirrors CNY and effective-date fields. Without ACF, canonical product meta and all pricing/report functionality continue to work.

## Installation and commands

Install the production ZIP in WordPress, activate it, verify Woo base currency is IRR, configure exact source scopes, then run dry-run to completion before apply.

```bash
wp ashko patris status
wp ashko patris dry-run /secure/path/event.json
wp ashko patris apply /secure/path/event.json --yes
wp ashko patris reconcile
```

Do not place production JSON, databases, reports, or credentials inside the plugin directory or repository.

## Development

```bash
composer install
composer lint
composer test
pwsh ./scripts/build-plugin.ps1
```

The build emits a plugin-root-correct `dist/ashko-wp-<version>.zip` and excludes `.git`, `.github`, tests, build tooling, reports, archives, and production data.

Licensed under GPL-2.0-or-later.
