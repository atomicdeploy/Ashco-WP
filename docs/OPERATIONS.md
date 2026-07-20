# Production operations

1. Back up files and database outside the web root.
2. Install the ZIP and verify PHP memory can be raised to at least 192 MiB.
3. Confirm WooCommerce base currency is IRR and leave currency switchers inactive.
4. Configure exact `{source id,dataset}` scopes and copy the generated secret into the Patris service environment.
5. Send the snapshot to `/dry-run` repeatedly while `retryable` is true.
6. Download/review the run CSV and warning groups.
7. Send the identical snapshot to `/apply` repeatedly while `retryable` is true.
8. Confirm `pending_products` is zero. Deferred missing/ambiguous rows remain explicit reconciliation work and are never guessed.

The first apply call may finish report planning and return `report_ready` without product writes. Subsequent identical calls drain at most 25 Woo writes each. Every batch persists and verifies receiver state before returning.

The production ZIP deliberately excludes source control, CI, tests, reports, databases, JSON payloads, credentials, and build scripts.
