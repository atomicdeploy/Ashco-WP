# Ashco-WP Patris Sync

Ashco-WP is the Ashco-specific WooCommerce receiver for Patris Export. It accepts the living `patris.product-sync` standard while keeping Ashco branding, settings, storage, REST routes, reports, and product matching independent from others.

## Safety model

- Run and finish the resumable dry-run before applying a production event.
- Products are matched only by an exact, case-sensitive Patris `serial` against the configured WooCommerce meta key (production default `_sku`) or `_ashko_patris_serial` written by this plugin on an earlier safe match.
- Patris Code is retained for audit/category integrity but is never an application identifier. There is no fuzzy name fallback.
- Blank Serial, duplicate source Serial, duplicate Woo Serial, and unmatched products are deferred and reported without a product write.
- Category trees, excluded Codes, sparse record hashes, source revision, event identity, event ordering, and formula integrity are validated before writes.
- Optional product keys are omitted when Patris has no source/reference value. An explicit JSON `null` is retained and hashed as an explicit source value; omission and `null` are never treated as the same payload.
- WooCommerce base currency must be `IRR`. The source contract remains canonical `IRT` for interoperability.
- External-service delivery is off unless separately implemented and explicitly configured. This release makes no outbound requests.

## Approved Ashco policy

Defaults are installed as editable settings:

- CNY reference rate: `300,000 IRR`
- Air/Express shipping amount: `22,000,000` per kilogram
- shipping currency: `IRR` by default; the administrator must explicitly choose `IRR` or `CNY`
- profit margin: `30%`
- final-price rounding: nearest half-up to `10^0 IRT` by default; `price_rounding_digits` is configurable from `0` through `9`
- saleable stock: omitted/null means no write; non-positive becomes `0`; positive becomes `max(1, floor(ALLANBAR × 30%))`
- default shipping method for records with CNY: `air_express`
- partner and direct domestic method: `domestic`, fixed at `0 IRR/kg`
- direct FOROSH/فی فروش fallback: disabled by default

The selected source is explicit. The positive CNY path wins only when weight, foreign shipping, FX, margin, and rounding are all usable. Otherwise a positive Patris `partner_price_source` (Sharh1 slot 1) uses the domestic path. `sale_price_source` is the separate FOROSH/فی فروش fact and is not the partner price. The CNY path is:

```text
((CNY × 300000) + ((weight_g / 1000) × 22000000)) × 1.30
```

The partner-price path is `partner_IRR × 1.30`; it records `domestic / 0 / IRR`, and freight, weight, and FX are not used. When CNY shipping is configured in `CNY`, the freight component is converted with the same CNY-to-IRR rate before markup. When it is configured in `IRR`, it is added directly.

An optional last path can use positive FOROSH/فی فروش directly as Woo IRR when both calculated paths are unavailable. It applies no profit, freight, FX, or rounding and requires an exact integer IRT representation. The `use_sale_price_direct_fallback` setting is `no` by default and remains disabled for Ashco.

The producer's independently validated `final_price` remains integer IRT. One configurable nearest-half-up operation is performed at the end in IRT, and Woo receives exactly `final_price × 10` IRR. For example, with two rounding digits, `123,456 IRT` becomes `123,500 IRT`. Raw zero remains distinct from an omitted or explicit-null source fact, but zero is never a usable selected price.

Full source stock is stored in `_ashko_patris_allanbar_full`. Omitted/null stock causes no Woo stock write; negative and zero stock map to zero; any positive source stock maps to at least one through `max(1, floor(ALLANBAR × 30%))`. Ashco products remain visible when out of stock, and exact saleable quantity is shown on the storefront when enabled. The normal WooCommerce stock-HTML filter is authoritative; a duplicate-safe single-product fallback runs immediately after the standard add-to-cart slot so catalog-mode themes cannot silently remove synchronized quantities, including zero stock.

## REST endpoints

```text
POST /wp-json/ashko/patris/product-sync/dry-run
POST /wp-json/ashko/patris/product-sync/apply
GET  /wp-json/ashko/patris/product-sync/status
```

Authenticate with a user/application password that has `manage_woocommerce`, or the generated receiver secret. Patris Export sends that secret through the neutral header:

```text
X-Patris-Product-Sync-Secret: <receiver secret>
```

Secrets are never accepted in query strings. Optional `X-Patris-Contract` and `X-Patris-Event-ID` headers must match the JSON document exactly.

Source scoping is stored as an exact list of `{id,dataset}` pairs. An empty list is intended only for initial setup.

## Resumable execution

Shared hosting has a short request timeout, so report planning and Woo writes are deliberately bounded:

