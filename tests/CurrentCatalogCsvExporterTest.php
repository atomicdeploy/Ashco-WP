<?php
use Ashko\Patris\Current_Catalog_Csv_Exporter;
use Ashko\Patris\Current_Catalog_Report;
use PHPUnit\Framework\TestCase;

final class CurrentCatalogCsvExporterTest extends TestCase {
    public function test_unfiltered_production_sized_export_is_file_backed_and_drains_the_report_graph(): void {
        $report = $this->report(3958);
        $memory_with_rows = memory_get_usage();
        $stream = tmpfile();
        self::assertIsResource($stream);

        $result = (new Current_Catalog_Csv_Exporter())->write(
            $stream,
            $report,
            array('scope' => 'all', 'search' => '', 'warning' => '')
        );

        self::assertFalse(is_wp_error($result));
        self::assertSame(3958, $result['rows']);
        self::assertGreaterThan(500000, $result['bytes']);
        self::assertSame(array(), $report['rows']);
        self::assertLessThan($memory_with_rows, memory_get_usage());

        rewind($stream);
        $headers = fgetcsv($stream, null, ',', '"', '');
        self::assertIsArray($headers);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        self::assertSame(Current_Catalog_Csv_Exporter::headers(), $headers);

        $first = fgetcsv($stream, null, ',', '"', '');
        self::assertIsArray($first);
        self::assertCount(count($headers), $first);
        $record = array_combine($headers, $first);
        self::assertSame('null', $record['cny_state']);
        self::assertSame('', $record['cny']);
        self::assertSame('omitted', $record['weight_state']);
        self::assertSame('', $record['weight_grams']);
        self::assertSame("'=HYPERLINK(\"https://invalid.test\")", $record['woo_name']);
        self::assertSame(
            '{"_ashko_patris_unit":"عدد","_ashko_patris_shipping_method_id":"domestic","_ashko_patris_shipping_price_per_kg":"0","_ashko_patris_shipping_price_per_kg_currency":"IRR"}',
            $record['expected_managed_meta_json']
        );

        $rows = 1;
        while (false !== fgetcsv($stream, null, ',', '"', '')) {
            ++$rows;
        }
        self::assertSame(3958, $rows);
        fclose($stream);
    }

    public function test_preflight_rejects_more_than_the_exact_safe_row_limit_without_writing_or_draining(): void {
        $row = $this->row(1);
        $report = array(
            'snapshot_kind' => 'applied',
            'generated_at' => '2026-07-28T10:00:00Z',
            'staged_at' => '',
            'provenance' => array(),
            'rows' => array_fill(0, Current_Catalog_Report::MAX_CSV_ROWS + 1, $row),
        );
        $stream = tmpfile();

        $result = (new Current_Catalog_Csv_Exporter())->write($stream, $report, array('scope' => 'all'));

        self::assertTrue(is_wp_error($result));
        self::assertSame('ashko_current_catalog_csv_row_limit', $result->get_error_code());
        self::assertSame(Current_Catalog_Report::MAX_CSV_ROWS + 1, count($report['rows']));
        self::assertSame(0, ftell($stream));
        fclose($stream);
    }

    public function test_filtered_export_preserves_existing_matching_semantics_while_draining_nonmatches(): void {
        $report = $this->report(12);
        foreach ($report['rows'] as $index => &$row) {
            $row['kind'] = 0 === $index % 2 ? 'matched' : 'source_only';
            $row['warnings'] = 0 === $index % 3 ? array('needs_attention') : array();
            $row['search'] = 0 === $index % 4 ? 'needle' : 'other';
        }
        unset($row);
        $expected = (new Current_Catalog_Report())->filtered_rows(
            $report,
            array('scope' => 'matched', 'warning' => 'needs_attention', 'search' => 'needle')
        );
        $stream = tmpfile();

        $result = (new Current_Catalog_Csv_Exporter())->write(
            $stream,
            $report,
            array('scope' => 'matched', 'warning' => 'needs_attention', 'search' => 'needle')
        );

        self::assertFalse(is_wp_error($result));
        self::assertSame(count($expected), $result['rows']);
        self::assertSame(1, $result['rows']);
        self::assertSame(array(), $report['rows']);
        fclose($stream);
    }

