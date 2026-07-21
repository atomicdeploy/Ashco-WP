<?php
namespace Ashko\Patris;

use Throwable;
use WP_Error;

/**
 * Read-only reconciliation of the complete current receiver snapshot and WooCommerce.
 *
 * This report never mutates receiver state or products. Source values retain three
 * distinct states: omitted, explicit null, and present value.
 */
final class Current_Catalog_Report {
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 100;
    public const MAX_CSV_ROWS = 20000;
    public const MAX_CSV_CELL_BYTES = 32000;
    public const STAGED_OPTION = 'ashko_current_catalog_staged_projection';
    private const MAX_WOO_PRODUCTS = 50000;
    private const MAX_STAGED_SOURCES = 16;

    /** @return array|WP_Error */
    public function build(?array $state = null, ?array $woo_products = null, string $snapshot = 'applied') {
        $snapshot_kind = 'provided';
        $staged_at = '';
        if (null === $state) {
            if ('candidate' === $snapshot && self::has_staged_candidate()) {
                $candidate = $this->candidate_state();
                $state = $candidate['state'];
                $snapshot_kind = 'candidate';
                $staged_at = $candidate['staged_at'];
            } else {
                $state = Product_Sync_Receiver::instance()->get_state();
                $snapshot_kind = 'applied';
            }
        }
        $sources = is_array($state['sources'] ?? null) ? $state['sources'] : array();
        ksort($sources, SORT_STRING);

        $source_records = $this->source_records($sources);
        $resolver_input = array();
        $source_serials = array();
        foreach ($source_records as $key => $record) {
            if (empty($record['has_product'])) {
                continue;
            }
            $resolver_product = $record['product'];
            $resolver_product['product_code'] = $key;
            $resolver_input[] = $resolver_product;
            $serial = $this->present_string($record['product'], 'serial');
            if ('' !== $serial) {
                $source_serials[$serial] = true;
            }
        }

        $resolved = Serial_Resolver::instance()->resolve_catalog($resolver_input);
        if (null === $woo_products) {
            $woo_products = $this->load_woocommerce_products();
            if (is_wp_error($woo_products)) {
                return $woo_products;
            }
        }

        $woo = array();
        $variable_parent_count = 0;
        $variable_parent_ids = array();
        foreach ($woo_products as $product) {
            if (!is_object($product) || !method_exists($product, 'get_id')) {
                continue;
            }
            if ($this->is_variable_parent($product)) {
                ++$variable_parent_count;
                $variable_parent_ids[(int) $product->get_id()] = true;
                continue;
            }
            $snapshot = $this->woo_snapshot($product);
            $woo[(int) $snapshot['id']] = array('product' => $product, 'snapshot' => $snapshot);
        }
        ksort($woo, SORT_NUMERIC);

        $rows = array();
        $related_woo_ids = array();
        foreach ($source_records as $key => $record) {
            $resolution = $resolved[$key] ?? new WP_Error(
                'ashko_current_report_unmatched',
                __('No WooCommerce product has that exact Serial.', 'ashko-wp'),
                array('reason' => 'unmatched_woocommerce')
            );
            $row = $this->source_row($record, $resolution, $woo, $variable_parent_ids, $related_woo_ids);
            $rows[] = $row;
        }

        foreach ($woo as $woo_id => $entry) {
            $snapshot = $entry['snapshot'];
            $serial_intersection = array_intersect($snapshot['serials'], array_keys($source_serials));
            if (isset($related_woo_ids[$woo_id]) || array() !== $serial_intersection) {
                continue;
            }
            $rows[] = $this->woo_only_row($snapshot);
        }

        $summary = $this->summary($rows);
        $summary['source_snapshots'] = count($sources);
        $summary['variable_parents_excluded'] = $variable_parent_count;
        $summary['woo_products_considered'] = count($woo);

        return array(
            'generated_at' => gmdate('c'),
            'snapshot_kind' => $snapshot_kind,
            'staged_at' => $staged_at,
            'provenance' => $this->provenance(),
            'summary' => $summary,
            'warnings' => $this->warning_counts($rows),
            'rows' => $rows,
        );
    }