- a report call processes at most 200 products or about 12 seconds;
- an apply/reconcile call attempts at most 25 Woo writes or about 15 seconds;
- receiver state and the durable outbox are committed after every apply batch;
- full snapshots queue changed hashes for delivery and place same-hash applied rows in a bounded live-state audit; current rows are cleared without a Woo write, while drifted rows re-enter the normal 25-write delivery batches;
- identical event calls resume the same report and then retry only pending delivery work;
- successful POST responses use one wrapper: `{success:true,data:{...}}`; `data` always exposes non-null `status`, `event_id`, `retryable`, `pending_products`, and `deferred_products`, plus the durable `run_id`.

When an apply response says `partially_applied` or `retry_pending`, send the identical event again. Report planning during apply also uses `retry_pending`; `data.report_status` distinguishes `report_pending` from `report_ready` without adding delivery-state aliases. A terminal replay does not write products again. Dry-run report planning uses `report_pending`, and completion uses `dry_run_complete`.

The receiver calls WordPress's supported `wp_raise_memory_limit('admin')` before decoding and fails clearly below 192 MiB. It does not use `set_time_limit` as a correctness mechanism.

## Product fields and reports

Core Woo fields are changed only when their desired value differs: regular/active price, stale sale price, store-unit weight, manage-stock state, stock quantity, stock status, and the narrowly scoped incomplete-product draft action. Meta changes are counted separately, including:

- CNY, exact grams, unit, Woodmart `woodmart_price_unit_of_measure`;
- full ALLANBAR and applied stock;
- effective/source shipping amounts and their explicit currencies, margin, FX, and formula values;
- canonical IRT, native IRR, both discrepancy measures;
- distinct raw partner price and FOROSH/فی فروش, selected price amount/currency/kind, and source/effective rounding provenance;
- category, effective-date, catalog, source timestamp, Serial, Code, and record hash.

Each dry-run/apply has a durable run record and per-product rows with old/new values. The Persian admin page groups warnings for missing CNY or any usable selected source, partner-price fallback, weight, unit, Serial, shipping amount/currency, margin, FX, rounding, or final price; duplicate Serial; negative stock; unmatched/ambiguous Woo targets; source warnings; formula discrepancies; and each publication-safety condition. CSV downloads are UTF-8 and include Gregorian and Jalali effective dates.

The separate **Current product and price status** tab does not replace or alter that durable history. Every validated dry-run stages its complete candidate projection in a private, non-authoritative option; the selector can therefore reconcile that unapplied candidate or the accepted receiver state against the complete current WooCommerce catalog before any product write. Matching remains exact, case-sensitive Serial. The report includes matched, source-only, positive-stock source-only, ambiguous, Woo-only, quarantined, and source-warning rows. It identifies retained stale data for quarantined codes, preserves every envelope warning, and excludes variable parent containers while retaining purchasable variations.

Core price, saleable stock, and store-unit weight are evaluated independently. Every plugin-managed product fact is also compared directly rather than trusting the stored source hash: product Code, canonical Serial, CNY and currency, unit, source weight and stock facts, shipping amount/currency/method, margin, FX, formula, final-price facts, effective date, and record identity. The report and CSV state the exact FX, freight amount and currency, margin, stock percentage, and formulas used for the current projection. Search, scope, warning filters, paging, and the filtered UTF-8 CSV export operate over the selected complete projection. CSV exports are capped at 20,000 rows, cap individual cells, and neutralize spreadsheet formula prefixes. Full downloads are first completed in a file-backed temporary stream while visited report rows are released, then sent in bounded chunks; this prevents the report graph and a server-buffered CSV copy from occupying memory together.

Publication status is another independent report dimension. A matched product is changed to `draft` only if it has no effective product or inherited parent image, has neither a positive calculated/canonical nor existing Woo price, and the source explicitly reports non-positive stock. The incoming source stock is authoritative because the same apply replaces Woo stock, so a stale positive Woo quantity does not suppress this safeguard. All three conditions and the pending status change appear in dry-run history and current-catalog CSV. Omitted/null source stock never means zero, and complete products are never auto-published.

The current report explicitly shows `فاقد داده منبع` for an omitted source key and `null صریح منبع` for a present key whose value is JSON null. Omitted fields are not materialized as null in report data.

## Product identity and sale unit

Patris Code, Patris Serial, and sale unit are product facts, not descriptive copy. For plugin-owned products they appear as escaped, RTL-safe rows in WooCommerce's standard Additional Information table and as Schema.org `additionalProperty` values in both WooCommerce and Rank Math Product entities. Their public Persian labels are `کد کالا`, `سریال کالا`, and `واحد فروش`; source-system terminology is not exposed to shoppers. Patris Code is not mislabeled as MPN/GTIN, and the theme's price-unit field remains a derived display adapter.

