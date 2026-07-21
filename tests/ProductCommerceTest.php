<?php
use Ashko\Patris\Product_Commerce;
use PHPUnit\Framework\TestCase;

final class ProductCommerceTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_hooks'] = array();
        $GLOBALS['ashko_test_inline_scripts'] = array();
        $GLOBALS['ashko_test_products'] = array();
        unset($GLOBALS['product']);
    }

    public function test_registers_the_complete_commerce_pipeline(): void {
        Product_Commerce::register();

        $hooks = array_column($GLOBALS['ashko_test_hooks'], 'hook');
        self::assertContains('woocommerce_after_add_to_cart_quantity', $hooks);
        self::assertContains('woocommerce_available_variation', $hooks);
        self::assertContains('woocommerce_add_cart_item_data', $hooks);
        self::assertContains('woocommerce_get_cart_item_from_session', $hooks);
        self::assertContains('woocommerce_get_item_data', $hooks);
        self::assertContains('woocommerce_checkout_create_order_line_item', $hooks);
        self::assertContains('woocommerce_order_item_get_formatted_meta_data', $hooks);
        self::assertContains('wp_enqueue_scripts', $hooks);
    }

    public function test_resolves_variation_override_then_parent_fallback(): void {
        $parent = new Ashko_Test_Commerce_Product(10, 'variable', 0, array(
            Product_Commerce::UNIT_META => 'عدد',
        ));
        $inheriting = new Ashko_Test_Commerce_Product(11, 'variation', 10, array(
            Product_Commerce::UNIT_META => '',
        ));
        $overriding = new Ashko_Test_Commerce_Product(12, 'variation', 10, array(
            Product_Commerce::UNIT_META => ' بسته ',
        ));

        self::assertSame('عدد', Product_Commerce::resolve_unit($parent));
        self::assertSame('عدد', Product_Commerce::resolve_unit($inheriting));
        self::assertSame('بسته', Product_Commerce::resolve_unit($overriding));
        self::assertSame('', Product_Commerce::resolve_unit(null));
    }

    public function test_renders_one_escaped_quantity_label_and_variable_placeholder(): void {
        $GLOBALS['product'] = new Ashko_Test_Commerce_Product(20, 'simple', 0, array(
            Product_Commerce::UNIT_META => '<عدد>',
        ));

        ob_start();
        Product_Commerce::render_quantity_unit();
        $html = ob_get_clean();

        self::assertStringContainsString('واحد فروش:', $html);
        self::assertStringContainsString('&lt;عدد&gt;', $html);
        self::assertSame(1, substr_count($html, 'ashco-patris-sales-unit__value'));
        self::assertStringNotContainsString(' hidden ', $html);

        $GLOBALS['product'] = new Ashko_Test_Commerce_Product(21, 'variable');
        ob_start();
        Product_Commerce::render_quantity_unit();
        $empty_html = ob_get_clean();
        self::assertStringContainsString(' hidden aria-hidden="true"', $empty_html);

        $GLOBALS['product'] = new Ashko_Test_Commerce_Product(22, 'simple');
        ob_start();
        Product_Commerce::render_quantity_unit();
        self::assertSame('', ob_get_clean());
    }

    public function test_available_variation_exposes_only_the_resolved_canonical_unit(): void {
        new Ashko_Test_Commerce_Product(30, 'variable', 0, array(
            Product_Commerce::UNIT_META => 'عدد',
        ));
        $variation = new Ashko_Test_Commerce_Product(31, 'variation', 30);

        $data = Product_Commerce::available_variation(array('variation_id' => 31), null, $variation);
        self::assertSame('عدد', $data[Product_Commerce::VARIATION_UNIT_KEY]);
        self::assertArrayNotHasKey(Product_Commerce::UNIT_META, $data);

        $empty = new Ashko_Test_Commerce_Product(32, 'variation');
        $data = Product_Commerce::available_variation(
            array(Product_Commerce::VARIATION_UNIT_KEY => 'stale'),
            null,
            $empty
        );
        self::assertArrayNotHasKey(Product_Commerce::VARIATION_UNIT_KEY, $data);
    }

    public function test_cart_uses_selected_variation_snapshot_and_preserves_it_from_session(): void {
        new Ashko_Test_Commerce_Product(40, 'variable', 0, array(
            Product_Commerce::UNIT_META => 'عدد',
        ));
        $variation = new Ashko_Test_Commerce_Product(41, 'variation', 40, array(
            Product_Commerce::UNIT_META => 'بسته',
        ));

        $cart_data = Product_Commerce::add_cart_item_data(array(), 40, 41, 2);
        self::assertSame('بسته', $cart_data[Product_Commerce::UNIT_META]);

        $variation->set_meta(Product_Commerce::UNIT_META, 'کارتن');
        $restored = Product_Commerce::restore_cart_item_data(
            array('data' => $variation),
            $cart_data,
            'cart-key'
        );
        self::assertSame('بسته', $restored[Product_Commerce::UNIT_META]);

        $legacy = Product_Commerce::restore_cart_item_data(
            array('data' => $variation),
            array(),
            'legacy-key'
        );
        self::assertSame('کارتن', $legacy[Product_Commerce::UNIT_META]);
    }

    public function test_cart_item_row_is_snapshot_based_and_idempotent(): void {
        $cart_item = array(Product_Commerce::UNIT_META => 'عدد');
        $rows = Product_Commerce::cart_item_data(array(), $cart_item);

        self::assertCount(1, $rows);
        self::assertSame('واحد فروش', $rows[0]['key']);
        self::assertSame('عدد', $rows[0]['value']);
        self::assertSame(array('key', 'value', 'display'), array_keys($rows[0]));

        $rows = Product_Commerce::cart_item_data($rows, $cart_item);
        self::assertCount(1, $rows);

        $other = array(array('key' => 'واحد فروش', 'value' => 'جعبه', 'display' => 'جعبه'));
        $combined = Product_Commerce::cart_item_data($other, $cart_item);
        self::assertCount(2, $combined);
        self::assertSame('جعبه', $combined[0]['value']);

        self::assertSame($rows, Product_Commerce::cart_item_data(
            $rows,
            array(Product_Commerce::UNIT_META => '')
        ));
        self::assertSame(array(), Product_Commerce::cart_item_data(array(), array()));
    }

    public function test_order_line_snapshots_cart_unit_not_changed_product_meta(): void {
        $product = new Ashko_Test_Commerce_Product(50, 'simple', 0, array(
            Product_Commerce::UNIT_META => 'عدد',
        ));
        $cart_data = Product_Commerce::add_cart_item_data(array('data' => $product), 50, 0, 1);
        $product->set_meta(Product_Commerce::UNIT_META, 'بسته');
        $item = new Ashko_Test_Commerce_Order_Item();

        Product_Commerce::create_order_line_item($item, 'cart-key', $cart_data, null);

        self::assertSame('عدد', $item->get_meta(Product_Commerce::UNIT_META, true));
        self::assertCount(1, $item->added);
        self::assertTrue($item->added[0]['unique']);
    }

    public function test_order_line_legacy_fallback_and_empty_snapshot_are_distinct(): void {
        $product = new Ashko_Test_Commerce_Product(60, 'simple', 0, array(
            Product_Commerce::UNIT_META => 'عدد',
        ));
        $legacy_item = new Ashko_Test_Commerce_Order_Item();
        Product_Commerce::create_order_line_item($legacy_item, 'legacy', array('data' => $product), null);
        self::assertSame('عدد', $legacy_item->get_meta(Product_Commerce::UNIT_META, true));

        $empty_item = new Ashko_Test_Commerce_Order_Item();
        Product_Commerce::create_order_line_item(
            $empty_item,
            'snapshotted-empty',
            array('data' => $product, Product_Commerce::UNIT_META => ''),
            null
        );
        self::assertSame(array(), $empty_item->added);
    }

    public function test_formatted_order_meta_exposes_exactly_one_snapshot_row(): void {
        $item = new Ashko_Test_Commerce_Order_Item(array(Product_Commerce::UNIT_META => 'عدد'));

        $formatted = Product_Commerce::formatted_order_item_meta(array(), $item);
        self::assertCount(1, $formatted);
        $row = reset($formatted);
        self::assertSame(Product_Commerce::UNIT_META, $row->key);
        self::assertSame('واحد فروش', $row->display_key);
        self::assertSame('عدد', $row->display_value);

        $formatted = Product_Commerce::formatted_order_item_meta($formatted, $item);
        self::assertCount(1, $formatted);

        $duplicate = (object) array(
            'key' => Product_Commerce::UNIT_META,
            'value' => 'stale',
            'display_key' => 'stale',
            'display_value' => 'stale',
        );
        $formatted = Product_Commerce::formatted_order_item_meta(
            array(4 => clone $duplicate, 5 => clone $duplicate),
            $item
        );
        self::assertCount(1, $formatted);
        self::assertSame('واحد فروش', $formatted[4]->display_key);

        $empty_item = new Ashko_Test_Commerce_Order_Item();
        self::assertSame(array(), Product_Commerce::formatted_order_item_meta(array(), $empty_item));
    }

    public function test_variation_script_consumes_canonical_data_and_resets_to_parent(): void {
        Product_Commerce::enqueue_variation_script();

        self::assertCount(1, $GLOBALS['ashko_test_inline_scripts']);
        $script = $GLOBALS['ashko_test_inline_scripts'][0];
        self::assertSame('wc-add-to-cart-variation', $script['handle']);
        self::assertSame('after', $script['position']);
        self::assertStringContainsString('found_variation', $script['data']);
        self::assertStringContainsString('reset_data hide_variation', $script['data']);
        self::assertStringContainsString(Product_Commerce::VARIATION_UNIT_KEY, $script['data']);
        self::assertStringNotContainsString('variation._ashko_patris_unit', $script['data']);
        self::assertStringContainsString('data-default-unit', $script['data']);
    }
}

