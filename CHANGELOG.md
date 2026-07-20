# Changelog

## 1.0.0 — 2026-07-20

- Added exact `digitalogic.product-sync` v1.0/v1.1 validation with catalog/exclusion integrity and idempotent ordered receiver state.
- Added strict configurable Serial matching (`_sku` by default plus persisted `_ashko_patris_serial`) with no Code/name fallback.
- Added native-IRR Ashko formula, approved CNY/freight/margin defaults, full ALLANBAR retention, and floored 30% saleable stock.
- Added bounded resumable report and Woo delivery batches for 30-second shared-hosting requests.
- Added durable per-field reports, warning groups, Persian administration, CSV downloads, and Gregorian/Jalali effective dates.
- Added ACF bidirectional CNY/date support with a no-ACF canonical-meta fallback.
- Added Patris-compatible and Ashko-branded secret headers, source scoping, CLI commands, PHPUnit tests, CI, and a production-safe ZIP build.
