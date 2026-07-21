# Production operations

1. Back up files and database outside the web root.
2. Install the ZIP and verify PHP memory can be raised to at least 192 MiB.
3. Deploy the matching Patris Export contract change in the same cutover; previously generated documents are intentionally rejected.
4. Confirm WooCommerce base currency is IRR and leave currency switchers inactive.
5. Enter the shipping amount per kilogram and explicitly select its `IRR` or `CNY` currency; never infer currency from the amount.
6. Configure exact `{source id,dataset}` scopes and copy the generated secret into the Patris service environment.
7. Start the new receiver state with a complete snapshot. Do not begin with an update event.
8. Send the snapshot to `/dry-run` repeatedly while `retryable` is true.
9. Download/review the run CSV and warning groups.
10. Send the identical snapshot to `/apply` repeatedly while `retryable` is true.
11. Confirm `pending_products` is zero. Deferred missing/ambiguous rows remain explicit reconciliation work and are never guessed.

The first apply call may finish report planning without product writes. Its delivery status remains `retry_pending` with `retryable: true` and at least one `pending_products`; the separate `report_status` field indicates `report_pending` or `report_ready`. Subsequent identical calls drain at most 25 Woo writes each. Every batch persists and verifies receiver state before returning.

The production ZIP deliberately excludes source control, CI, tests, reports, databases, JSON payloads, credentials, and build scripts.

This cutover uses fresh receiver/report storage so the sparse hash identity cannot be confused with previously materialized null-filled records. Keep the pre-cutover tables/options in the database backup until the new snapshot, reports, and WooCommerce writes have been verified; they can then be removed as retired data rather than kept as a compatibility path.
