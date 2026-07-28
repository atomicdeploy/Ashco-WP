<?php
use Ashko\Patris\Product_Applicator;
use PHPUnit\Framework\TestCase;

final class ProductApplicatorTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION]);
    }

    private function data(): array {
        return array(
            'product_code' => '101023', 'category_code' => '101', 'name' => 'Part', 'serial' => 'B 32', 'unit' => 'عدد',
            'warehouse_stock' => array('1' => 10), 'total_stock' => 10, 'foreign_currency' => 'CNY',
            'foreign_price' => 0.0215, 'price_source_amount' => 0.0215,
            'price_source_currency' => 'CNY', 'price_source_kind' => 'foreign_price',
            'weight_grams' => 2, 'shipping_method_id' => 'air_express',
            'shipping_price_per_kg' => 73.333333333333, 'shipping_price_per_kg_currency' => 'CNY',
            'markup_percent' => 30, 'irt_per_cny' => 30000,
            'price_rounding_digits' => 0, 'price_rounding_mode' => 'nearest_half_up',
            'pricing_catalog_revision' => 'test', 'pricing_catalog_status' => 'static',
            'currency_effective_date' => '2026-07-20', 'final_price' => 6558,
            'source_updated_at' => '', 'warnings' => array(),
            'record_hash' => 'sha256:' . str_repeat('a', 64),
        );
    }

    public function test_plan_separates_core_and_meta_changes_and_uses_native_irr(): void {
        $product = new Ashko_Test_Product(100, array(
            'regular_price' => '65580', 'price' => '65580', 'manage_stock' => true,
            'stock_quantity' => 10, 'stock_status' => 'instock', 'weight' => '',
        ));
        $plan = Product_Applicator::instance()->plan($product, $this->data());
        self::assertSame('65590', $plan['core_changes']['regular_price']['new']);
        self::assertSame(3, $plan['core_changes']['stock_quantity']['new']);
        self::assertSame('2', $plan['core_changes']['weight']['new']);
        self::assertSame('عدد', $plan['meta_changes']['woodmart_price_unit_of_measure']['new']);
        self::assertSame('IRR', $plan['meta_changes']['_ashko_patris_shipping_price_per_kg_currency']['new']);
        self::assertSame('CNY', $plan['meta_changes']['_ashko_patris_source_shipping_price_per_kg_currency']['new']);
        self::assertSame('10', $plan['meta_changes']['_ashko_patris_formula_discrepancy_irr']['new']);
        self::assertContains('formula_discrepancy', $plan['warnings']);
    }

    public function test_plan_supports_cny_shipping_configuration(): void {
        $GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION] = array(
            'shipping_price_per_kg' => '73.333333333333333333',
            'shipping_price_per_kg_currency' => 'CNY',
        );
        $product = new Ashko_Test_Product(101);

        $plan = Product_Applicator::instance()->plan($product, $this->data());

        self::assertSame('65580', $plan['core_changes']['regular_price']['new']);
        self::assertSame('CNY', $plan['meta_changes']['_ashko_patris_shipping_price_per_kg_currency']['new']);
        self::assertNotContains('missing_shipping', $plan['warnings']);
    }

    public function test_missing_shipping_currency_blocks_price_calculation_and_warns(): void {
        $GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION] = array(
            'shipping_price_per_kg' => '22000000',
            'shipping_price_per_kg_currency' => '',
        );
        $analysis = Product_Applicator::instance()->analyze_source($this->data());

        self::assertNull($analysis['calculation']);
        self::assertContains('missing_shipping', $analysis['warnings']);
        self::assertContains('missing_final_price', $analysis['warnings']);
    }

    public function test_stale_sale_price_is_an_explicit_change_and_second_apply_is_idempotent(): void {
        $product = new Ashko_Test_Product(16569, array(
            'regular_price' => '10489500', 'price' => '7200000', 'sale_price' => '7200000',
            'manage_stock' => true, 'stock_quantity' => 3, 'stock_status' => 'instock', 'weight' => '2',
        ));
        $plan = Product_Applicator::instance()->plan($product, $this->data());
        self::assertArrayHasKey('sale_price', $plan['core_changes']);
        self::assertSame('', $plan['core_changes']['sale_price']['new']);
        Product_Applicator::instance()->apply_product_feed($product, $this->data());
        self::assertSame(1, $product->save_count);
        $again = Product_Applicator::instance()->plan($product, $this->data());
        self::assertFalse($again['changed']);
    }

    public function test_partner_price_fallback_uses_irr_without_weight_freight_or_fx(): void {
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['partner_price_source'] = 1000000;
        $data['price_source_amount'] = 1000000;
        $data['price_source_currency'] = 'IRR';
        $data['price_source_kind'] = 'partner_price';
        $data['shipping_method_id'] = 'domestic';
        $data['shipping_price_per_kg'] = 0;
        $data['shipping_price_per_kg_currency'] = 'IRR';
        unset($data['weight_grams']);
        $product = new Ashko_Test_Product(220, array(
            'regular_price' => '', 'price' => '', 'manage_stock' => true,
            'stock_quantity' => 3, 'stock_status' => 'instock',
        ));

        $plan = Product_Applicator::instance()->plan($product, $data);

        self::assertSame('1300000', $plan['core_changes']['regular_price']['new']);
        self::assertSame('partner_price', $plan['meta_changes']['_ashko_patris_price_source_kind']['new']);
        self::assertSame('1000000', $plan['meta_changes']['_ashko_patris_partner_price_source']['new']);
        self::assertSame('', $plan['meta_changes']['_ashko_patris_sale_price_source']['new'] ?? '');
        self::assertSame('domestic', $plan['meta_changes']['_ashko_patris_shipping_method_id']['new']);
        self::assertSame('0', $plan['meta_changes']['_ashko_patris_shipping_price_per_kg']['new']);
        self::assertSame('IRR', $plan['meta_changes']['_ashko_patris_shipping_price_per_kg_currency']['new']);
        self::assertSame('domestic', $plan['meta_changes']['_ashko_patris_source_shipping_method_id']['new']);
        self::assertSame('0', $plan['meta_changes']['_ashko_patris_source_shipping_price_per_kg']['new']);
        self::assertSame('IRR', $plan['meta_changes']['_ashko_patris_source_shipping_price_per_kg_currency']['new']);
        self::assertContains('partner_price_source_used', $plan['warnings']);
        self::assertNotContains('missing_weight', $plan['warnings']);
        self::assertNotContains('missing_shipping', $plan['warnings']);
        self::assertNotContains('missing_fx', $plan['warnings']);
        self::assertNotContains('missing_final_price', $plan['warnings']);
    }

    public function test_direct_sale_price_path_is_explicitly_enabled_and_has_no_markup_or_rounding_effect(): void {
        $GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION] = array(
            'use_sale_price_direct_fallback' => 'yes',
            'profit_margin_percent' => '999',
            'price_rounding_digits' => '9',
        );
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['sale_price_source'] = 1234560;
        $data['price_source_amount'] = 1234560;
        $data['price_source_currency'] = 'IRR';
        $data['price_source_kind'] = 'sale_price_direct';
        $data['shipping_method_id'] = 'domestic';
        $data['shipping_price_per_kg'] = 0;
        $data['shipping_price_per_kg_currency'] = 'IRR';
        $data['final_price'] = 123456;
        unset(
            $data['weight_grams'],
            $data['markup_percent'],
            $data['irt_per_cny'],
            $data['price_rounding_digits'],
            $data['price_rounding_mode']
        );
        $product = new Ashko_Test_Product(225);

        $plan = Product_Applicator::instance()->plan($product, $data);

        self::assertSame('1234560', $plan['core_changes']['regular_price']['new']);
        self::assertSame('sale_price_direct', $plan['meta_changes']['_ashko_patris_price_source_kind']['new']);
        self::assertSame('', $plan['meta_changes']['_ashko_patris_markup_percent']['new'] ?? '');
        self::assertSame('', $plan['meta_changes']['_ashko_patris_price_rounding_digits']['new'] ?? '');
        self::assertSame('domestic', $plan['meta_changes']['_ashko_patris_shipping_method_id']['new']);
        self::assertContains('direct_sale_price_source_used', $plan['warnings']);
        self::assertNotContains('missing_margin', $plan['warnings']);
        self::assertNotContains('missing_rounding', $plan['warnings']);
    }

    public function test_piecewise_stock_policy_is_shared_by_apply_dry_run_and_report_projection(): void {
        $cases = array(
            '__omitted__' => null,
            '-5' => 0,
            '0' => 0,
            '1' => 1,
            '2' => 1,
            '3' => 1,
            '4' => 1,
            '2141' => 642,
        );
        foreach ($cases as $source_stock => $expected) {
            $data = $this->data();
            if ('__omitted__' === $source_stock) {
                unset($data['total_stock']);
            } else {
                $data['total_stock'] = $source_stock;
            }

            $projection = Product_Applicator::instance()->report_projection($data);

            if (null === $expected) {
                self::assertArrayNotHasKey('stock_quantity', $projection['core']);
                self::assertSame('', $projection['meta']['_ashko_patris_stock_applied']);
                continue;
            }
            self::assertSame($expected, $projection['core']['stock_quantity'], 'source stock ' . $source_stock);
            self::assertSame($expected > 0 ? 'instock' : 'outofstock', $projection['core']['stock_status']);
            self::assertSame((string) $expected, $projection['meta']['_ashko_patris_stock_applied']);
        }
    }

    public function test_publication_safety_drafts_only_when_all_three_conditions_hold(): void {
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['total_stock'] = 0;
        unset(
            $data['price_source_amount'],
            $data['price_source_currency'],
            $data['price_source_kind'],
            $data['final_price']
        );
        $product = new Ashko_Test_Product(221, array(
            'status' => 'publish', 'image_id' => 0, 'regular_price' => '', 'price' => '',
            'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock',
        ));

        $plan = Product_Applicator::instance()->plan($product, $data);

        self::assertSame('draft', $plan['core_changes']['status']['new']);
        self::assertSame('draft_incomplete', $plan['meta_changes']['_ashko_patris_publication_safety']['new']);
        self::assertTrue($plan['publication_safety']['should_draft']);
        self::assertContains('publication_safety_draft_required', $plan['warnings']);

        Product_Applicator::instance()->apply_product_feed($product, $data);
        $again = Product_Applicator::instance()->plan($product, $data);
        self::assertArrayNotHasKey('status', $again['core_changes']);
        self::assertContains('publication_safety_kept_draft', $again['warnings']);
    }

    /**
     * @dataProvider publicationSafetyExceptions
     */
    public function test_publication_safety_does_not_draft_when_any_condition_is_not_met(
        array $core,
        array $data_changes
    ): void {
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['total_stock'] = 0;
        unset(
            $data['price_source_amount'],
            $data['price_source_currency'],
            $data['price_source_kind'],
            $data['final_price']
        );
        foreach ($data_changes as $key => $value) {
            if ('__unset__' === $value) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $product = new Ashko_Test_Product(300 + count($core) + count($data_changes), array_merge(array(
            'status' => 'publish', 'image_id' => 0, 'regular_price' => '', 'price' => '',
            'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock',
        ), $core));

        $plan = Product_Applicator::instance()->plan($product, $data);

        self::assertArrayNotHasKey('status', $plan['core_changes']);
        self::assertFalse($plan['publication_safety']['should_draft']);
    }

    public static function publicationSafetyExceptions(): array {
        return array(
            'has image' => array(array('image_id' => 99), array()),
            'has existing price' => array(array('regular_price' => '5000', 'price' => '5000'), array()),
            'source stock is positive' => array(array(), array('total_stock' => 1)),
            'source stock is omitted, not zero' => array(array(), array('total_stock' => '__unset__')),
            'source stock is explicitly null, not zero' => array(array(), array('total_stock' => null)),
        );
    }

    public function test_explicit_zero_source_stock_overrides_stale_positive_woo_stock_for_safety(): void {
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['total_stock'] = 0;
        unset(
            $data['price_source_amount'],
            $data['price_source_currency'],
            $data['price_source_kind'],
            $data['final_price']
        );
        $product = new Ashko_Test_Product(450, array(
            'status' => 'publish', 'image_id' => 0, 'regular_price' => '', 'price' => '',
            'stock_quantity' => 8, 'stock_status' => 'instock',
        ));

        $plan = Product_Applicator::instance()->plan($product, $data);

        self::assertSame(0, $plan['core_changes']['stock_quantity']['new']);
        self::assertSame('draft', $plan['core_changes']['status']['new']);
        self::assertTrue($plan['publication_safety']['no_positive_stock']);
        self::assertTrue($plan['publication_safety']['woo_stock_is_positive']);
    }

    public function test_variation_inheriting_parent_image_is_not_drafted(): void {
        new Ashko_Test_Product(500, array('image_id' => 88, 'type' => 'variable'));
        $data = $this->data();
        $data['foreign_price'] = 0;
        $data['total_stock'] = 0;
        unset(
            $data['price_source_amount'],
            $data['price_source_currency'],
            $data['price_source_kind'],
            $data['final_price']
        );
        $variation = new Ashko_Test_Product(501, array(
            'type' => 'variation', 'parent_id' => 500, 'image_id' => 0, 'status' => 'publish',
            'regular_price' => '', 'price' => '', 'stock_quantity' => 0,
        ));

        $plan = Product_Applicator::instance()->plan($variation, $data);

        self::assertArrayNotHasKey('status', $plan['core_changes']);
        self::assertFalse($plan['publication_safety']['image_missing']);
    }

    public function test_complete_data_never_auto_publishes_an_existing_draft(): void {
        $product = new Ashko_Test_Product(600, array(
            'status' => 'draft', 'image_id' => 1, 'regular_price' => '', 'price' => '',
            'stock_quantity' => 0,
        ));

        $plan = Product_Applicator::instance()->plan($product, $this->data());

        self::assertArrayNotHasKey('status', $plan['core_changes']);
    }
}
