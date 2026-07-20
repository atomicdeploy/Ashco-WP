<?php
use Ashko\Patris\Product_Applicator;
use PHPUnit\Framework\TestCase;

final class ProductApplicatorTest extends TestCase {
    private function data(): array {
        return array(
            'product_code' => '101023', 'category_code' => '101', 'name' => 'Part', 'serial' => 'B 32', 'unit' => 'عدد',
            'warehouse_stock' => array('1' => 10), 'total_stock' => 10, 'foreign_currency' => 'CNY',
            'foreign_price' => 0.0215, 'weight_grams' => 2, 'shipping_method_id' => 'air_express',
            'shipping_price_per_kg_cny' => 73.333333333333, 'markup_percent' => 30, 'irt_per_cny' => 30000,
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
        self::assertSame('65585', $plan['core_changes']['regular_price']['new']);
        self::assertSame(3, $plan['core_changes']['stock_quantity']['new']);
        self::assertSame('2', $plan['core_changes']['weight']['new']);
        self::assertSame('عدد', $plan['meta_changes']['woodmart_price_unit_of_measure']['new']);
        self::assertSame('5', $plan['meta_changes']['_ashko_patris_formula_discrepancy_irr']['new']);
        self::assertContains('formula_discrepancy', $plan['warnings']);
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
}
