<?php
use Ashko\Patris\Config;
use Ashko\Patris\Product_Presentation;
use PHPUnit\Framework\TestCase;

final class ProductPresentationTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_hooks'] = array();
        $GLOBALS['ashko_test_products'] = array();
        $GLOBALS['ashko_test_post_ids'] = array();
        $GLOBALS['ashko_test_raw_post_excerpts'] = array();
        $GLOBALS['ashko_test_get_posts_calls'] = array();
        $GLOBALS['ashko_test_excerpt_prefilter_calls'] = 0;
        unset($GLOBALS['ashko_test_get_posts_args']);
        $GLOBALS['ashko_test_product_categories'] = array();
        $GLOBALS['ashko_test_queried_object_id'] = 0;
        $GLOBALS['ashko_test_post_types'] = array();
        unset($GLOBALS['product']);
        unset($GLOBALS['ashko_test_options']['ashko_patris_legacy_excerpt_cleanup']);
    }

    public function test_registers_semantic_product_and_excerpt_hooks(): void {
        Product_Presentation::register();

        $hooks = array_column($GLOBALS['ashko_test_hooks'], 'hook');
        self::assertContains('woocommerce_product_get_short_description', $hooks);
        self::assertContains('woocommerce_short_description', $hooks);
        self::assertContains('get_the_excerpt', $hooks);
        self::assertContains('woocommerce_display_product_attributes', $hooks);
        self::assertContains('woocommerce_product_tabs', $hooks);
        self::assertContains('woocommerce_structured_data_product', $hooks);
        self::assertContains('rank_math/snippet/rich_snippet_product_entity', $hooks);
        $rank_math_hooks = array_values(array_filter(
            $GLOBALS['ashko_test_hooks'],
            static fn(array $hook): bool => 'rank_math/snippet/rich_snippet_product_entity' === $hook['hook']
        ));
        self::assertCount(1, $rank_math_hooks);
        self::assertSame(20, $rank_math_hooks[0]['priority']);
        self::assertSame(1, $rank_math_hooks[0]['accepted_args']);
    }

    public function test_product_details_are_structured_escaped_and_blank_safe(): void {
        $product = $this->product(101, 'Part', 'A&B', 'B < 7', 'عدد');

        $attributes = Product_Presentation::product_attributes(array(
            'weight' => array('label' => 'وزن', 'value' => '2 گرم'),
        ), $product);

        self::assertSame(array(
            'weight',
            'ashco_patris_product_code',
            'ashco_patris_serial',
            'ashco_patris_sale_unit',
        ), array_keys($attributes));
        self::assertSame('کد کالا', $attributes['ashco_patris_product_code']['label']);
        self::assertSame('سریال کالا', $attributes['ashco_patris_serial']['label']);
        self::assertStringContainsString('class="ashco-patris-identifier"', $attributes['ashco_patris_product_code']['value']);
        self::assertStringContainsString('dir="ltr"', $attributes['ashco_patris_product_code']['value']);
        self::assertStringStartsWith('<span ', $attributes['ashco_patris_product_code']['value']);
        self::assertSame(
            $attributes['ashco_patris_product_code']['value'],
            wp_kses_post($attributes['ashco_patris_product_code']['value'])
        );
        self::assertStringContainsString('A&amp;B', $attributes['ashco_patris_product_code']['value']);
        self::assertStringContainsString('B &lt; 7', $attributes['ashco_patris_serial']['value']);
        self::assertSame('عدد', $attributes['ashco_patris_sale_unit']['value']);

        $blank = $this->product(102, 'Blank', '', 'S-2', '');
        $attributes = Product_Presentation::product_attributes(array(), $blank);
        self::assertSame(array('ashco_patris_serial'), array_keys($attributes));

        $unowned = new Ashko_Test_Presentation_Product(103, 'Merchant', '', array(
            '_ashko_patris_product_code' => 'DO-NOT-SHOW',
        ));
        self::assertSame(array(), Product_Presentation::product_attributes(array(), $unowned));
    }

    public function test_exact_one_time_import_provenance_is_owned_but_incomplete_or_forged_provenance_is_not(): void {
        $trusted_meta = array(
            '_ashko_patris_product_code' => 'P-IMPORT',
            Config::OWN_SERIAL_META => 'S-IMPORT',
            '_ashko_patris_unit' => 'عدد',
            '_ashko_patris_import_marker' => 'positive-stock-20260720',
            '_ashko_patris_import_source_sha256' => '3da2f89f3c814c3d6b8efc4511984739c87b5c12f9ef3c6ea1e11575925fa321',
        );
        $trusted = new Ashko_Test_Presentation_Product(104, 'Imported', '', $trusted_meta);
        $GLOBALS['ashko_test_product_categories'][104] = array('قطعه');
        self::assertArrayHasKey(
            'ashco_patris_sale_unit',
            Product_Presentation::product_attributes(array(), $trusted)
        );
        $legacy = '«Imported» از گروه «قطعه» با سریال S-IMPORT و کد پاتریس P-IMPORT است. واحد فروش: عدد.';
        self::assertTrue(Product_Presentation::is_legacy_generated_excerpt($legacy, $trusted));
        self::assertFalse(Product_Presentation::is_legacy_generated_excerpt($legacy . ' توضیح فروشنده.', $trusted));
        self::assertSame(
            $legacy . ' توضیح فروشنده.',
            Product_Presentation::filter_product_short_description($legacy . ' توضیح فروشنده.', $trusted)
        );

        $untrusted = array(
            array_diff_key($trusted_meta, array('_ashko_patris_import_source_sha256' => true)),
            array_diff_key($trusted_meta, array('_ashko_patris_import_marker' => true)),
            array_replace($trusted_meta, array('_ashko_patris_import_marker' => 'positive-stock-20260721')),
            array_replace($trusted_meta, array('_ashko_patris_import_marker' => 'positive-stock-20260720 ')),
            array_replace($trusted_meta, array(
                '_ashko_patris_import_source_sha256' => str_repeat('f', 64),
            )),
            array_replace($trusted_meta, array(
                '_ashko_patris_import_source_sha256' => ' ' . $trusted_meta['_ashko_patris_import_source_sha256'],
            )),
            array_replace($trusted_meta, array(
                '_ashko_patris_import_source_sha256' => strtoupper($trusted_meta['_ashko_patris_import_source_sha256']),
            )),
            array_replace($trusted_meta, array(
                '_ashko_patris_record_hash' => 'sha256:' . str_repeat('A', 64),
                '_ashko_patris_import_marker' => '',
                '_ashko_patris_import_source_sha256' => '',
            )),
            array_replace($trusted_meta, array(
                '_ashko_patris_record_hash' => ' sha256:' . str_repeat('a', 64),
                '_ashko_patris_import_marker' => '',
                '_ashko_patris_import_source_sha256' => '',
            )),
            array_replace($trusted_meta, array(
                '_ashko_patris_record_hash' => 'sha256:' . str_repeat('a', 64) . "\n",
                '_ashko_patris_import_marker' => '',
                '_ashko_patris_import_source_sha256' => '',
            )),
        );
        foreach ($untrusted as $offset => $meta) {
            $product = new Ashko_Test_Presentation_Product(105 + $offset, 'Untrusted', '', $meta);
            self::assertSame(array(), Product_Presentation::product_attributes(array(), $product));
        }
    }

    public function test_additional_information_tab_is_restored_only_when_needed(): void {
        $GLOBALS['product'] = $this->product(110, 'Part', 'P-1', 'S-1', 'عدد');
        $tabs = Product_Presentation::product_tabs(array());
        self::assertArrayHasKey('additional_information', $tabs);
        self::assertSame('woocommerce_product_additional_information_tab', $tabs['additional_information']['callback']);

        $existing = array('additional_information' => array('title' => 'Existing'));
        self::assertSame($existing, Product_Presentation::product_tabs($existing));

        $GLOBALS['product'] = new Ashko_Test_Presentation_Product(111, 'Merchant');
        self::assertSame(array(), Product_Presentation::product_tabs(array()));
    }

    public function test_structured_data_uses_additional_properties_without_false_mpn_semantics(): void {
        $product = $this->product(120, 'Part', 'P-1', 'S-1', 'متر');
        $markup = Product_Presentation::structured_data(array('name' => 'Part'), $product);

        self::assertArrayNotHasKey('mpn', $markup);
        self::assertCount(3, $markup['additionalProperty']);
        self::assertSame(
            array('کد کالا', 'سریال کالا', 'واحد فروش'),
            array_column($markup['additionalProperty'], 'name')
        );

        $again = Product_Presentation::structured_data($markup, $product);
        self::assertCount(3, $again['additionalProperty']);
    }

    public function test_rank_math_product_entity_preserves_data_and_is_idempotent(): void {
        $product = $this->product(121, 'Part', 'P-2', 'S-2', 'عدد');
        $GLOBALS['ashko_test_queried_object_id'] = 121;
        $entity = array(
            '@type' => 'Product',
            'offers' => array('price' => '100'),
            'additionalProperty' => array(
                array('@type' => 'PropertyValue', 'name' => 'رنگ', 'value' => 'قرمز'),
            ),
        );

        $markup = Product_Presentation::rank_math_product_entity($entity);
        self::assertSame('100', $markup['offers']['price']);
        self::assertSame('قرمز', $markup['additionalProperty'][0]['value']);
        self::assertSame(
            array('رنگ', 'کد کالا', 'سریال کالا', 'واحد فروش'),
            array_column($markup['additionalProperty'], 'name')
        );

        $again = Product_Presentation::rank_math_product_entity($markup);
        self::assertSame($markup, $again);
    }

    public function test_rank_math_product_entity_requires_the_queried_woocommerce_product(): void {
        $entity = array('@type' => 'Product', 'name' => 'Part');
        self::assertSame($entity, Product_Presentation::rank_math_product_entity($entity));

        $GLOBALS['product'] = $this->product(122, 'Unrelated loop product', 'WRONG', 'WRONG', 'بسته');
        self::assertSame($entity, Product_Presentation::rank_math_product_entity($entity));

        $this->product(123, 'Queried product', 'P-3', 'S-3', 'متر');
        $GLOBALS['ashko_test_queried_object_id'] = 123;
        $GLOBALS['ashko_test_post_types'][123] = 'page';
        self::assertSame($entity, Product_Presentation::rank_math_product_entity($entity));

        $GLOBALS['ashko_test_post_types'][123] = 'product';
        $markup = Product_Presentation::rank_math_product_entity($entity);
        self::assertSame(
            array('کد کالا', 'سریال کالا', 'واحد فروش'),
            array_column($markup['additionalProperty'], 'name')
        );
        self::assertSame('not-an-array', Product_Presentation::rank_math_product_entity('not-an-array'));
    }

    public function test_exact_import_excerpt_is_hidden_but_merchant_copy_is_preserved(): void {
        $product = $this->product(130, 'MODULE PLAYER G016-12V', '118325', '118325', 'عدد');
        $GLOBALS['ashko_test_product_categories'][130] = array('ماژول');
        $legacy = '«MODULE PLAYER G016-12V» از گروه «ماژول» با سریال 118325 و کد پاتریس 118325 است. واحد فروش: عدد.';

        self::assertTrue(Product_Presentation::is_legacy_generated_excerpt($legacy, $product));
        self::assertSame('', Product_Presentation::filter_product_short_description($legacy, $product));
        self::assertSame('', Product_Presentation::filter_product_short_description('<p>' . $legacy . '</p>', $product));
        self::assertFalse(Product_Presentation::is_legacy_generated_excerpt($legacy . ' توضیح فروشنده.', $product));
        self::assertSame(
            $legacy . ' توضیح فروشنده.',
            Product_Presentation::filter_product_short_description($legacy . ' توضیح فروشنده.', $product)
        );
        self::assertFalse(Product_Presentation::is_legacy_generated_excerpt(
            str_replace('118325 است', 'DIFFERENT است', $legacy),
            $product
        ));
        self::assertFalse(Product_Presentation::is_legacy_generated_excerpt(
            str_replace('«ماژول»', '«گروه دیگر»', $legacy),
            $product
        ));
    }

    public function test_missing_unit_import_template_is_recognized_only_for_missing_unit_source(): void {
        $product = $this->product(140, 'Part', 'P-2', 'S-2', '');
        $GLOBALS['ashko_test_product_categories'][140] = array('قطعه');
        $without_unit = '«Part» از گروه «قطعه» با سریال S-2 و کد پاتریس P-2 است.';
        self::assertTrue(Product_Presentation::is_legacy_generated_excerpt($without_unit, $product));

        $with_unit = $this->product(141, 'Part', 'P-2', 'S-2', 'عدد');
        $GLOBALS['ashko_test_product_categories'][141] = array('قطعه');
        self::assertFalse(Product_Presentation::is_legacy_generated_excerpt($without_unit, $with_unit));
    }

    public function test_post_and_render_filters_remove_only_owned_generated_excerpt(): void {
        $product = $this->product(150, 'Part', 'P-3', 'S-3', 'عدد');
        $GLOBALS['ashko_test_product_categories'][150] = array('قطعه');
        $legacy = '«Part» از گروه «قطعه» با سریال S-3 و کد پاتریس P-3 است. واحد فروش: عدد.';
        $GLOBALS['product'] = $product;

        self::assertSame('', Product_Presentation::filter_rendered_short_description('<p>' . $legacy . '</p>'));
        self::assertSame('', Product_Presentation::filter_post_excerpt($legacy, (object) array(
            'ID' => 150,
            'post_type' => 'product',
        )));
        self::assertSame($legacy, Product_Presentation::filter_post_excerpt($legacy, (object) array(
            'ID' => 150,
            'post_type' => 'page',
        )));
    }

    public function test_cleanup_dry_run_and_apply_are_exact_idempotent_and_audited(): void {
        $exact = $this->product(160, 'Part A', 'P-4', 'S-4', 'عدد');
        $exact->set_short_description('«Part A» از گروه «قطعه» با سریال S-4 و کد پاتریس P-4 است. واحد فروش: عدد.');
        $missing = new Ashko_Test_Presentation_Product(161, 'Part B', '', array(
            '_ashko_patris_product_code' => 'P-5',
            Config::OWN_SERIAL_META => 'S-5',
            '_ashko_patris_unit' => '',
            '_ashko_patris_import_marker' => 'positive-stock-20260720',
            '_ashko_patris_import_source_sha256' => '3da2f89f3c814c3d6b8efc4511984739c87b5c12f9ef3c6ea1e11575925fa321',
        ));
        $missing->set_short_description('«Part B» از گروه «قطعه» با سریال S-5 و کد پاتریس P-5 است.');
        $merchant = $this->product(162, 'Part C', 'P-6', 'S-6', 'عدد');
        $merchant->set_short_description('توضیح واقعی فروشنده با کد P-6');
        $GLOBALS['ashko_test_product_categories'][160] = array('قطعه');
        $GLOBALS['ashko_test_product_categories'][161] = array('قطعه');
        $GLOBALS['ashko_test_product_categories'][162] = array('قطعه');
        $GLOBALS['ashko_test_post_ids'] = array(160, 161, 162);

        $dry_run = Product_Presentation::cleanup_legacy_excerpts(false);
        self::assertSame(3, $dry_run['scanned']);
        self::assertSame(2, $dry_run['matched']);
        self::assertSame(0, $dry_run['cleared']);
        self::assertNotSame('', $exact->get_short_description('edit'));
        self::assertSame(array(160, 161, 162), $GLOBALS['ashko_test_get_posts_args']['post__in']);

        $meta_query = $GLOBALS['ashko_test_get_posts_args']['meta_query'];
        self::assertSame('OR', $meta_query['relation']);
        self::assertSame('_ashko_patris_record_hash', $meta_query[0]['key']);
        self::assertSame('REGEXP', $meta_query[0]['compare']);
        self::assertSame('^sha256:[a-f0-9]{64}$', $meta_query[0]['value']);
        self::assertSame('_ashko_patris_import_marker', $meta_query[1][0]['key']);
        self::assertSame('positive-stock-20260720', $meta_query[1][0]['value']);
        self::assertSame('_ashko_patris_import_source_sha256', $meta_query[1][1]['key']);
        self::assertSame(
            '3da2f89f3c814c3d6b8efc4511984739c87b5c12f9ef3c6ea1e11575925fa321',
            $meta_query[1][1]['value']
        );

        $applied = Product_Presentation::cleanup_legacy_excerpts(true);
        self::assertSame(2, $applied['cleared']);
        self::assertSame('', $exact->get_short_description('edit'));
        self::assertSame('', $missing->get_short_description('edit'));
        self::assertSame('توضیح واقعی فروشنده با کد P-6', $merchant->get_short_description('edit'));
        self::assertSame(1, $exact->save_count);
        self::assertSame(1, $missing->save_count);
        self::assertSame(0, $merchant->save_count);
        self::assertSame(2, get_option('ashko_patris_legacy_excerpt_cleanup')['cleared']);

        $again = Product_Presentation::cleanup_legacy_excerpts(true);
        self::assertSame(1, $again['scanned']);
        self::assertSame(0, $again['matched']);
        self::assertSame(0, $again['cleared']);
        self::assertSame(array(162), $GLOBALS['ashko_test_get_posts_args']['post__in']);
        self::assertSame('توضیح واقعی فروشنده با کد P-6', $merchant->get_short_description('edit'));
    }

    public function test_cleanup_empty_prefilter_short_circuits_but_later_trusted_excerpt_is_discovered(): void {
        $product = $this->product(170, 'Part D', 'P-7', 'S-7', 'عدد');
        $GLOBALS['ashko_test_product_categories'][170] = array('قطعه');
        $GLOBALS['ashko_test_post_ids'] = array(170);

        $empty = Product_Presentation::cleanup_legacy_excerpts(true);
        self::assertSame(0, $empty['scanned']);
        self::assertSame(0, $empty['matched']);
        self::assertSame(0, $empty['cleared']);
        self::assertSame(1, $GLOBALS['ashko_test_excerpt_prefilter_calls']);
        self::assertSame(array(), $GLOBALS['ashko_test_get_posts_calls']);
        self::assertArrayNotHasKey('ashko_test_get_posts_args', $GLOBALS);
        self::assertSame(array(
            'completed_at' => '2026-07-20 12:00:00',
            'scanned' => 0,
            'matched' => 0,
            'cleared' => 0,
            'errors' => 0,
        ), get_option('ashko_patris_legacy_excerpt_cleanup'));

        $legacy = '«Part D» از گروه «قطعه» با سریال S-7 و کد پاتریس P-7 است. واحد فروش: عدد.';
        $product->set_short_description($legacy);
        $later = Product_Presentation::cleanup_legacy_excerpts(false);
        self::assertSame(1, $later['scanned']);
        self::assertSame(1, $later['matched']);
        self::assertSame(array(170), $later['matched_product_ids']);
        self::assertSame(array(170), $GLOBALS['ashko_test_get_posts_args']['post__in']);
    }

    private function product(int $id, string $name, string $code, string $serial, string $unit): Ashko_Test_Presentation_Product {
        return new Ashko_Test_Presentation_Product($id, $name, '', array(
            '_ashko_patris_record_hash' => 'sha256:' . str_repeat('a', 64),
            '_ashko_patris_product_code' => $code,
            Config::OWN_SERIAL_META => $serial,
            '_ashko_patris_unit' => $unit,
        ));
    }
}

final class Ashko_Test_Presentation_Product {
    private int $id;
    private string $name;
    private string $short_description;
    private array $meta;
    public int $save_count = 0;

    public function __construct(int $id, string $name, string $short_description = '', array $meta = array()) {
        $this->id = $id;
        $this->name = $name;
        $this->short_description = $short_description;
        $this->meta = $meta;
        $GLOBALS['ashko_test_products'][$id] = $this;
        $GLOBALS['ashko_test_raw_post_excerpts'][$id] = $short_description;
    }

    public function get_id(): int { return $this->id; }
    public function get_name($context = 'view'): string { return $this->name; }
    public function get_meta($key, $single = true, $context = 'view') { return $this->meta[$key] ?? ''; }
    public function get_short_description($context = 'view'): string { return $this->short_description; }
    public function set_short_description(string $value): void {
        $this->short_description = $value;
        $GLOBALS['ashko_test_raw_post_excerpts'][$this->id] = $value;
    }
    public function save(): int { ++$this->save_count; return $this->id; }
}
