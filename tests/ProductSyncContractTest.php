<?php
use Ashko\Patris\Product_Sync_Receiver;
use PHPUnit\Framework\TestCase;

final class ProductSyncContractTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['ashko_test_options']['ashko_product_sync_v1_state']);
        $GLOBALS['ashko_test_options']['ashko_product_sync_v1_source_scopes'] = array();
        $GLOBALS['ashko_test_currency'] = 'IRR';
        $GLOBALS['ashko_test_products'] = array();
    }

    public function test_exact_v10_contract_is_accepted(): void {
        $json = file_get_contents(__DIR__ . '/fixtures/patris-product-sync-v1-golden.json');
        $preview = Product_Sync_Receiver::instance()->preview_json($json);
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertSame('1.0', $preview['envelope']['schema_version']);
        self::assertSame(array(), $preview['transition']['categories']);
        self::assertSame(array(), $preview['transition']['excluded_codes']);
    }

    public function test_exact_v11_catalog_and_exclusion_projection_is_accepted(): void {
        $json = file_get_contents(__DIR__ . '/fixtures/patris-product-sync-v1.1-golden.json');
        $preview = Product_Sync_Receiver::instance()->preview_json($json);
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertSame('1.1', $preview['envelope']['schema_version']);
        self::assertCount(2, $preview['transition']['categories']);
        self::assertSame(array('999010'), $preview['transition']['excluded_codes']);
    }

    public function test_raw_patris_key_is_rejected_before_application(): void {
        $payload = json_decode(file_get_contents(__DIR__ . '/fixtures/patris-product-sync-v1-golden.json'), true);
        $payload['products'][0]['ALLANBAR'] = 5;
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_raw_key_forbidden', $result->get_error_code());
    }

    public function test_preview_can_report_currency_mismatch_but_apply_fails_before_lock_or_write(): void {
        $json = file_get_contents(__DIR__ . '/fixtures/patris-product-sync-v1-golden.json');
        $GLOBALS['ashko_test_currency'] = 'IRT';
        $preview = Product_Sync_Receiver::instance()->preview_json($json);
        self::assertFalse(is_wp_error($preview));
        $result = Product_Sync_Receiver::instance()->receive_json($json);
        self::assertSame('ashko_product_sync_store_currency_mismatch', $result->get_error_code());
        self::assertSame(array(), $GLOBALS['ashko_test_products']);
    }
}
