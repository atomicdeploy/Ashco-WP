# Patris contract boundary

Ashko-WP accepts one living `digitalogic.product-sync` standard. It has no schema, formula, or route version selector and no compatibility branch for older payload shapes. Ashko and Digitalogic share the same payload semantics; site identity remains in each site's route, credentials, options, tables, PHP namespace, text domain, matching policy, and metadata prefix.

The required envelope keys are `schema`, `event_type`, `event_id`, `source`, `generated_at`, `products`, `categories`, `excluded_codes`, `quarantined_codes`, and `warnings`. The five collection keys are present even when empty. `local_currency`, `formula_id`, and `deleted_codes` are optional; when supplied, currency is `IRT` and the formula identifier is `landed_price`.

Every product requires `product_code`, `warnings`, and `record_hash`. Other approved product keys are sparse. A missing key means Patris supplied no source/reference value. A present key with JSON `null` means the source explicitly supplied null. Empty strings and empty warehouse objects are also explicit values. Ashko preserves all four states for hashing and receiver storage instead of filling missing keys with null.

Product hashes are SHA-256 identities of exactly the present product members other than `record_hash`, with object keys sorted lexicographically. Category hashes follow the same rule. Warehouse keys are sorted. `event_id` includes the schema name, event type, supplied currency/formula identifiers, source, timestamp, sorted product/category hash lists, excluded codes, optional tombstones, and quarantined codes. Warnings do not affect event identity.

Categories always contain `category_code`, `name`, `parent_code`, `depth`, `warnings`, and `record_hash`. An explicitly null source category name remains `name: null`; derived hierarchy fields remain non-null. Category hierarchy, catalog overlaps, source revision, event identity, ordering, and independently calculated final price are verified before a WooCommerce write.

Only `shipping_method_id` and `shipping_price_per_kg_cny` are accepted for source shipping. Removed schema/formula selectors, the duplicate event name, and old shipping aliases are rejected as unknown fields. There is no fallback parser.

Raw Paradox/Patris fields are rejected. Only transformed fields can reach WooCommerce. Application identity on Ashko is `serial`, never `product_code`; exact case-sensitive matches from the configured meta key and `_ashko_patris_serial` are unioned by Woo ID, and any collision is reported as ambiguous.

Ashko routes are `/wp-json/ashko/patris/product-sync/dry-run`, `/apply`, and `/status`. No versioned route alias is registered.
