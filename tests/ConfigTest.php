<?php
use Ashko\Patris\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
    public function test_product_code_metadata_cannot_be_used_for_serial_matching(): void {
        $settings = Config::sanitize(array('serial_meta_key' => '_site_patris_product_code'));

        self::assertSame('_sku', $settings['serial_meta_key']);
    }

    public function test_unrelated_metadata_key_remains_configurable(): void {
        $settings = Config::sanitize(array('serial_meta_key' => '_catalog_serial'));

        self::assertSame('_catalog_serial', $settings['serial_meta_key']);
    }
}