    /** Persist a validated dry-run projection without changing receiver authority or WooCommerce. */
    public static function stage_preview(array $preview) {
        $envelope = is_array($preview['envelope'] ?? null) ? $preview['envelope'] : array();
        $transition = is_array($preview['transition'] ?? null) ? $preview['transition'] : array();
        $source = is_array($envelope['source'] ?? null) ? $envelope['source'] : array();
        $source_id = (string) ($source['id'] ?? '');
        $dataset = (string) ($source['dataset'] ?? '');
        if (
            '' === $source_id
            || '' === $dataset
            || !is_array($transition['products'] ?? null)
            || !is_array($envelope['quarantined_codes'] ?? null)
            || !is_array($envelope['warnings'] ?? null)
        ) {
            return new WP_Error(
                'ashko_current_report_stage_invalid',
                __('The validated candidate projection is incomplete and was not staged.', 'ashko-wp')
            );
        }

        $key = self::source_key($source_id, $dataset);
        $staged = get_option(self::STAGED_OPTION, array());
        $staged = is_array($staged) ? $staged : array();
        $staged['sources'] = is_array($staged['sources'] ?? null) ? $staged['sources'] : array();
        if (!isset($staged['sources'][$key]) && count($staged['sources']) >= self::MAX_STAGED_SOURCES) {
            return new WP_Error(
                'ashko_current_report_stage_limit',
                __('The safe staged-source limit has been reached.', 'ashko-wp')
            );
        }
        $current_candidate = is_array($staged['sources'][$key] ?? null) ? $staged['sources'][$key] : array();
        $new_order = is_array($envelope['generated_at_order'] ?? null) ? $envelope['generated_at_order'] : array();
        $current_order = is_array($current_candidate['generated_at_order'] ?? null)
            ? $current_candidate['generated_at_order']
            : array();
        if (array() !== $current_order && array() !== $new_order) {
            $comparison = self::compare_timestamp_order($new_order, $current_order);
            if ($comparison < 0) {
                return new WP_Error(
                    'ashko_current_report_staged_candidate_stale',
                    __('An older candidate cannot replace the newer staged projection.', 'ashko-wp')
                );
            }
            if (
                0 === $comparison
                && !hash_equals((string) ($current_candidate['last_event_id'] ?? ''), (string) ($envelope['event_id'] ?? ''))
            ) {
                return new WP_Error(
                    'ashko_current_report_staged_candidate_conflict',
                    __('A different candidate already occupies this source timestamp.', 'ashko-wp')
                );
            }
        }

        $applied = Product_Sync_Receiver::instance()->get_state();
        $existing = is_array($applied['sources'][$key] ?? null) ? $applied['sources'][$key] : array();
        $existing_dates = is_array($existing['preserved_product_generated_at'] ?? null)
            ? $existing['preserved_product_generated_at']
            : array();
        $preserved_codes = array_values(array_filter(
            (array) ($transition['preserved_quarantined_codes'] ?? array()),
            'is_string'
        ));
        sort($preserved_codes, SORT_STRING);
        $preserved_dates = array();
        foreach ($preserved_codes as $code) {
            $date = (string) ($existing_dates[$code] ?? ($existing['generated_at'] ?? ''));
            if ('' !== $date) {
                $preserved_dates[$code] = $date;
            }
        }

        $candidate = array(
            'source' => $source,
            'generated_at' => (string) ($envelope['generated_at'] ?? ''),
            'generated_at_order' => $new_order,
            'last_event_id' => (string) ($envelope['event_id'] ?? ''),
            'last_event_type' => (string) ($envelope['event_type'] ?? ''),
            'products' => $transition['products'],
            'categories' => is_array($transition['categories'] ?? null) ? $transition['categories'] : array(),
            'excluded_codes' => is_array($transition['excluded_codes'] ?? null) ? $transition['excluded_codes'] : array(),
            'quarantined_codes' => array_values($envelope['quarantined_codes']),
            'preserved_quarantined_codes' => $preserved_codes,
            'preserved_product_generated_at' => $preserved_dates,
            'envelope_warnings' => array_values($envelope['warnings']),
            'staged_at' => gmdate('c'),
            'candidate' => true,
        );
        $staged['sources'][$key] = $candidate;
        ksort($staged['sources'], SORT_STRING);
        update_option(self::STAGED_OPTION, $staged, false);
        $readback = get_option(self::STAGED_OPTION, array());
        if (!is_array($readback) || !isset($readback['sources'][$key])) {
            return new WP_Error(
                'ashko_current_report_stage_failed',
                __('The validated candidate projection did not pass staged readback.', 'ashko-wp')
            );
        }
        if (!hash_equals(hash('sha256', maybe_serialize($candidate)), hash('sha256', maybe_serialize($readback['sources'][$key])))) {
            return new WP_Error(
                'ashko_current_report_stage_readback_mismatch',
                __('The staged candidate projection changed during persistence.', 'ashko-wp')
            );
        }
        return array(
            'source_id' => $source_id,
            'dataset' => $dataset,
            'event_id' => $candidate['last_event_id'],
            'products' => count($candidate['products']),
            'quarantined_codes' => count($candidate['quarantined_codes']),
            'envelope_warnings' => count($candidate['envelope_warnings']),
            'staged_at' => $candidate['staged_at'],
        );
    }

    public static function clear_staged_source(string $source_id, string $dataset, string $event_id): void {
        $staged = get_option(self::STAGED_OPTION, array());
        if (!is_array($staged) || !is_array($staged['sources'] ?? null)) {
            return;
        }
        $key = self::source_key($source_id, $dataset);
        $candidate = $staged['sources'][$key] ?? null;
        if (!is_array($candidate) || !hash_equals((string) ($candidate['last_event_id'] ?? ''), $event_id)) {
            return;
        }
        unset($staged['sources'][$key]);
        update_option(self::STAGED_OPTION, $staged, false);
    }

    public static function has_staged_candidate(): bool {
        return array() !== self::active_staged_sources();
    }

