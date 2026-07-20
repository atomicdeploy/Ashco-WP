<?php
use Ashko\Patris\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['ashko_test_options'][Config::OPTION]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['ashko_test_options'][Config::OPTION]);
    }

    public function test_product_code_metadata_cannot_be_used_for_serial_matching(): void {
        $settings = Config::sanitize(array('serial_meta_key' => '_site_patris_product_code'));

        self::assertSame('_sku', $settings['serial_meta_key']);
    }

    public function test_unrelated_metadata_key_remains_configurable(): void {
        $settings = Config::sanitize(array('serial_meta_key' => '_catalog_serial'));

        self::assertSame('_catalog_serial', $settings['serial_meta_key']);
    }

    public function test_shipping_setting_requires_a_supported_currency(): void {
        $settings = Config::sanitize(array(
            'shipping_price_per_kg' => '100',
            'shipping_price_per_kg_currency' => 'cny',
        ));
        self::assertSame('100', $settings['shipping_price_per_kg']);
        self::assertSame('CNY', $settings['shipping_price_per_kg_currency']);

        $invalid = Config::sanitize(array(
            'shipping_price_per_kg' => '100',
            'shipping_price_per_kg_currency' => 'IRT',
        ));
        self::assertSame('', $invalid['shipping_price_per_kg_currency']);
    }

    public function test_existing_internal_irr_shipping_setting_is_migrated_on_read(): void {
        $GLOBALS['ashko_test_options'][Config::OPTION] = array('shipping_irr_per_kg' => '12345678');

        $settings = Config::all();

        self::assertSame('12345678', $settings['shipping_price_per_kg']);
        self::assertSame('IRR', $settings['shipping_price_per_kg_currency']);
        self::assertArrayNotHasKey('shipping_irr_per_kg', $settings);
        self::assertSame(
            array('shipping_price_per_kg' => '12345678', 'shipping_price_per_kg_currency' => 'IRR'),
            $GLOBALS['ashko_test_options'][Config::OPTION]
        );
    }
}
