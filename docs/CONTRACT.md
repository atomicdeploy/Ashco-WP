# Patris contract boundary

Ashco-WP accepts one living `patris.product-sync` standard with one current schema, formula, and stable route shape. It has no compatibility branch for retired payload shapes. Ashco and others share the same payload semantics; site identity remains in each site's route, credentials, options, tables, PHP namespace, text domain, matching policy, and metadata prefix.

The required envelope keys are `schema`, `event_type`, `event_id`, `source`, `generated_at`, `products`, `categories`, `excluded_codes`, `quarantined_codes`, and `warnings`. The five collection keys are present even when empty. `local_currency`, `formula_id`, and `deleted_codes` are optional; `local_currency` and `formula_id` must either both be absent or both be present, with currency `IRT` and formula identifier `landed_price`.

Every product requires `product_code`, `warnings`, and `record_hash`. Other approved product keys are sparse. A missing key means Patris supplied no source/reference value. A present key with JSON `null` means the source explicitly supplied null. Empty strings and empty warehouse objects are also explicit values. Ashco preserves all four states for hashing and receiver storage instead of filling missing keys with null.

Product hashes are SHA-256 identities of exactly the present product members other than `record_hash`, with object keys sorted lexicographically. Category hashes follow the same rule. Warehouse keys are sorted. `event_id` includes the schema name, event type, supplied currency/formula identifiers, source, timestamp, sorted product/category hash lists, excluded codes, optional tombstones, and quarantined codes. Warnings do not affect event identity.

Categories always contain `category_code`, `name`, `parent_code`, `depth`, `warnings`, and `record_hash`. An explicitly null source category name remains `name: null`; derived hierarchy fields remain non-null. Category hierarchy, catalog overlaps, source revision, event identity, ordering, and independently calculated final price are verified before a WooCommerce write.

Source shipping uses `shipping_method_id` plus the paired fields `shipping_price_per_kg` and `shipping_price_per_kg_currency`. The amount and currency keys must either both be omitted or both be present. A non-null currency must be exactly uppercase `CNY` or `IRR`; an explicit null is preserved but cannot participate in price calculation. Missing source values remain omitted, and no currency is inferred from the amount. Unknown envelope or product fields are rejected. There is no fallback parser.

Canonical `final_price` remains IRT. Goods are converted from CNY with `irt_per_cny`. CNY freight is calculated per kilogram and converted with `irt_per_cny`; IRR freight is calculated per kilogram and divided by 10 to reach IRT. Goods and freight are combined, markup is applied once, and the receiver performs one final half-up rounding step in IRT. If either shipping field is absent or explicitly null, `final_price` must be omitted.

Raw Paradox/Patris fields are rejected. Only transformed fields can reach WooCommerce. Application identity on Ashco is `serial`, never `product_code`; exact case-sensitive matches from the configured meta key and `_ashko_patris_serial` are unioned by Woo ID, and any collision is reported as ambiguous.

Ashco routes are `/wp-json/ashko/patris/product-sync/dry-run`, `/apply`, and `/status`. No compatibility route alias is registered.

Every successful POST response is wrapped as `{success:true,data:{...}}`. The `data` object always contains non-null `status`, `event_id`, `retryable`, `pending_products`, and `deferred_products` fields. Apply work that needs another identical request uses `retry_pending` (or receiver-emitted `partially_applied`) with `retryable: true` and a positive pending count; terminal states have no pending work and are not retryable. Dry-run report progress uses `report_pending`, and a completed dry run uses `dry_run_complete`.