    public function filtered_rows(array $report, array $criteria): array {
        $search = $this->lower(trim((string) ($criteria['search'] ?? '')));
        $scope = (string) ($criteria['scope'] ?? 'all');
        $warning = (string) ($criteria['warning'] ?? '');
        $allowed_scopes = array('all', 'matched', 'source_only', 'woo_only', 'ambiguous', 'drift', 'quarantined', 'source_warning');
        if (!in_array($scope, $allowed_scopes, true)) {
            $scope = 'all';
        }

        return array_values(array_filter($report['rows'] ?? array(), static function ($row) use ($search, $scope, $warning): bool {
            if ('all' !== $scope) {
                if ('drift' === $scope) {
                    if (empty(array_filter($row['drift'] ?? array()))) {
                        return false;
                    }
                } elseif ('quarantined' === $scope) {
                    if (empty($row['quarantined'])) {
                        return false;
                    }
                } elseif ('source_warning' === $scope) {
                    if (empty($row['envelope_warnings'])) {
                        return false;
                    }
                } elseif ($scope !== (string) ($row['kind'] ?? '')) {
                    return false;
                }
            }
            if ('' !== $warning && !in_array($warning, $row['warnings'] ?? array(), true)) {
                return false;
            }
            if ('' !== $search && !str_contains((string) ($row['search'] ?? ''), $search)) {
                return false;
            }
            return true;
        }));
    }

