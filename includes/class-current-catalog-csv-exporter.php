<?php
namespace Ashko\Patris;

use WP_Error;

/**
 * Writes a current-catalog CSV to a caller-owned stream.
 *
 * The exporter drains report rows after a preflight count. The admin action can
 * therefore write to a file-backed temporary stream, release the report graph,
 * and only then copy the completed bytes to the HTTP response.
 */
final class Current_Catalog_Csv_Exporter {
    public const MAX_RUNTIME_SECONDS = 120;
    public const OUTPUT_CHUNK_BYTES = 1048576;
    private const FLUSH_EVERY_ROWS = 250;

    private Current_Catalog_Report $report_service;

    public function __construct(?Current_Catalog_Report $report_service = null) {
        $this->report_service = $report_service ?? new Current_Catalog_Report();
    }

    public static function prepare_request(): void {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_RUNTIME_SECONDS);
        }
    }

    /** @return array{rows:int,bytes:int}|WP_Error */
    public function write($stream, array &$report, array $criteria) {
        if (!is_resource($stream)) {
            return $this->stream_error();
        }

        $matching = $this->report_service->filtered_row_count(
            $report,
            $criteria,
            Current_Catalog_Report::MAX_CSV_ROWS + 1
        );
        if ($matching > Current_Catalog_Report::MAX_CSV_ROWS) {
            return new WP_Error(
                'ashko_current_catalog_csv_row_limit',
                sprintf(
                    __('نتیجه CSV از سقف امن %d ردیف بیشتر است؛ فیلتر محدودتری انتخاب کنید.', 'ashko-wp'),
                    Current_Catalog_Report::MAX_CSV_ROWS
                )
            );
        }

        if (3 !== fwrite($stream, "\xEF\xBB\xBF") || false === $this->put_row($stream, self::headers(), false)) {
            return $this->stream_error();
        }

        $written = 0;
        $rows =& $report['rows'];
        foreach ($this->report_service->drain_filtered_rows($rows, $criteria) as $row) {
            if (false === $this->put_row($stream, self::values($report, $row), true)) {
                return $this->stream_error();
            }
            ++$written;
            if (0 === $written % self::FLUSH_EVERY_ROWS && !fflush($stream)) {
                return $this->stream_error();
            }
        }
        if (!fflush($stream)) {
            return $this->stream_error();
        }
        $bytes = ftell($stream);
        if (false === $bytes) {
            return $this->stream_error();
        }
        return array('rows' => $written, 'bytes' => (int) $bytes);
    }

    /** @return string[] */
    public static function headers(): array {
        return array(
            'snapshot_kind', 'report_generated_at', 'staged_at', 'candidate', 'kind', 'resolution', 'source_id', 'dataset',
            'snapshot_generated_at', 'source_received_at', 'quarantined', 'preserved_quarantined', 'stale_since',
            'envelope_warnings', 'product_code_state', 'product_code',
            'name_state', 'name', 'serial_state', 'serial', 'foreign_currency_state', 'foreign_currency',
            'cny_state', 'cny', 'partner_price_state', 'partner_price_irr',
            'direct_sale_price_state', 'direct_sale_price_irr',
            'price_source_amount_state', 'price_source_amount', 'price_source_currency_state', 'price_source_currency',
            'price_source_kind_state', 'price_source_kind', 'price_rounding_digits_state', 'price_rounding_digits',
            'price_rounding_mode_state', 'price_rounding_mode',
            'source_shipping_method_state', 'source_shipping_method_id',
            'source_shipping_rate_state', 'source_shipping_price_per_kg',
            'source_shipping_currency_state', 'source_shipping_price_per_kg_currency',
            'weight_state', 'weight_grams', 'unit_state', 'unit',
            'stock_state', 'total_stock', 'woo_id', 'woo_name', 'woo_serials', 'woo_regular_price_irr',
            'woo_post_status', 'projected_post_status', 'woo_image_id',
            'source_final_price_state', 'source_final_price_irt', 'projected_price_irr', 'woo_stock', 'projected_stock',
            'woo_weight', 'projected_weight', 'source_record_hash_state', 'source_record_hash',
            'store_currency', 'fx_irr_per_cny', 'shipping_method_id', 'shipping_price_per_kg', 'shipping_price_per_kg_currency',
            'effective_shipping_method_id', 'effective_shipping_price_per_kg', 'effective_shipping_price_per_kg_currency',
            'domestic_shipping_method_id', 'domestic_shipping_price_per_kg', 'domestic_shipping_price_per_kg_currency',
            'use_sale_price_direct_fallback',
            'profit_margin_percent', 'effective_price_rounding_digits', 'effective_price_rounding_mode',
            'stock_percent', 'price_formula', 'stock_formula',
            'woo_product_code_meta', 'expected_product_code_meta', 'woo_canonical_serial_meta', 'expected_canonical_serial_meta',
            'woo_cny_meta', 'expected_cny_meta', 'woo_foreign_currency_meta', 'expected_foreign_currency_meta',
            'woo_unit_meta', 'expected_unit_meta', 'woo_managed_meta_json', 'expected_managed_meta_json',
            'core_changes_json', 'meta_changes_json', 'publication_safety_json',
            'price_drift', 'stock_drift', 'weight_drift', 'hash_drift', 'product_code_drift', 'serial_drift',
            'cny_drift', 'foreign_currency_drift', 'unit_drift', 'source_weight_drift', 'stock_metadata_drift',
            'pricing_metadata_drift', 'metadata_drift', 'publication_drift', 'warnings',
        );
    }

    /** @return mixed[] */
    public static function values(array $report, array $row): array {
        $product_code = self::source_field($row, 'product_code');
        $name = self::source_field($row, 'name');
        $serial = self::source_field($row, 'serial');
        $foreign_currency = self::source_field($row, 'foreign_currency');
        $cny = self::source_field($row, 'foreign_price');
        $partner_price = self::source_field($row, 'partner_price_source');
        $direct_sale_price = self::source_field($row, 'sale_price_source');
        $price_source_amount = self::source_field($row, 'price_source_amount');
        $price_source_currency = self::source_field($row, 'price_source_currency');
        $price_source_kind = self::source_field($row, 'price_source_kind');
        $rounding_digits = self::source_field($row, 'price_rounding_digits');
        $rounding_mode = self::source_field($row, 'price_rounding_mode');
        $source_shipping_method = self::source_field($row, 'shipping_method_id');
        $source_shipping_rate = self::source_field($row, 'shipping_price_per_kg');
        $source_shipping_currency = self::source_field($row, 'shipping_price_per_kg_currency');
        $weight = self::source_field($row, 'weight_grams');
        $unit = self::source_field($row, 'unit');
        $stock = self::source_field($row, 'total_stock');
        $source_final = self::source_field($row, 'final_price');
        $source_hash = self::source_field($row, 'record_hash');
        $woo = is_array($row['woo'] ?? null) ? $row['woo'] : array();
        $projection = is_array($row['projection'] ?? null) ? $row['projection'] : array();
        $woo_meta = is_array($woo['managed_meta'] ?? null) ? $woo['managed_meta'] : array();
        $expected_meta = is_array($projection['managed_meta'] ?? null) ? $projection['managed_meta'] : array();
        $provenance = is_array($report['provenance'] ?? null) ? $report['provenance'] : array();

        return array(
            $report['snapshot_kind'] ?? '', $report['generated_at'] ?? '', $report['staged_at'] ?? '', $row['candidate'] ?? '',
            $row['kind'] ?? '', $row['resolution'] ?? '', $row['source_id'] ?? '', $row['dataset'] ?? '',
            $row['snapshot_generated_at'] ?? '', $row['source_received_at'] ?? '', $row['quarantined'] ?? '',
            $row['preserved_quarantined'] ?? '', $row['stale_since'] ?? '',
            implode('|', $row['envelope_warnings'] ?? array()),
            $product_code['state'], $product_code['value'], $name['state'], $name['value'],
            $serial['state'], $serial['value'], $foreign_currency['state'], $foreign_currency['value'],
            $cny['state'], $cny['value'],
            $partner_price['state'], $partner_price['value'],
            $direct_sale_price['state'], $direct_sale_price['value'],
            $price_source_amount['state'], $price_source_amount['value'],
            $price_source_currency['state'], $price_source_currency['value'],
            $price_source_kind['state'], $price_source_kind['value'],
            $rounding_digits['state'], $rounding_digits['value'],
            $rounding_mode['state'], $rounding_mode['value'],
            $source_shipping_method['state'], $source_shipping_method['value'],
            $source_shipping_rate['state'], $source_shipping_rate['value'],
            $source_shipping_currency['state'], $source_shipping_currency['value'],
            $weight['state'], $weight['value'], $unit['state'], $unit['value'], $stock['state'], $stock['value'],
            $woo['id'] ?? '', $woo['name'] ?? '', implode('|', $woo['serials'] ?? array()),
            $woo['regular_price'] ?? '', $woo['post_status'] ?? '', $projection['post_status'] ?? '',
            $woo['image_id'] ?? '', $source_final['state'], $source_final['value'],
            $projection['price_irr'] ?? '', $woo['stock_quantity'] ?? '',
            $projection['stock_quantity'] ?? '', $woo['weight'] ?? '', $projection['weight'] ?? '',
            $source_hash['state'], $source_hash['value'],
            $provenance['store_currency'] ?? '', $provenance['fx_irr_per_cny'] ?? '', $provenance['shipping_method_id'] ?? '',
            $provenance['shipping_price_per_kg'] ?? '', $provenance['shipping_price_per_kg_currency'] ?? '',
            $expected_meta['_ashko_patris_shipping_method_id'] ?? '',
            $expected_meta['_ashko_patris_shipping_price_per_kg'] ?? '',
            $expected_meta['_ashko_patris_shipping_price_per_kg_currency'] ?? '',
            $provenance['domestic_shipping_method_id'] ?? '', $provenance['domestic_shipping_price_per_kg'] ?? '',
            $provenance['domestic_shipping_price_per_kg_currency'] ?? '',
            $provenance['use_sale_price_direct_fallback'] ?? '',
            $provenance['profit_margin_percent'] ?? '', $provenance['price_rounding_digits'] ?? '',
            $provenance['price_rounding_mode'] ?? '', $provenance['stock_percent'] ?? '',
            $provenance['price_formula'] ?? '', $provenance['stock_formula'] ?? '',
            $woo_meta['_ashko_patris_product_code'] ?? '', $expected_meta['_ashko_patris_product_code'] ?? '',
            $woo_meta[Config::OWN_SERIAL_META] ?? '', $expected_meta[Config::OWN_SERIAL_META] ?? '',
            $woo_meta['_ashko_patris_cny'] ?? '', $expected_meta['_ashko_patris_cny'] ?? '',
            $woo_meta['_ashko_patris_foreign_currency'] ?? '', $expected_meta['_ashko_patris_foreign_currency'] ?? '',
            $woo_meta['_ashko_patris_unit'] ?? '', $expected_meta['_ashko_patris_unit'] ?? '',
            $woo_meta, $expected_meta, $row['core_changes'] ?? array(), $row['meta_drift'] ?? array(),
            $row['publication_safety'] ?? array(),
            !empty($row['drift']['price']), !empty($row['drift']['stock']), !empty($row['drift']['weight']),
            !empty($row['drift']['hash']), !empty($row['drift']['product_code']), !empty($row['drift']['serial']),
            !empty($row['drift']['cny']), !empty($row['drift']['foreign_currency']), !empty($row['drift']['unit']),
            !empty($row['drift']['source_weight']), !empty($row['drift']['stock_metadata']),
            !empty($row['drift']['pricing_metadata']), !empty($row['drift']['metadata']),
            !empty($row['drift']['publication']), implode('|', $row['warnings'] ?? array()),
        );
    }

    private function put_row($stream, array $values, bool $sanitize) {
        if ($sanitize) {
            $values = array_map(array(Current_Catalog_Report::class, 'csv_cell'), $values);
        }
        return fputcsv($stream, $values, ',', '"', '');
    }

    private static function source_field(array $row, string $field): array {
        $source_field = $row['source_fields'][$field] ?? array('state' => 'not_applicable');
        $value = array_key_exists('value', $source_field) ? $source_field['value'] : '';
        return array('state' => (string) $source_field['state'], 'value' => $value);
    }

    private function stream_error(): WP_Error {
        return new WP_Error(
            'ashko_current_catalog_csv_stream_failed',
            __('خروجی CSV باز نشد.', 'ashko-wp')
        );
    }
}
