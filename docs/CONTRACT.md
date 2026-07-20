# Patris contract boundary

Ashko-WP accepts only `digitalogic.product-sync` schema major 1. The contract name remains unchanged so one Patris Export producer can serve Ashko and Digitalogic. Site identity lives in the `ashko/v1` route, Ashko-only credentials/options/tables, `Ashko\Patris` PHP namespace, `ashko-wp` text domain, and `_ashko_patris_*` metadata.

Version 1.0 contains products. Version 1.1 adds the complete category projection, `category_code` on products, and `excluded_codes`. An update cannot cross feature levels without a new snapshot. Category hashes, hierarchy depth/parent relationships, overlaps, source revision, and event identity are verified exactly.

Raw Paradox/Patris fields are rejected. Only transformed typed fields can reach WooCommerce.

Application identity is `serial`, never `product_code`. The resolver performs a case-sensitive exact database comparison against the configured meta key and `_ashko_patris_serial`; results from both namespaces are unioned and de-duplicated by Woo ID. Any cross-product collision is ambiguous.