    public function page(array $rows, int $page, int $page_size): array {
        $page_size = max(1, min(self::MAX_PAGE_SIZE, $page_size));
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $page_size));
        $page = max(1, min($pages, $page));
        return array(
            'rows' => array_slice($rows, ($page - 1) * $page_size, $page_size),
            'page' => $page,
            'page_size' => $page_size,
            'pages' => $pages,
            'total' => $total,
        );
    }

    public static function field_state(array $source, string $key): array {
        if (!array_key_exists($key, $source)) {
            return array('state' => 'omitted');
        }
        return array('state' => null === $source[$key] ? 'null' : 'value', 'value' => $source[$key]);
    }

    public static function csv_cell($value): string {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (null === $value) {
            $value = '';
        }
        $value = (string) $value;
        if (strlen($value) > self::MAX_CSV_CELL_BYTES) {
            $value = function_exists('mb_strcut')
                ? mb_strcut($value, 0, self::MAX_CSV_CELL_BYTES, 'UTF-8')
                : substr($value, 0, self::MAX_CSV_CELL_BYTES);
        }
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }

    public static function download_url(array $criteria = array()): string {
        $query = array(
            'action' => 'ashko_download_current_catalog_report',
            'search' => (string) ($criteria['search'] ?? ''),
            'scope' => (string) ($criteria['scope'] ?? 'all'),
            'warning' => (string) ($criteria['warning'] ?? ''),
            'snapshot' => (string) ($criteria['snapshot'] ?? 'applied'),
        );
        return wp_nonce_url(
            admin_url('admin-post.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)),
            'ashko_download_current_catalog_report'
        );
    }

    private function candidate_state(): array {
        $state = Product_Sync_Receiver::instance()->get_state();
        $state['sources'] = is_array($state['sources'] ?? null) ? $state['sources'] : array();
        $staged_sources = self::active_staged_sources($state);
        $latest = '';
        foreach ($staged_sources as $key => $source_state) {
            if (!is_array($source_state)) {
                continue;
            }
            $state['sources'][(string) $key] = $source_state;
            $latest = max($latest, (string) ($source_state['staged_at'] ?? ''));
        }
        ksort($state['sources'], SORT_STRING);
        return array('state' => $state, 'staged_at' => $latest);
    }

    private static function active_staged_sources(?array $applied = null): array {
        $staged = get_option(self::STAGED_OPTION, array());
        $sources = is_array($staged['sources'] ?? null) ? $staged['sources'] : array();
        if (array() === $sources) {
            return array();
        }
        $applied = null === $applied ? Product_Sync_Receiver::instance()->get_state() : $applied;
        $applied_sources = is_array($applied['sources'] ?? null) ? $applied['sources'] : array();
        foreach ($sources as $key => $candidate) {
            if (!is_array($candidate)) {
                unset($sources[$key]);
                continue;
            }
            $candidate_event = (string) ($candidate['last_event_id'] ?? '');
            $applied_event = (string) ($applied_sources[$key]['last_event_id'] ?? '');
            if ('' !== $candidate_event && '' !== $applied_event && hash_equals($candidate_event, $applied_event)) {
                unset($sources[$key]);
            }
        }
        ksort($sources, SORT_STRING);
        return $sources;
    }

    private function provenance(): array {
        $values = array(
            'store_currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'fx_irr_per_cny' => (string) Config::get('fx_irr_per_cny', ''),
            'shipping_method_id' => (string) Config::get('default_shipping_method', ''),
            'shipping_price_per_kg' => (string) Config::get('shipping_price_per_kg', ''),
            'shipping_price_per_kg_currency' => (string) Config::get('shipping_price_per_kg_currency', ''),
            'profit_margin_percent' => (string) Config::get('profit_margin_percent', ''),
            'stock_percent' => (string) Config::get('stock_percent', ''),
            'price_formula' => Decimal_Calculator::PRICE_FORMULA,
            'stock_formula' => Decimal_Calculator::STOCK_FORMULA,
        );
        return array_filter($values, static fn($value): bool => '' !== (string) $value);
    }

    private static function source_key(string $source_id, string $dataset): string {
        return hash('sha256', $source_id . "\n" . $dataset);
    }

    private static function compare_timestamp_order(array $left, array $right): int {
        if (count($left) !== 2 || count($right) !== 2) {
            return 0;
        }
        if ((int) $left[0] !== (int) $right[0]) {
            return (int) $left[0] <=> (int) $right[0];
        }
        return (int) $left[1] <=> (int) $right[1];
    }

    private function source_records(array $sources): array {
        $records = array();
        foreach ($sources as $source_key => $source_state) {
            if (!is_array($source_state)) {
                continue;
            }
            $source = is_array($source_state['source'] ?? null) ? $source_state['source'] : array();
            $products = is_array($source_state['products'] ?? null) ? $source_state['products'] : array();
            $quarantined = array_fill_keys(array_values(array_filter(
                (array) ($source_state['quarantined_codes'] ?? array()),
                'is_string'
            )), true);
            $preserved_dates = is_array($source_state['preserved_product_generated_at'] ?? null)
                ? $source_state['preserved_product_generated_at']
                : array();
            $envelope_warnings = array_values(array_filter(
                (array) ($source_state['envelope_warnings'] ?? array()),
                'is_string'
            ));
            ksort($products, SORT_STRING);
            $known_codes = array_fill_keys(array_merge(array_keys($products), array_keys($quarantined)), true);
            $warnings_by_code = array();
            $unassigned_warnings = array();
            foreach ($envelope_warnings as $warning) {
                $separator = strrpos($warning, ':');
                $warning_code = false === $separator ? '' : substr($warning, $separator + 1);
                if ('' !== $warning_code && isset($known_codes[$warning_code])) {
                    $warnings_by_code[$warning_code][] = $warning;
                } else {
                    $unassigned_warnings[] = $warning;
                }
            }
            foreach ($products as $product_key => $product) {
                if (!is_array($product)) {
                    continue;
                }
                $code = (string) ($product['product_code'] ?? $product_key);
                $key = hash('sha256', (string) $source_key . "\0" . $code);
                $is_quarantined = isset($quarantined[$code]);
                // Valid envelopes never contain a product and quarantine the same
                // code. A product retained under a quarantined code is therefore
                // necessarily carried from an older accepted snapshot.
                $is_preserved = $is_quarantined;
                $records[$key] = array(
                    'source_id' => (string) ($source['id'] ?? ''),
                    'dataset' => (string) ($source['dataset'] ?? ''),
                    'snapshot_generated_at' => (string) ($source_state['generated_at'] ?? ''),
                    'source_received_at' => (string) ($source_state['received_at'] ?? ''),
                    'staged_at' => (string) ($source_state['staged_at'] ?? ''),
                    'candidate' => !empty($source_state['candidate']),
                    'has_product' => true,
                    'record_type' => 'product',
                    'quarantined' => $is_quarantined,
                    'preserved_quarantined' => $is_preserved,
                    'stale_since' => $is_preserved ? (string) ($preserved_dates[$code] ?? '') : '',
                    'envelope_warnings' => $warnings_by_code[$code] ?? array(),
                    'product' => $product,
                );
            }
            foreach (array_keys($quarantined) as $code) {
                if (isset($products[$code])) {
                    continue;
                }
                $key = hash('sha256', (string) $source_key . "\0quarantine\0" . $code);
                $records[$key] = array(
                    'source_id' => (string) ($source['id'] ?? ''),
                    'dataset' => (string) ($source['dataset'] ?? ''),
                    'snapshot_generated_at' => (string) ($source_state['generated_at'] ?? ''),
                    'source_received_at' => (string) ($source_state['received_at'] ?? ''),
                    'staged_at' => (string) ($source_state['staged_at'] ?? ''),
                    'candidate' => !empty($source_state['candidate']),
                    'has_product' => false,
                    'record_type' => 'quarantine',
                    'quarantined' => true,
                    'preserved_quarantined' => false,
                    'stale_since' => '',
                    'envelope_warnings' => $warnings_by_code[$code] ?? array(),
                    'product' => array('product_code' => $code),
                );
            }
            foreach ($unassigned_warnings as $index => $warning) {
                $key = hash('sha256', (string) $source_key . "\0warning\0" . $index . "\0" . $warning);
                $records[$key] = array(
                    'source_id' => (string) ($source['id'] ?? ''),
                    'dataset' => (string) ($source['dataset'] ?? ''),
                    'snapshot_generated_at' => (string) ($source_state['generated_at'] ?? ''),
                    'source_received_at' => (string) ($source_state['received_at'] ?? ''),
                    'staged_at' => (string) ($source_state['staged_at'] ?? ''),
                    'candidate' => !empty($source_state['candidate']),
                    'has_product' => false,
                    'record_type' => 'source_warning',
                    'quarantined' => false,
                    'preserved_quarantined' => false,
                    'stale_since' => '',
                    'envelope_warnings' => array($warning),
                    'product' => array(),
                );
            }
        }
        return $records;
    }

    /** @return array|WP_Error */
    private function load_woocommerce_products() {
        $statuses = function_exists('get_post_stati') ? get_post_stati(array(), 'names') : array();
        $statuses = is_array($statuses) && array() !== $statuses
            ? array_values(array_diff(array_map('strval', $statuses), array('trash', 'auto-draft')))
            : array('publish', 'private', 'draft', 'pending', 'future', 'inherit');
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => $statuses,
            'fields' => 'ids',
            'posts_per_page' => self::MAX_WOO_PRODUCTS + 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ));
        if (!is_array($ids)) {
            return new WP_Error('ashko_current_report_woo_query_failed', __('The Ashco product catalog could not be read.', 'ashko-wp'));
        }
        if (count($ids) > self::MAX_WOO_PRODUCTS) {
            return new WP_Error('ashko_current_report_woo_limit', __('The Ashco product catalog exceeds the safe report limit.', 'ashko-wp'));
        }
        $products = array();
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product) {
                $products[] = $product;
            }
        }
        return $products;
    }

    private function source_row(
        array $record,
        $resolution,
        array $woo,
        array $variable_parent_ids,
        array &$related_woo_ids
    ): array {
        if (empty($record['has_product'])) {
            return $this->non_product_source_row($record);
        }
        $source = $record['product'];
        $resolution = $this->exclude_variable_parents_from_resolution($resolution, $woo, $variable_parent_ids);
        $applicator = Product_Applicator::instance();
        $analysis = $applicator->analyze_source($source);
        $desired = $applicator->report_projection($source);
        $warnings = $analysis['warnings'];
        $states = array();
        foreach (array('product_code', 'name', 'serial', 'foreign_currency', 'foreign_price', 'weight_grams', 'unit', 'total_stock', 'final_price', 'record_hash') as $field) {
            $states[$field] = self::field_state($source, $field);
        }
        foreach (array('serial', 'foreign_currency', 'foreign_price', 'weight_grams', 'unit', 'total_stock') as $field) {
            if ('omitted' === $states[$field]['state']) {
                $warnings[] = 'source_omitted_' . $field;
            } elseif ('null' === $states[$field]['state']) {
                $warnings[] = 'source_explicit_null_' . $field;
            }
        }
        if (!empty($record['quarantined'])) {
            $warnings[] = 'quarantined_source_record';
        }
        if (!empty($record['preserved_quarantined'])) {
            $warnings[] = 'quarantined_preserved_stale';
        }
        if (!empty($record['envelope_warnings'])) {
            $warnings[] = 'source_envelope_warning';
        }

        $row = array(
            'kind' => 'source_only',
            'resolution' => 'unmatched_woocommerce',
            'source_id' => $record['source_id'],
            'dataset' => $record['dataset'],
            'snapshot_generated_at' => $record['snapshot_generated_at'],
            'source_received_at' => $record['source_received_at'],
            'staged_at' => $record['staged_at'],
            'candidate' => $record['candidate'],
            'has_source_product' => true,
            'quarantined' => $record['quarantined'],
            'preserved_quarantined' => $record['preserved_quarantined'],
            'stale_since' => $record['stale_since'],
            'envelope_warnings' => $record['envelope_warnings'],
            'product_code' => $this->present_string($source, 'product_code'),
            'name' => $this->present_string($source, 'name'),
            'serial' => $this->present_string($source, 'serial'),
            'source_fields' => $states,
            'woo' => array(),
            'projection' => $this->projection($source, $desired),
            'meta_drift' => array(),
            'drift' => $this->empty_drift(),
            'warnings' => array(),
            'search' => '',
        );

        if (is_wp_error($resolution)) {
            $data = $resolution->get_error_data();
            $reason = is_array($data) ? (string) ($data['reason'] ?? 'unmatched_woocommerce') : 'unmatched_woocommerce';
            if ('ashko_product_identifier_query_failed' === $resolution->get_error_code()) {
                $row['kind'] = 'ambiguous';
                $row['resolution'] = 'serial_lookup_failed';
                $warnings[] = 'serial_lookup_failed';
            } else {
                $row['resolution'] = $reason;
            }
            if (in_array($reason, array('duplicate_source_serial', 'duplicate_woocommerce_serial'), true)) {
                $row['kind'] = 'ambiguous';
                $warnings[] = $reason;
                if (is_array($data['woocommerce_ids'] ?? null)) {
                    foreach ($data['woocommerce_ids'] as $id) {
                        $related_woo_ids[(int) $id] = true;
                    }
                }
            } elseif ('serial_lookup_failed' !== $row['resolution']) {
                $warnings[] = 'unmatched_woocommerce';
                if ($this->positive_stock($source)) {
                    $warnings[] = 'positive_stock_missing_in_woocommerce';
                }
            }
        } else {
            $woo_id = (int) ($resolution['woocommerce_id'] ?? 0);
            if (isset($woo[$woo_id]) && !$this->is_variable_parent($woo[$woo_id]['product'])) {
                $related_woo_ids[$woo_id] = true;
                $row['kind'] = 'matched';
                $row['resolution'] = 'exact_serial';
                $row['woo'] = $woo[$woo_id]['snapshot'];
                $plan = $applicator->plan($woo[$woo_id]['product'], $source);
                $row['meta_drift'] = $plan['meta_changes'];
                $row['drift'] = $this->drift(
                    $source,
                    $woo[$woo_id]['product'],
                    $analysis['calculation'],
                    $plan['meta_changes']
                );
                foreach ($row['drift'] as $field => $drifted) {
                    if ($drifted) {
                        $warnings[] = $field . '_drift';
                    }
                }
            } else {
                $row['resolution'] = isset($variable_parent_ids[$woo_id])
                    ? 'variable_parent_excluded'
                    : 'woocommerce_product_unavailable';
                $warnings[] = $row['resolution'];
                if ($this->positive_stock($source)) {
                    $warnings[] = 'positive_stock_missing_in_woocommerce';
                }
            }
        }

        $warnings = array_values(array_unique(array_filter($warnings, 'is_string')));
        sort($warnings, SORT_STRING);
        $row['warnings'] = $warnings;
        $row['search'] = $this->search_blob($row);
        return $row;
    }

    private function non_product_source_row(array $record): array {
        $source = $record['product'];
        $states = array();
        foreach (array('product_code', 'name', 'serial', 'foreign_currency', 'foreign_price', 'weight_grams', 'unit', 'total_stock', 'final_price', 'record_hash') as $field) {
            $states[$field] = self::field_state($source, $field);
        }
        $is_quarantine = 'quarantine' === (string) $record['record_type'];
        $warnings = $is_quarantine
            ? array('quarantined_source_record', 'quarantined_without_product')
            : array('source_envelope_warning');
        if (!empty($record['envelope_warnings']) && !in_array('source_envelope_warning', $warnings, true)) {
            $warnings[] = 'source_envelope_warning';
        }
        $row = array(
            'kind' => $is_quarantine ? 'quarantined' : 'source_warning',
            'resolution' => $is_quarantine ? 'quarantined_without_product' : 'source_envelope_warning',
            'source_id' => $record['source_id'],
            'dataset' => $record['dataset'],
            'snapshot_generated_at' => $record['snapshot_generated_at'],
            'source_received_at' => $record['source_received_at'],
            'staged_at' => $record['staged_at'],
            'candidate' => $record['candidate'],
            'has_source_product' => false,
            'quarantined' => $record['quarantined'],
            'preserved_quarantined' => false,
            'stale_since' => '',
            'envelope_warnings' => $record['envelope_warnings'],
            'product_code' => $this->present_string($source, 'product_code'),
            'name' => '',
            'serial' => '',
            'source_fields' => $states,
            'woo' => array(),
            'projection' => array(),
            'meta_drift' => array(),
            'drift' => $this->empty_drift(),
            'warnings' => $warnings,
            'search' => '',
        );
        $row['search'] = $this->search_blob($row);
        return $row;
    }

    private function woo_only_row(array $woo): array {
        $warnings = array('missing_source_product');
        if (array() === $woo['serials']) {
            $warnings[] = 'missing_woocommerce_serial';
        }
        $row = array(
            'kind' => 'woo_only',
            'resolution' => 'missing_source_product',
            'source_id' => '',
            'dataset' => '',
            'snapshot_generated_at' => '',
            'source_received_at' => '',
            'staged_at' => '',
            'candidate' => false,
            'has_source_product' => false,
            'quarantined' => false,
            'preserved_quarantined' => false,
            'stale_since' => '',
            'envelope_warnings' => array(),
            'product_code' => '',
            'name' => (string) $woo['name'],
            'serial' => (string) ($woo['serials'][0] ?? ''),
            'source_fields' => array(),
            'woo' => $woo,
            'projection' => array(),
            'meta_drift' => array(),
            'drift' => $this->empty_drift(),
            'warnings' => $warnings,
            'search' => '',
        );
        $row['search'] = $this->search_blob($row);
        return $row;
    }

    private function projection(array $source, array $desired): array {
        $projection = array();
        $calculation = $desired['calculation'] ?? null;
        if (is_array($calculation)) {
            $projection['price_irr'] = (string) $calculation['woo_final_irr'];
            $projection['price_irt'] = (string) $calculation['native_final_irt'];
        }
        if (array_key_exists('total_stock', $source) && null !== $source['total_stock']) {
            $stock = is_numeric($source['total_stock']) && (float) $source['total_stock'] < 0
                ? 0
                : Decimal_Calculator::stock($source['total_stock'], (string) Config::get('stock_percent', '30'));
            if (null !== $stock) {
                $projection['stock_quantity'] = $stock;
            }
        }
        if (array_key_exists('weight_grams', $source) && null !== $source['weight_grams']) {
            $projection['weight'] = $this->store_weight($source['weight_grams']);
        }
        if (array_key_exists('record_hash', $source)) {
            if (null !== $source['record_hash']) {
                $projection['record_hash'] = $source['record_hash'];
            }
        }
        $projection['managed_meta'] = is_array($desired['meta'] ?? null) ? $desired['meta'] : array();
        return $projection;
    }

    private function drift(array $source, $product, $calculation, array $meta_changes): array {
        $price = false;
        if (is_array($calculation)) {
            $expected = $this->normalize_decimal((string) $calculation['woo_final_irr']);
            $price = $expected !== $this->normalize_decimal((string) $product->get_regular_price('edit'))
                || $expected !== $this->normalize_decimal((string) $product->get_price('edit'))
                || '' !== (string) $product->get_sale_price('edit');
        }

        $stock = false;
        if (array_key_exists('total_stock', $source) && null !== $source['total_stock']) {
            $expected_stock = is_numeric($source['total_stock']) && (float) $source['total_stock'] < 0
                ? 0
                : Decimal_Calculator::stock($source['total_stock'], (string) Config::get('stock_percent', '30'));
            if (null !== $expected_stock) {
                $stock = !$product->get_manage_stock('edit')
                    || null === $product->get_stock_quantity('edit')
                    || $expected_stock !== (int) $product->get_stock_quantity('edit')
                    || ($expected_stock > 0 ? 'instock' : 'outofstock') !== (string) $product->get_stock_status('edit');
            }
        }

        $weight = false;
        if (array_key_exists('weight_grams', $source) && null !== $source['weight_grams']) {
            $weight = $this->normalize_decimal($this->store_weight($source['weight_grams']))
                !== $this->normalize_decimal((string) $product->get_weight('edit'));
        }

        $hash = false;
        if (array_key_exists('record_hash', $source) && null !== $source['record_hash']) {
            $hash = (string) $source['record_hash'] !== (string) $product->get_meta('_ashko_patris_record_hash', true, 'edit');
        }
        $keys = array_keys($meta_changes);
        $product_code = in_array('_ashko_patris_product_code', $keys, true);
        $serial = in_array(Config::OWN_SERIAL_META, $keys, true);
        $cny = array() !== array_intersect($keys, array('_ashko_patris_cny', 'ashko_cny_price'));
        $foreign_currency = in_array('_ashko_patris_foreign_currency', $keys, true);
        $unit = array() !== array_intersect($keys, array('_ashko_patris_unit', 'woodmart_price_unit_of_measure'));
        $source_weight = in_array('_ashko_patris_weight_grams', $keys, true);
        $stock_metadata = array() !== array_filter($keys, static fn(string $key): bool =>
            str_contains($key, 'stock') || str_contains($key, 'allanbar') || str_contains($key, 'warehouse')
        );
        $pricing_metadata = array() !== array_filter($keys, static fn(string $key): bool =>
            str_contains($key, 'shipping')
            || str_contains($key, 'markup')
            || str_contains($key, '_fx_')
            || str_contains($key, 'final_')
            || str_contains($key, 'formula')
            || str_contains($key, 'currency_effective')
            || str_contains($key, 'pricing_catalog')
            || str_contains($key, 'irt_per_cny')
        );
        $metadata = array() !== $meta_changes;
        return compact(
            'price', 'stock', 'weight', 'hash', 'product_code', 'serial', 'cny', 'foreign_currency',
            'unit', 'source_weight', 'stock_metadata', 'pricing_metadata', 'metadata'
        );
    }

    private function woo_snapshot($product): array {
        $serials = array();
        foreach (array((string) Config::get('serial_meta_key', '_sku'), Config::OWN_SERIAL_META) as $key) {
            $value = (string) $product->get_meta($key, true, 'edit');
            if ('' !== $value) {
                $serials[$value] = $value;
            }
        }
        $managed_meta = array();
        foreach (Product_Applicator::instance()->managed_meta_keys() as $key) {
            $managed_meta[$key] = (string) $product->get_meta($key, true, 'edit');
        }
        return array(
            'id' => (int) $product->get_id(),
            'name' => method_exists($product, 'get_name') ? (string) $product->get_name('edit') : '',
            'type' => method_exists($product, 'get_type') ? (string) $product->get_type() : '',
            'serials' => array_values($serials),
            'regular_price' => (string) $product->get_regular_price('edit'),
            'price' => (string) $product->get_price('edit'),
            'sale_price' => (string) $product->get_sale_price('edit'),
            'manage_stock' => (bool) $product->get_manage_stock('edit'),
            'stock_quantity' => null === $product->get_stock_quantity('edit') ? null : (int) $product->get_stock_quantity('edit'),
            'stock_status' => (string) $product->get_stock_status('edit'),
            'weight' => (string) $product->get_weight('edit'),
            'record_hash' => (string) $product->get_meta('_ashko_patris_record_hash', true, 'edit'),
            'managed_meta' => $managed_meta,
        );
    }

    private function empty_drift(): array {
        return array(
            'price' => false,
            'stock' => false,
            'weight' => false,
            'hash' => false,
            'product_code' => false,
            'serial' => false,
            'cny' => false,
            'foreign_currency' => false,
            'unit' => false,
            'source_weight' => false,
            'stock_metadata' => false,
            'pricing_metadata' => false,
            'metadata' => false,
        );
    }

    private function is_variable_parent($product): bool {
        return method_exists($product, 'is_type') && $product->is_type('variable');
    }

    private function exclude_variable_parents_from_resolution($resolution, array $woo, array $variable_parent_ids) {
        if (!is_wp_error($resolution)) {
            return $resolution;
        }
        $data = $resolution->get_error_data();
        if (!is_array($data) || 'duplicate_woocommerce_serial' !== ($data['reason'] ?? '')) {
            return $resolution;
        }
        $original_ids = array_values(array_unique(array_map('intval', (array) ($data['woocommerce_ids'] ?? array()))));
        $eligible_ids = array_values(array_filter($original_ids, static function (int $id) use ($woo, $variable_parent_ids): bool {
            return isset($woo[$id]) && !isset($variable_parent_ids[$id]);
        }));
        if (1 === count($eligible_ids)) {
            return array(
                'woocommerce_id' => (string) $eligible_ids[0],
                'post_type' => (string) ($woo[$eligible_ids[0]]['snapshot']['type'] ?? ''),
                'identifier' => '',
                'resolved_by' => 'exact_serial_after_variable_parent_exclusion',
            );
        }
        $all_original_ids_are_variable_parents = array() !== $original_ids
            && count(array_filter($original_ids, static fn(int $id): bool => isset($variable_parent_ids[$id]))) === count($original_ids);
        if (array() === $eligible_ids && $all_original_ids_are_variable_parents) {
            return array(
                'woocommerce_id' => (string) $original_ids[0],
                'post_type' => 'product',
                'identifier' => '',
                'resolved_by' => 'variable_parent_excluded',
            );
        }
        $data['woocommerce_ids'] = array_map('strval', $eligible_ids);
        return new WP_Error(
            $resolution->get_error_code(),
            $resolution->get_error_message(),
            $data
        );
    }

    private function summary(array $rows): array {
        $summary = array(
            'rows' => count($rows),
            'source_products' => 0,
            'matched' => 0,
            'source_only' => 0,
            'positive_stock_missing_in_woocommerce' => 0,
            'woo_only' => 0,
            'ambiguous' => 0,
            'quarantined_codes' => 0,
            'preserved_quarantined' => 0,
            'source_warning' => 0,
            'envelope_warnings' => 0,
            'warning_rows' => 0,
        );
        foreach (array_keys($this->empty_drift()) as $field) {
            $summary[$field . '_drift'] = 0;
        }
        foreach ($rows as $row) {
            if (!empty($row['has_source_product'])) {
                ++$summary['source_products'];
            }
            if (isset($summary[$row['kind']])) {
                ++$summary[$row['kind']];
            }
            if (in_array('positive_stock_missing_in_woocommerce', $row['warnings'], true)) {
                ++$summary['positive_stock_missing_in_woocommerce'];
            }
            if ($row['warnings']) {
                ++$summary['warning_rows'];
            }
            if (!empty($row['quarantined'])) {
                ++$summary['quarantined_codes'];
            }
            if (!empty($row['preserved_quarantined'])) {
                ++$summary['preserved_quarantined'];
            }
            $summary['envelope_warnings'] += count($row['envelope_warnings'] ?? array());
            foreach (array_keys($this->empty_drift()) as $field) {
                if (!empty($row['drift'][$field])) {
                    ++$summary[$field . '_drift'];
                }
            }
        }
        return $summary;
    }

    private function warning_counts(array $rows): array {
        $counts = array();
        foreach ($rows as $row) {
            foreach ($row['warnings'] as $warning) {
                $counts[$warning] = ($counts[$warning] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    private function positive_stock(array $source): bool {
        return array_key_exists('total_stock', $source)
            && null !== $source['total_stock']
            && is_numeric($source['total_stock'])
            && (float) $source['total_stock'] > 0;
    }

    private function present_string(array $source, string $key): string {
        return array_key_exists($key, $source) && null !== $source[$key] && is_scalar($source[$key])
            ? (string) $source[$key]
            : '';
    }

    private function store_weight($grams): string {
        $unit = (string) get_option('woocommerce_weight_unit', 'g');
        if ('g' === $unit) {
            return (string) $grams;
        }
        if (function_exists('wc_get_weight')) {
            $converted = wc_get_weight((float) $grams, $unit, 'g');
            return function_exists('wc_format_decimal') ? wc_format_decimal($converted, 8, true) : (string) $converted;
        }
        return (string) $grams;
    }

    private function normalize_decimal(string $value): string {
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
            return $value;
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return '' === $value || '-0' === $value ? '0' : $value;
    }

    private function search_blob(array $row): string {
        $values = array(
            $row['source_id'] ?? '',
            $row['dataset'] ?? '',
            $row['product_code'] ?? '',
            $row['name'] ?? '',
            $row['serial'] ?? '',
            $row['woo']['id'] ?? '',
            $row['woo']['name'] ?? '',
            implode(' ', $row['woo']['serials'] ?? array()),
            implode(' ', $row['warnings'] ?? array()),
            implode(' ', $row['envelope_warnings'] ?? array()),
            $row['snapshot_generated_at'] ?? '',
            $row['stale_since'] ?? '',
            implode(' ', array_keys($row['meta_drift'] ?? array())),
        );
        return $this->lower(implode("\n", array_map('strval', $values)));
    }

    private function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
