# Changelog

## Unreleased

- Renamed the public product-identifier labels to `کد کالا` and `سریال کالا` in WooCommerce product details and structured product data while retaining exact recognition of historical generated excerpts.
- Corrected the public brand spelling to Ashco across plugin display text, runtime messages, translations, and documentation.
- Standardized product-sync authentication on the neutral `X-Patris-Product-Sync-Secret` credential header.
- Added an exact-match Rank Math maintenance repair for malformed product title/description defaults and known bad product overrides.
- Made shipping rates currency-explicit throughout the living product-sync contract and Ashco settings, with exact CNY/IRR conversion and one final rounding step.
- Moved Patris Code, Serial, and sale unit from generated excerpts into semantic WooCommerce product details and structured product properties.
- Snapshotted the canonical sale unit through variations, cart, checkout, order lines, emails, account views, and standard invoice metadata.
- Added an exact-match admin/WP-CLI cleanup for legacy generated excerpts while preserving merchant-authored summaries.
- Recognized the reviewed one-time import's exact legacy provenance tuple in product presentation and cleanup without widening ownership to incomplete or altered markers.
- Preserved explicit LTR identifier markup through the live Woodmart sanitizer and extended Rank Math's emitted Product entity with the same sparse, idempotent Schema.org properties.
- Prefiltered empty raw excerpts before guarded cleanup scans so completed maintenance no longer hydrates the cleared catalog on every settings view.
- Added an accessible Persian storefront preference for displaying product prices in ریال or تومان at the exact 10:1 ratio, without changing WooCommerce prices, totals, orders, payments, APIs, or transactional screens.

## 1.1.0 — 2026-07-20

- Established one living `patris.product-sync` standard with stable Ashco REST routes.
- Merged products and catalog projection into one shape, with sparse optional product keys and exact absent/null/empty-value hashing semantics.
- Standardized source shipping fields and removed duplicate payload members.
- Removed contract level data from receiver state, reports, request headers, actions, logs, fixtures, and public documentation.

## 1.0.1 — 2026-07-20

- Added a duplicate-safe single-product stock fallback after WooCommerce's normal add-to-cart output for catalog-mode themes that remove the stock template.
- Kept the canonical `woocommerce_get_stock_html` rendering path and added regression coverage for normal, fallback, zero-stock, and non-Patris products.

## 1.0.0 — 2026-07-20

- Added exact `patris.product-sync` validation with catalog/exclusion integrity and idempotent ordered receiver state.
- Added strict configurable Serial matching (`_sku` by default plus persisted `_ashko_patris_serial`) with no Code/name fallback.
- Added native-IRR Ashco formula, approved CNY/shipping/margin defaults, full ALLANBAR retention, and floored 30% saleable stock.
- Added bounded resumable report and Woo delivery batches for 30-second shared-hosting requests.
- Added durable per-field reports, warning groups, Persian administration, CSV downloads, and Gregorian/Jalali effective dates.
- Added ACF bidirectional CNY/date support with a no-ACF canonical-meta fallback.
- Added the neutral Patris secret header, source scoping, CLI commands, PHPUnit tests, CI, and a production-safe ZIP build.
