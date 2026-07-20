<?php
use Ashko\Patris\Product_Sync_Receiver;
use PHPUnit\Framework\TestCase;

final class ProductSyncContractTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['ashko_test_options']['ashko_product_sync_state']);
        $GLOBALS['ashko_test_options']['ashko_product_sync_source_scopes'] = array();
        $GLOBALS['ashko_test_currency'] = 'IRR';
        $GLOBALS['ashko_test_products'] = array();
    }

    public function test_living_sparse_contract_is_accepted(): void {
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertArrayNotHasKey('schema_version', $preview['envelope']);
        self::assertArrayNotHasKey('formula_revision', $preview['envelope']);
        self::assertSame('landed_price', $preview['envelope']['formula_id']);
        self::assertCount(2, $preview['transition']['categories']);
        self::assertSame(array('999010'), $preview['transition']['excluded_codes']);
    }

    public function test_absent_and_explicit_null_values_remain_distinct(): void {
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview));
        $product = $preview['envelope']['products'][1];
        self::assertArrayNotHasKey('foreign_price', $product);
        self::assertArrayNotHasKey('final_price', $product);
        self::assertArrayHasKey('location', $product);
        self::assertNull($product['location']);
        self::assertSame(array(), $product['warehouse_stock']);

        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $without = array('product_code' => 'P1', 'warnings' => array());
        $with_null = array('product_code' => 'P1', 'location' => null, 'warnings' => array());
        self::assertNotSame(
            $hash->invoke(Product_Sync_Receiver::instance(), $without),
            $hash->invoke(Product_Sync_Receiver::instance(), $with_null)
        );
    }

    public function test_empty_warehouse_object_is_not_interchangeable_with_an_array(): void {
        $json = str_replace('"warehouse_stock": {}', '"warehouse_stock": []', $this->fixture());
        $result = Product_Sync_Receiver::instance()->preview_json($json);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[1].warehouse_stock', $result->get_error_data()['field']);
    }

    public function test_explicit_null_category_name_is_preserved(): void {
        $receiver = Product_Sync_Receiver::instance();
        $category = array(
            'category_code' => 'ROOT',
            'name' => null,
            'parent_code' => '',
            'depth' => 1,
            'warnings' => array(),
        );
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'category_record_hash');
        $category['record_hash'] = $hash->invoke($receiver, $category);
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_categories');
        $result = $validate->invoke($receiver, array($category));
        self::assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        self::assertArrayHasKey('name', $result[0]);
        self::assertNull($result[0]['name']);
    }

    public function test_currency_and_formula_identifiers_are_optional_without_defaults(): void {
        $payload = json_decode($this->fixture(), true);
        unset($payload['local_currency'], $payload['formula_id']);
        $payload['products'][1]['warehouse_stock'] = (object) array();
        $event_id = new ReflectionMethod(Product_Sync_Receiver::class, 'event_id');
        $payload['event_id'] = $event_id->invoke(Product_Sync_Receiver::instance(), $payload);
        $preview = Product_Sync_Receiver::instance()->preview_json((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertArrayNotHasKey('local_currency', $preview['envelope']);
        self::assertArrayNotHasKey('formula_id', $preview['envelope']);
    }

    public function test_currency_and_formula_identifiers_must_be_supplied_together(): void {
        $without_formula = json_decode($this->fixture(), true);
        unset($without_formula['formula_id']);
        $result = Product_Sync_Receiver::instance()->receive($without_formula);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('formula_id', $result->get_error_data()['field']);

        $without_currency = json_decode($this->fixture(), true);
        unset($without_currency['local_currency']);
        $result = Product_Sync_Receiver::instance()->receive($without_currency);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('formula_id', $result->get_error_data()['field']);
    }

    public function test_explicit_null_final_price_is_rejected_and_absence_remains_valid(): void {
        $payload = json_decode($this->fixture(), true);
        $product = $payload['products'][0];
        $product['final_price'] = null;

        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $result = $validate->invoke(Product_Sync_Receiver::instance(), $product, 0);

        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].final_price', $result->get_error_data()['field']);
        self::assertStringContainsString('omit it when unavailable', $result->get_error_data()['reason']);

        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertArrayNotHasKey('final_price', $preview['envelope']['products'][1]);
    }

    public function test_removed_contract_fields_are_rejected(): void {
        $payload = json_decode($this->fixture(), true);
        $payload['schema_version'] = '1.1';
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_unknown_field', $result->get_error_code());

        unset($payload['schema_version']);
        $payload['products'][0]['freight_cny_per_kg'] = 120;
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_product_shape_invalid', $result->get_error_code());
    }

    public function test_raw_patris_key_is_rejected_before_application(): void {
        $payload = json_decode($this->fixture(), true);
        $payload['products'][0]['ALLANBAR'] = 5;
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_raw_key_forbidden', $result->get_error_code());
    }

    public function test_preview_can_report_currency_mismatch_but_apply_fails_before_lock_or_write(): void {
        $GLOBALS['ashko_test_currency'] = 'IRT';
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview));
        $result = Product_Sync_Receiver::instance()->receive_json($this->fixture());
        self::assertSame('ashko_product_sync_store_currency_mismatch', $result->get_error_code());
        self::assertSame(array(), $GLOBALS['ashko_test_products']);
    }

    private function fixture(): string {
        return (string) file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json');
    }
}
