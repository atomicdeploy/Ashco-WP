<?php
use Ashko\Patris\Product_Sync_Receiver;
use PHPUnit\Framework\TestCase;

final class ReceiverBatchTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_serial_rows'] = array();
        $GLOBALS['ashko_test_products'] = array();
        $GLOBALS['ashko_test_currency'] = 'IRR';
    }

    public function test_delivery_persists_a_bounded_twenty_five_write_batch(): void {
        $products = array();
        $pending = array();
        for ($index = 1; $index <= 30; $index++) {
            $code = 'P' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $serial = 'SERIAL-' . $index;
            $hash = 'sha256:' . hash('sha256', $code);
            $products[$code] = array(
                'product_code' => $code, 'category_code' => '', 'name' => 'Part ' . $index,
                'serial' => $serial, 'unit' => 'عدد', 'warehouse_stock' => array('1' => 10),
                'total_stock' => 10, 'foreign_currency' => 'CNY', 'foreign_price' => 1,
                'weight_grams' => 1, 'import_freight_method_id' => 'air_express',
                'freight_cny_per_kg' => 73.333333333333, 'markup_percent' => 30, 'irt_per_cny' => 30000,
                'pricing_catalog_revision' => 'test', 'pricing_catalog_status' => 'static',
                'currency_effective_date' => '2026-07-20', 'final_price' => 32860,
                'formula_version' => 'landed_price_v1', 'source_updated_at' => '', 'warnings' => array(),
                'record_hash' => $hash,
            );
            $pending[$code] = array('product_code' => $code, 'record_hash' => $hash, 'attempts' => 0);
            $GLOBALS['ashko_test_serial_rows'][] = array(
                'ID' => (string) $index, 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => $serial,
            );
            new Ashko_Test_Product($index);
        }
        $state = array(
            'products' => $products,
            'pending_products' => $pending,
            'deferred_products' => array(),
            'applied_products' => array(),
        );
        $method = new ReflectionMethod(Product_Sync_Receiver::class, 'drain_delivery_products');
        $arguments = array(&$state, true, false);
        $result = $method->invokeArgs(Product_Sync_Receiver::instance(), $arguments);
        self::assertSame(25, $result['write_attempts']);
        self::assertTrue($result['batch_limited']);
        self::assertCount(5, $state['pending_products']);
        self::assertCount(25, $state['applied_products']);
        self::assertSame(25, array_sum(array_map(static fn($product) => $product->save_count, $GLOBALS['ashko_test_products'])));
    }
}
