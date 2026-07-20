# Changelog

## 1.1.0 — 2026-07-20

- Replaced schema/formula feature levels with one living, versionless `digitalogic.product-sync` standard and unversioned Ashko REST routes.
- Merged products and catalog projection into one shape, with sparse optional product keys and exact absent/null/empty-value hashing semantics.
- Standardized source shipping on `shipping_method_id` and `shipping_price_per_kg_cny`; removed duplicate and legacy payload fields.
- Removed contract level data from receiver state, reports, request headers, actions, logs, fixtures, and public documentation.

## 1.0.1 — 2026-07-20

- Added a duplicate-safe single-product stock fallback after WooCommerce's normal add-to-cart output for catalog-mode themes that remove the stock template.
- Kept the canonical `woocommerce_get_stock_html` rendering path and added regression coverage for normal, fallback, zero-stock, and non-Patris products.

## 1.0.0 — 2026-07-20

- Added exact `digitalogic.product-sync` validation with catalog/exclusion integrity and idempotent ordered receiver state.
- Added strict configurable Serial matching (`_sku` by default plus persisted `_ashko_patris_serial`) with no Code/name fallback.
- Added native-IRR Ashko formula, approved CNY/shipping/margin defaults, full ALLANBAR retention, and floored 30% saleable stock.
- Added bounded resumable report and Woo delivery batches for 30-second shared-hosting requests.
- Added durable per-field reports, warning groups, Persian administration, CSV downloads, and Gregorian/Jalali effective dates.
- Added ACF bidirectional CNY/date support with a no-ACF canonical-meta fallback.
- Added Patris-compatible and Ashko-branded secret headers, source scoping, CLI commands, PHPUnit tests, CI, and a production-safe ZIP build.