The canonical `_ashko_patris_unit` is resolved from a selected variation first and then its parent. It is snapshotted when an item enters the cart, displayed once in cart/checkout, copied to the order line, and exposed through WooCommerce's standard formatted item metadata as `واحد فروش`. Account pages, email templates, and invoice plugins that use WooCommerce formatted order metadata therefore retain the unit that was actually ordered even if the product changes later.

## Storefront price display

WooCommerce continues to store, calculate, order, pay, report, and expose all prices in IRR. On non-transactional storefront pages only, an accessible Persian radio control lets a visitor display product prices as `ریال` or `تومان`; the تومان view is an exact browser-side division by 10 and never writes a price or changes the WooCommerce currency. Non-divisible IRR prices retain the exact decimal تومان value instead of being rounded.

Ashco-WP normalizes Product Schema.org and Facebook/OpenGraph offer prices from WooCommerce's raw edit-context values after third-party Persian currency filters run. This compatibility layer is active only when the raw WooCommerce base-currency option is `IRR`; it does not mutate stored or transactional prices, and it never invents a price when the raw product price is empty.

The preference is restricted to `IRR`/`IRT`, retained in local storage with a same-site cookie fallback, and applied to classic and block product prices added dynamically by themes or WooCommerce. It is intentionally absent from administration, REST/JSON/AJAX responses, feeds, cart, checkout, account, endpoint, order, payment, invoice, and mini-cart contexts. Original IRR markup stays in memory for each rendered amount. An inert markup checksum plus text-only original values lets an exact converted carousel clone restore punctuation, trailing decimals, and symbols without carrying executable markup or being divided twice; those values are assigned only to DOM text nodes and are never interpreted as HTML.

The Settings tab includes a guarded maintenance action for the earlier one-time import sentence. It clears only an entire machine-generated excerpt whose title, Code, Serial, and unit exactly match the plugin-owned product metadata; merchant-written text and partial/mismatched sentences are preserved. Already-empty excerpts are excluded before the more expensive ownership scan, while any later trusted non-empty excerpt is still detected. The same operation is available as an auditable WP-CLI dry run and explicit apply:

```bash
wp ashko patris cleanup-excerpts
wp ashko patris cleanup-excerpts --yes
```

## ACF and currency integration

Ashco-WP owns the CNY reference rate and canonical product meta integration; a third-party currency switcher is not required and should remain inactive on the IRR storefront. If ACF is available, Ashco-WP registers and bidirectionally mirrors CNY and effective-date fields. Without ACF, canonical product meta and all pricing/report functionality continue to work.

## Installation and commands

Install the production ZIP in WordPress, activate it, verify Woo base currency is IRR, configure exact source scopes, then run dry-run to completion before apply.

```bash
wp ashko patris status
wp ashko patris dry-run /secure/outside-webroot/kala.json
wp ashko patris apply /secure/outside-webroot/kala.json --user=<administrator> --yes
wp ashko patris reconcile
wp ashko patris cleanup-excerpts
```

Static canonical `kala.json` input is accepted only through these CLI file commands. The resolved file must be readable, non-empty, no larger than 8 MiB, and outside the resolved WordPress web root. The command checks the size before reading and holds a shared lock while reading. Mutation additionally requires both an explicit administrator `--user` with WooCommerce management access and `--yes`. Do not place production JSON, databases, reports, or credentials inside the plugin directory, repository, or any other web-accessible directory.

A successful dry-run response includes `staged_candidate`. Open the Persian current-catalog page and select the unapplied candidate to review the full source/Woo one-sided sets, quarantine, warnings, drift, and price provenance before using `apply`. Accepting that same event removes its staged copy because the receiver state then becomes authoritative.

### Compatibility identifiers

Ashco is the public brand and the required spelling for new user-facing text and integrations. Existing runtime identifiers remain unchanged until a coordinated migration is approved: the `ashko-wp` plugin/text-domain slug, `Ashko\Patris` PHP namespace, `/ashko/` REST namespace, `wp ashko` CLI prefix, and `_ashko_*` storage keys. Product-sync clients use the neutral `X-Patris-Product-Sync-Secret` header.

## Development

```bash
composer install
composer lint
composer test
composer test:js
pwsh ./scripts/build-plugin.ps1
```

The build emits a plugin-root-correct `dist/ashko-wp-<version>.zip` and excludes `.git`, `.github`, tests, build tooling, reports, archives, and production data.

Licensed under GPL-2.0-or-later.
