<?php
use Ashko\Patris\Current_Catalog_Report;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-current-catalog-report.php';

final class CurrentCatalogReportTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_products'] = array();
        $GLOBALS['ashko_test_serial_rows'] = array();
        unset($GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION]);
        unset($GLOBALS['ashko_test_options'][Current_Catalog_Report::STAGED_OPTION]);
        unset($GLOBALS['ashko_test_options'][Ashko\Patris\Product_Sync_Receiver::STATE_OPTION]);
    }

    public function test_complete_snapshot_reports_exact_matches_both_one_sided_sets_and_excludes_variable_parents(): void {
        $matched = new Ashko_Test_Product(10, array(
            'name' => 'کالای یک', 'regular_price' => '390000', 'price' => '390000', 'weight' => '0',
            'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock',
        ), array('_sku' => 'SER-1', '_ashko_patris_record_hash' => 'sha256:one'));
        $woo_only = new Ashko_Test_Product(20, array('name' => 'فقط فروشگاه'), array('_sku' => 'WOO-ONLY'));
        $variable_parent = new Ashko_Test_Product(30, array('name' => 'والد', 'type' => 'variable'), array('_sku' => 'PARENT'));
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '10', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'SER-1'),
            array('ID' => '30', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'PARENT'),
        );

        $state = $this->state(array(
            'P1' => $this->source('P1', 'SER-1', 10, 'sha256:one'),
            'P2' => $this->source('P2', 'MISSING', 8, 'sha256:two'),
            'P3' => array(
                'product_code' => 'P3', 'foreign_currency' => 'CNY', 'foreign_price' => null,
                'warnings' => array(), 'record_hash' => 'sha256:three',
            ),
            'P4' => $this->source('P4', 'PARENT', 5, 'sha256:four'),
        ));

        $report = (new Current_Catalog_Report())->build($state, array($matched, $woo_only, $variable_parent));

        self::assertFalse(is_wp_error($report));
        self::assertSame(4, $report['summary']['source_products']);
        self::assertSame(1, $report['summary']['matched']);
        self::assertSame(3, $report['summary']['source_only']);
        self::assertSame(2, $report['summary']['positive_stock_missing_in_woocommerce']);
        self::assertSame(1, $report['summary']['woo_only']);
        self::assertSame(1, $report['summary']['variable_parents_excluded']);
        self::assertSame(5, $report['summary']['rows']);

        self::assertSame('variable_parent_excluded', $this->row($report['rows'], 'P4')['resolution']);

        $p3 = $this->row($report['rows'], 'P3');
        self::assertSame('null', $p3['source_fields']['foreign_price']['state']);
        self::assertArrayHasKey('value', $p3['source_fields']['foreign_price']);
        self::assertNull($p3['source_fields']['foreign_price']['value']);
        self::assertSame('omitted', $p3['source_fields']['weight_grams']['state']);
        self::assertArrayNotHasKey('value', $p3['source_fields']['weight_grams']);
        self::assertContains('source_explicit_null_foreign_price', $p3['warnings']);
        self::assertContains('source_omitted_weight_grams', $p3['warnings']);
    }

    public function test_price_stock_weight_and_hash_drift_are_independent(): void {
        $product = new Ashko_Test_Product(11, array(
            'regular_price' => '1', 'price' => '390000', 'sale_price' => '', 'weight' => '0',
            'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock',
        ), array('_sku' => 'SER-2', '_ashko_patris_record_hash' => 'sha256:old'));
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '11', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'SER-2'),
        );
        $source = $this->source('P4', 'SER-2', 10, 'sha256:new');
        $source['weight_grams'] = 2;

        $report = (new Current_Catalog_Report())->build($this->state(array('P4' => $source)), array($product));
        $row = $this->row($report['rows'], 'P4');

        self::assertTrue($row['drift']['price']);
        self::assertFalse($row['drift']['stock']);
        self::assertTrue($row['drift']['weight']);
        self::assertTrue($row['drift']['hash']);
        self::assertSame(1, $report['summary']['price_drift']);
        self::assertSame(0, $report['summary']['stock_drift']);
        self::assertSame(1, $report['summary']['weight_drift']);
        self::assertSame(1, $report['summary']['hash_drift']);
    }

    public function test_variable_parent_collision_does_not_make_an_exact_variation_match_ambiguous(): void {
        $parent = new Ashko_Test_Product(40, array('name' => 'والد', 'type' => 'variable'), array('_sku' => 'SHARED'));
        $variation = new Ashko_Test_Product(41, array(
            'name' => 'گونه', 'type' => 'variation', 'regular_price' => '390000', 'price' => '390000',
            'weight' => '0', 'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock',
        ), array('_sku' => 'SHARED', '_ashko_patris_record_hash' => 'sha256:shared'));
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '40', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'SHARED'),
            array('ID' => '41', 'post_type' => 'product_variation', 'meta_key' => '_sku', 'meta_value' => 'SHARED'),
        );

        $report = (new Current_Catalog_Report())->build(
            $this->state(array('P5' => $this->source('P5', 'SHARED', 10, 'sha256:shared'))),
            array($parent, $variation)
        );

        $row = $this->row($report['rows'], 'P5');
        self::assertSame('matched', $row['kind']);
        self::assertSame(41, $row['woo']['id']);
        self::assertSame(0, $report['summary']['ambiguous']);
        self::assertSame(1, $report['summary']['variable_parents_excluded']);
    }

    public function test_search_scope_warning_filter_and_paging_are_bounded(): void {
        $report = array('rows' => array(
            array('kind' => 'source_only', 'warnings' => array('missing_cny'), 'drift' => array(), 'search' => 'alpha p1'),
            array('kind' => 'matched', 'warnings' => array('price_drift'), 'drift' => array('price' => true), 'search' => 'beta p2'),
            array('kind' => 'woo_only', 'warnings' => array('missing_source_product'), 'drift' => array(), 'search' => 'gamma'),
        ));
        $service = new Current_Catalog_Report();

        self::assertCount(1, $service->filtered_rows($report, array('search' => 'P1')));
        self::assertCount(1, $service->filtered_rows($report, array('scope' => 'drift')));
        self::assertCount(1, $service->filtered_rows($report, array('warning' => 'missing_source_product')));
        $page = $service->page(array_fill(0, 205, array()), 2, 1000);
        self::assertSame(100, $page['page_size']);
        self::assertSame(3, $page['pages']);
        self::assertCount(100, $page['rows']);
    }

    public function test_csv_cells_neutralize_formulas_and_bound_cell_size(): void {
        self::assertSame("'=SUM(A1:A2)", Current_Catalog_Report::csv_cell('=SUM(A1:A2)'));
        self::assertSame("'  +cmd", Current_Catalog_Report::csv_cell('  +cmd'));
        self::assertSame("'-12", Current_Catalog_Report::csv_cell('-12'));
        self::assertSame('safe', Current_Catalog_Report::csv_cell('safe'));
        self::assertLessThanOrEqual(
            Current_Catalog_Report::MAX_CSV_CELL_BYTES,
            strlen(Current_Catalog_Report::csv_cell(str_repeat('x', Current_Catalog_Report::MAX_CSV_CELL_BYTES + 100)))
        );
    }

    public function test_validated_dry_run_projection_is_staged_separately_and_reportable_before_apply(): void {
        $json = file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json');
        $preview = Ashko\Patris\Product_Sync_Receiver::instance()->preview_json($json);
        self::assertFalse(is_wp_error($preview));

        $staged = Current_Catalog_Report::stage_preview($preview);

        self::assertFalse(is_wp_error($staged));
        self::assertSame(2, $staged['products']);
        self::assertTrue(Current_Catalog_Report::has_staged_candidate());
        self::assertArrayNotHasKey(Ashko\Patris\Product_Sync_Receiver::STATE_OPTION, $GLOBALS['ashko_test_options']);

        $report = (new Current_Catalog_Report())->build(null, array(), 'candidate');
        self::assertSame('candidate', $report['snapshot_kind']);
        self::assertSame(2, $report['summary']['source_products']);
        self::assertNotSame('', $report['staged_at']);

        Current_Catalog_Report::clear_staged_source(
            $preview['envelope']['source']['id'],
            $preview['envelope']['source']['dataset'],
            $preview['envelope']['event_id']
        );
        self::assertFalse(Current_Catalog_Report::has_staged_candidate());
    }

    public function test_quarantine_envelope_warnings_and_preserved_staleness_are_complete(): void {
        $source = $this->source('P-OLD', 'SER-OLD', 10, 'sha256:old');
        $product = new Ashko_Test_Product(70, array(
            'regular_price' => '390000', 'price' => '390000', 'weight' => '0',
            'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock',
        ), array('_sku' => 'SER-OLD', '_ashko_patris_record_hash' => 'sha256:old'));
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '70', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'SER-OLD'),
        );
        $state = array('sources' => array('source-key' => array(
            'source' => array('id' => 'ashco-office', 'dataset' => 'kala.db'),
            'generated_at' => '2026-07-21T00:00:00Z',
            'products' => array('P-OLD' => $source),
            'quarantined_codes' => array('P-NEW', 'P-OLD'),
            'preserved_quarantined_codes' => array('P-OLD'),
            'preserved_product_generated_at' => array('P-OLD' => '2026-07-19T00:00:00Z'),
            'envelope_warnings' => array(
                'ambiguous_catalog_record:P-NEW',
                'ambiguous_catalog_record:P-OLD',
                'catalog_source_attention',
            ),
        )));

        $report = (new Current_Catalog_Report())->build($state, array($product));

        self::assertSame(2, $report['summary']['quarantined_codes']);
        self::assertSame(1, $report['summary']['preserved_quarantined']);
        self::assertSame(3, $report['summary']['envelope_warnings']);
        self::assertSame(1, $report['summary']['source_warning']);
        $old = $this->row($report['rows'], 'P-OLD');
        self::assertTrue($old['quarantined']);
        self::assertTrue($old['preserved_quarantined']);
        self::assertSame('2026-07-19T00:00:00Z', $old['stale_since']);
        self::assertContains('quarantined_preserved_stale', $old['warnings']);
        self::assertSame(array('ambiguous_catalog_record:P-OLD'), $old['envelope_warnings']);
        $new = $this->row($report['rows'], 'P-NEW');
        self::assertSame('quarantined', $new['kind']);
        self::assertContains('quarantined_without_product', $new['warnings']);
        self::assertCount(1, array_filter($report['rows'], static fn(array $row): bool => 'source_warning' === $row['kind']));
    }

    public function test_all_managed_woocommerce_metadata_is_compared_independently(): void {
        $product = new Ashko_Test_Product(77, array(
            'name' => 'کالا', 'regular_price' => '418600', 'price' => '418600', 'sale_price' => '', 'weight' => '1',
            'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock',
        ), array(
            '_sku' => 'SER-77', '_ashko_patris_record_hash' => 'sha256:same',
            '_ashko_patris_serial' => 'WRONG-SERIAL', '_ashko_patris_unit' => 'بسته',
            '_ashko_patris_cny' => '999', '_ashko_patris_foreign_currency' => 'IRR',
            '_ashko_patris_product_code' => 'WRONG-CODE',
        ));
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '77', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'SER-77'),
        );
        $source = array(
            'product_code' => 'P77', 'name' => 'کالا', 'serial' => 'SER-77', 'unit' => 'عدد',
            'foreign_currency' => 'CNY', 'foreign_price' => '1', 'weight_grams' => '1',
            'total_stock' => '10', 'warnings' => array(), 'record_hash' => 'sha256:same',
        );
        $report = (new Current_Catalog_Report())->build($this->state(array('P77' => $source)), array($product));
        $row = $this->row($report['rows'], 'P77');

        self::assertFalse($row['drift']['price']);
        self::assertFalse($row['drift']['stock']);
        self::assertFalse($row['drift']['weight']);
        self::assertFalse($row['drift']['hash']);
        foreach (array('product_code', 'serial', 'cny', 'foreign_currency', 'unit', 'metadata') as $field) {
            self::assertTrue($row['drift'][$field], $field . ' drift was not reported');
        }
        foreach (array('_ashko_patris_product_code', '_ashko_patris_serial', '_ashko_patris_cny', '_ashko_patris_foreign_currency', '_ashko_patris_unit') as $key) {
            self::assertArrayHasKey($key, $row['meta_drift']);
            self::assertArrayHasKey($key, $row['woo']['managed_meta']);
            self::assertArrayHasKey($key, $row['projection']['managed_meta']);
        }
        self::assertSame('300000', $report['provenance']['fx_irr_per_cny']);
        self::assertSame('IRR', $report['provenance']['shipping_price_per_kg_currency']);
        self::assertSame(Ashko\Patris\Decimal_Calculator::PRICE_FORMULA, $report['provenance']['price_formula']);
        self::assertSame(Ashko\Patris\Decimal_Calculator::STOCK_FORMULA, $report['provenance']['stock_formula']);
    }

    public function test_pricing_provenance_keeps_cny_freight_currency_explicit(): void {
        $GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION] = array(
            'fx_irr_per_cny' => '300000',
            'shipping_price_per_kg' => '73.5',
            'shipping_price_per_kg_currency' => 'CNY',
            'profit_margin_percent' => '30',
            'stock_percent' => '30',
            'default_shipping_method' => 'air_express',
        );

        $report = (new Current_Catalog_Report())->build(array('sources' => array()), array());

        self::assertSame('73.5', $report['provenance']['shipping_price_per_kg']);
        self::assertSame('CNY', $report['provenance']['shipping_price_per_kg_currency']);
        self::assertSame('30', $report['provenance']['profit_margin_percent']);
        self::assertSame('30', $report['provenance']['stock_percent']);
    }

    private function source(string $code, string $serial, int $stock, string $hash): array {
        return array(
            'product_code' => $code, 'name' => 'کالا ' . $code, 'serial' => $serial, 'unit' => 'عدد',
            'foreign_currency' => 'CNY', 'foreign_price' => 1, 'weight_grams' => 0,
            'total_stock' => $stock, 'warnings' => array(), 'record_hash' => $hash,
        );
    }

    private function state(array $products): array {
        return array('sources' => array('source-key' => array(
            'source' => array('id' => 'ashco-office', 'dataset' => 'kala.db'),
            'generated_at' => '2026-07-21T00:00:00Z',
            'products' => $products,
        )));
    }

    private function row(array $rows, string $code): array {
        foreach ($rows as $row) {
            if ($code === $row['product_code']) {
                return $row;
            }
        }
        self::fail('Report row not found: ' . $code);
    }
}
