<?php
use Ashko\Patris\Serial_Resolver;
use PHPUnit\Framework\TestCase;

final class SerialResolverTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_serial_rows'] = array(
            array('ID' => '10', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'AbC-1'),
            array('ID' => '10', 'post_type' => 'product', 'meta_key' => '_ashko_patris_serial', 'meta_value' => 'AbC-1'),
            array('ID' => '11', 'post_type' => 'product', 'meta_key' => '_sku', 'meta_value' => 'ONLY-SKU'),
        );
    }

    public function test_exact_sku_and_owned_serial_for_same_product_are_one_match(): void {
        $resolved = Serial_Resolver::instance()->resolve_catalog(array(array('product_code' => 'P1', 'serial' => 'AbC-1')));
        self::assertFalse(is_wp_error($resolved['P1']));
        self::assertSame('10', $resolved['P1']['woocommerce_id']);
    }

    public function test_case_mismatch_is_not_a_match(): void {
        $resolved = Serial_Resolver::instance()->resolve_catalog(array(array('product_code' => 'P1', 'serial' => 'abc-1')));
        self::assertSame('ashko_product_identifier_not_found', $resolved['P1']->get_error_code());
    }

    public function test_duplicate_source_serial_is_ambiguous_even_with_one_woo_match(): void {
        $resolved = Serial_Resolver::instance()->resolve_catalog(array(
            array('product_code' => 'P1', 'serial' => 'ONLY-SKU'),
            array('product_code' => 'P2', 'serial' => 'ONLY-SKU'),
        ));
        self::assertSame('duplicate_source_serial', $resolved['P1']->get_error_data()['reason']);
        self::assertSame('duplicate_source_serial', $resolved['P2']->get_error_data()['reason']);
    }
}