final class Ashko_Test_Commerce_Product {
    private int $id;
    private string $type;
    private int $parent_id;
    private array $meta;

    public function __construct(int $id, string $type = 'simple', int $parent_id = 0, array $meta = array()) {
        $this->id = $id;
        $this->type = $type;
        $this->parent_id = $parent_id;
        $this->meta = $meta;
        $GLOBALS['ashko_test_products'][$id] = $this;
    }

    public function get_id(): int { return $this->id; }
    public function is_type(string $type): bool { return $this->type === $type; }
    public function get_type(): string { return $this->type; }
    public function get_parent_id(): int { return $this->parent_id; }
    public function get_meta($key, $single = true, $context = 'view') { return $this->meta[$key] ?? ''; }
    public function set_meta(string $key, string $value): void { $this->meta[$key] = $value; }
}

final class Ashko_Test_Commerce_Order_Item {
    private array $meta;
    public array $added = array();

    public function __construct(array $meta = array()) {
        $this->meta = $meta;
    }

    public function add_meta_data($key, $value, $unique = false): void {
        $this->added[] = array('key' => $key, 'value' => $value, 'unique' => (bool) $unique);
        if (!$unique || !array_key_exists($key, $this->meta)) {
            $this->meta[$key] = $value;
        }
    }

    public function get_meta($key, $single = true) {
        return $this->meta[$key] ?? '';
    }
}
