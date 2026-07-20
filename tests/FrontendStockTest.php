<?php
use Ashko\Patris\Config;
use Ashko\Patris\Frontend_Stock;
use PHPUnit\Framework\TestCase;

final class FrontendStockTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_hooks'] = array();
        $GLOBALS['ashko_test_current_actions'] = array();
        $GLOBALS['ashko_test_options'][Config::OPTION] = array(
            'show_exact_stock' => 'yes',
            'keep_out_of_stock_visible' => 'yes',
        );
        $GLOBALS['product'] = null;
    }

    public function test_register_keeps_stock_filter_and_adds_post_cart_fallback(): void {
        Frontend_Stock::register();

        self::assertContains(array(
            'type' => 'filter',
            'hook' => 'woocommerce_get_stock_html',
            'callback' => array(Frontend_Stock::class, 'stock_html'),
            'priority' => 20,
            'accepted_args' => 2,
        ), $GLOBALS['ashko_test_hooks']);
        self::assertContains(array(
            'type' => 'action',
            'hook' => 'woocommerce_single_product_summary',
            'callback' => array(Frontend_Stock::class, 'single_product_stock_fallback'),
            'priority' => 31,
            'accepted_args' => 1,
        ), $GLOBALS['ashko_test_hooks']);
    }

    public function test_normal_stock_filter_suppresses_fallback_duplicate(): void {
        $product = $this->synced_product(301, 12);
        $GLOBALS['product'] = $product;
        $GLOBALS['ashko_test_current_actions']['woocommerce_single_product_summary'] = true;

        $normal = Frontend_Stock::stock_html('', $product);
        ob_start();
        Frontend_Stock::single_product_stock_fallback();
        $fallback = ob_get_clean();

        self::assertStringContainsString('موجودی قابل فروش اشکو: 12', $normal);
        self::assertSame('', $fallback);
    }

    public function test_fallback_renders_exact_stock_when_theme_omits_template(): void {
        $GLOBALS['product'] = $this->synced_product(302, 7);
        $GLOBALS['ashko_test_current_actions']['woocommerce_single_product_summary'] = true;

        ob_start();
        Frontend_Stock::single_product_stock_fallback();
        $html = ob_get_clean();

        self::assertStringContainsString('class="stock in-stock"', $html);
        self::assertStringContainsString('موجودی قابل فروش اشکو: 7', $html);
    }

    public function test_fallback_renders_zero_as_out_of_stock(): void {
        $GLOBALS['product'] = $this->synced_product(303, 0);

        ob_start();
        Frontend_Stock::single_product_stock_fallback();
        $html = ob_get_clean();

        self::assertStringContainsString('class="stock out-of-stock"', $html);
        self::assertStringContainsString('موجودی قابل فروش اشکو: 0', $html);
    }

    public function test_fallback_ignores_products_not_owned_by_patris_sync(): void {
        $GLOBALS['product'] = new Ashko_Test_Product(304, array('stock_quantity' => 9));

        ob_start();
        Frontend_Stock::single_product_stock_fallback();
        $html = ob_get_clean();

        self::assertSame('', $html);
    }

    private function synced_product(int $id, int $quantity): Ashko_Test_Product {
        return new Ashko_Test_Product(
            $id,
            array('stock_quantity' => $quantity),
            array('_ashko_patris_record_hash' => 'sha256:' . str_repeat('a', 64))
        );
    }
}
