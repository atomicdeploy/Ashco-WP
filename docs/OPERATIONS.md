# Production operations

1. Back up files and database outside the web root.
2. Install the ZIP and verify PHP memory can be raised to at least 192 MiB.
3. Deploy the matching Patris Export contract change in the same cutover; previously generated documents are intentionally rejected.
4. Confirm WooCommerce base currency is IRR and leave currency switchers inactive.
   The built-in storefront control changes product-price display only: تومان is exactly IRR divided by 10. Cart, checkout, account, order, payment, invoice, administration, and API values remain IRR.
5. Enter the shipping amount per kilogram and explicitly select its `IRR` or `CNY` currency; never infer currency from the amount.
6. Configure exact `{source id,dataset}` scopes and copy the generated secret into the Patris service environment.
7. Start the new receiver state with a complete snapshot. Do not begin with an update event.
8. Send the snapshot to `/dry-run` repeatedly while `retryable` is true.
9. Open **پاتریس اشکو → وضعیت فعلی کالا و قیمت**, select the validated unapplied candidate, and review its complete one-sided sets, quarantined/stale records, envelope warnings, direct metadata drift, and pricing provenance. Download the filtered CSV as needed.
10. Send the identical snapshot to `/apply` repeatedly while `retryable` is true.
11. Confirm `pending_products` is zero. Deferred missing/ambiguous rows remain explicit reconciliation work and are never guessed.
12. Select the accepted-current view and confirm matched, source-only, positive-stock missing, ambiguous, Woo-only, quarantine, warning, and independent drift totals. Export the currently filtered rows when an offline review is needed.

The first apply call may finish report planning without product writes. Its delivery status remains `retry_pending` with `retryable: true` and at least one `pending_products`; the separate `report_status` field indicates `report_pending` or `report_ready`. Subsequent identical calls drain at most 25 Woo writes each. Every batch persists and verifies receiver state before returning.

The production ZIP deliberately excludes source control, CI, tests, reports, databases, JSON payloads, credentials, and build scripts.

## One-time static kala.json operation

Keep the transformed canonical file outside the resolved WordPress web root. First inspect it without mutation:

```bash
wp ashko patris dry-run /secure/outside-webroot/kala.json
```

After the dry-run report is complete and reviewed, apply the identical file with an explicitly selected administrator:

```bash
wp ashko patris apply /secure/outside-webroot/kala.json --user=<administrator> --yes
```

The CLI rejects symlink-resolved paths inside the web root, unreadable or empty files, and files larger than 8 MiB before loading the document. It verifies the open-file size and reads under a shared lock. The apply command refuses to mutate without both `--user` and `--yes` and verifies administrator and WooCommerce management capabilities.

The static file is never served by a report page. After full validation, dry-run stores its complete transition in a separate private staged-candidate option without changing receiver authority or WooCommerce. The Persian current-catalog selector can inspect that unapplied candidate before mutation. It exposes all quarantined codes and envelope warnings, marks prior product data retained because of quarantine as stale, compares every plugin-managed WooCommerce fact directly, and records the exact FX/freight currency/rate/margin/stock formulas in both the UI and CSV. Applying the identical accepted event removes its staged copy after the receiver state becomes authoritative. Durable dry-run/apply history remains available on its own tab.

This cutover uses fresh receiver/report storage so the sparse hash identity cannot be confused with previously materialized null-filled records. Keep the pre-cutover tables/options in the database backup until the new snapshot, reports, and WooCommerce writes have been verified; they can then be removed as retired data rather than kept as a compatibility path.