    private function report(int $rows): array {
        $report = array(
            'snapshot_kind' => 'applied',
            'generated_at' => '2026-07-28T10:00:00Z',
            'staged_at' => '',
            'provenance' => array(
                'store_currency' => 'IRR',
                'fx_irr_per_cny' => '300000',
                'shipping_method_id' => 'air_express',
                'shipping_price_per_kg' => '22000000',
                'shipping_price_per_kg_currency' => 'IRR',
                'domestic_shipping_method_id' => 'domestic',
                'domestic_shipping_price_per_kg' => '0',
                'domestic_shipping_price_per_kg_currency' => 'IRR',
                'use_sale_price_direct_fallback' => 'no',
                'profit_margin_percent' => '30',
                'price_rounding_digits' => '2',
                'price_rounding_mode' => 'nearest_half_up',
                'stock_percent' => '30',
                'price_formula' => 'formula',
                'stock_formula' => 'stock formula',
            ),
            'rows' => array(),
        );
        for ($index = 1; $index <= $rows; ++$index) {
            $report['rows'][] = $this->row($index);
        }
        return $report;
    }

    private function row(int $index): array {
        return array(
            'candidate' => false,
            'kind' => 'matched',
            'resolution' => 'exact_serial',
            'source_id' => 'ashco-office',
            'dataset' => 'kala.db',
            'snapshot_generated_at' => '2026-07-28T09:00:00Z',
            'source_received_at' => '2026-07-28T09:01:00Z',
            'quarantined' => false,
            'preserved_quarantined' => false,
            'stale_since' => '',
            'envelope_warnings' => array(),
            'source_fields' => array(
                'product_code' => array('state' => 'value', 'value' => 'P-' . $index),
                'name' => array('state' => 'value', 'value' => 'کالای ' . $index),
                'serial' => array('state' => 'value', 'value' => 'SER-' . $index),
                'foreign_currency' => array('state' => 'value', 'value' => 'CNY'),
                'foreign_price' => array('state' => 'null', 'value' => null),
                'partner_price_source' => array('state' => 'value', 'value' => '9000'),
                'sale_price_source' => array('state' => 'value', 'value' => '8000'),
                'price_source_amount' => array('state' => 'value', 'value' => '9000'),
                'price_source_currency' => array('state' => 'value', 'value' => 'IRR'),
                'price_source_kind' => array('state' => 'value', 'value' => 'partner_price'),
                'price_rounding_digits' => array('state' => 'value', 'value' => 2),
                'price_rounding_mode' => array('state' => 'value', 'value' => 'nearest_half_up'),
                'shipping_method_id' => array('state' => 'value', 'value' => 'domestic'),
                'shipping_price_per_kg' => array('state' => 'value', 'value' => '0'),
                'shipping_price_per_kg_currency' => array('state' => 'value', 'value' => 'IRR'),
                'weight_grams' => array('state' => 'omitted'),
                'unit' => array('state' => 'value', 'value' => 'عدد'),
                'total_stock' => array('state' => 'value', 'value' => 2),
                'final_price' => array('state' => 'value', 'value' => '1200'),
                'record_hash' => array('state' => 'value', 'value' => 'sha256:' . str_pad((string) $index, 64, '0', STR_PAD_LEFT)),
            ),
            'woo' => array(
                'id' => 1000 + $index,
                'name' => '=HYPERLINK("https://invalid.test")',
                'serials' => array('SER-' . $index),
                'regular_price' => '12000',
                'post_status' => 'publish',
                'image_id' => 10,
                'stock_quantity' => 1,
                'weight' => '',
                'managed_meta' => array('_ashko_patris_unit' => 'عدد'),
            ),
            'projection' => array(
                'price_irr' => '12000',
                'stock_quantity' => 1,
                'managed_meta' => array(
                    '_ashko_patris_unit' => 'عدد',
                    '_ashko_patris_shipping_method_id' => 'domestic',
                    '_ashko_patris_shipping_price_per_kg' => '0',
                    '_ashko_patris_shipping_price_per_kg_currency' => 'IRR',
                ),
            ),
            'core_changes' => array(),
            'meta_drift' => array(),
            'publication_safety' => array(),
            'drift' => array(
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
                'publication' => false,
            ),
            'warnings' => array(),
            'search' => 'p-' . $index . ' ser-' . $index,
        );
    }
}
