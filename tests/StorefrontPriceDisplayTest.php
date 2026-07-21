<?php
use Ashko\Patris\Storefront_Price_Display;
use PHPUnit\Framework\TestCase;

final class StorefrontPriceDisplayTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_hooks'] = array();
        $GLOBALS['ashko_test_request_context'] = array();
        $GLOBALS['ashko_test_enqueued_styles'] = array();
        $GLOBALS['ashko_test_enqueued_scripts'] = array();
        $GLOBALS['ashko_test_localized_scripts'] = array();
        $GLOBALS['ashko_test_currency'] = 'IRR';
    }

    public function test_registers_only_frontend_asset_and_control_hooks(): void {
        Storefront_Price_Display::register();

        self::assertSame(
            array('wp_enqueue_scripts', 'wp_footer'),
            array_column($GLOBALS['ashko_test_hooks'], 'hook')
        );
        self::assertSame(array(30, 20), array_column($GLOBALS['ashko_test_hooks'], 'priority'));
        foreach ($GLOBALS['ashko_test_hooks'] as $hook) {
            self::assertSame('action', $hook['type']);
        }
    }

    public function test_request_scope_excludes_all_non_storefront_and_transactional_contexts(): void {
        self::assertTrue(Storefront_Price_Display::is_eligible_request());

        foreach (array('admin', 'ajax', 'json', 'feed', 'embed', 'cart', 'checkout', 'account', 'wc_endpoint') as $context) {
            $GLOBALS['ashko_test_request_context'] = array($context => true);
            self::assertFalse(Storefront_Price_Display::is_eligible_request(), $context);
        }

        $GLOBALS['ashko_test_request_context'] = array();
        $GLOBALS['ashko_test_currency'] = 'IRT';
        self::assertFalse(Storefront_Price_Display::is_eligible_request());
    }

    public function test_enqueues_display_assets_with_exact_non_monetary_configuration(): void {
        Storefront_Price_Display::enqueue_assets();

        self::assertCount(1, $GLOBALS['ashko_test_enqueued_styles']);
        self::assertCount(1, $GLOBALS['ashko_test_enqueued_scripts']);
        self::assertCount(1, $GLOBALS['ashko_test_localized_scripts']);

        $style = $GLOBALS['ashko_test_enqueued_styles'][0];
        self::assertSame(Storefront_Price_Display::STYLE_HANDLE, $style['handle']);
        self::assertStringEndsWith('/assets/css/storefront-price-display.css', $style['src']);
        self::assertMatchesRegularExpression('/^1\.1\.0\.\d+$/', $style['version']);

        $script = $GLOBALS['ashko_test_enqueued_scripts'][0];
        self::assertSame(Storefront_Price_Display::SCRIPT_HANDLE, $script['handle']);
        self::assertStringEndsWith('/assets/js/storefront-price-display.js', $script['src']);
        self::assertSame(array('strategy' => 'defer', 'in_footer' => true), $script['args']);

        $localized = $GLOBALS['ashko_test_localized_scripts'][0];
        self::assertSame(Storefront_Price_Display::SCRIPT_HANDLE, $localized['handle']);
        self::assertSame('ashcoStorefrontPriceDisplay', $localized['object_name']);
        self::assertSame('IRR', $localized['data']['defaultCurrency']);
        self::assertSame(10, $localized['data']['conversionRate']);
        self::assertSame(array('IRR' => 'ریال', 'IRT' => 'تومان'), $localized['data']['labels']);
        self::assertArrayNotHasKey('price', $localized['data']);
        self::assertArrayNotHasKey('currencyRate', $localized['data']);
    }

    public function test_renders_a_native_keyboard_accessible_persian_radio_group(): void {
        ob_start();
        Storefront_Price_Display::render_switch();
        $html = ob_get_clean();

        self::assertStringContainsString('<fieldset', $html);
        self::assertStringContainsString('<legend', $html);
        self::assertStringContainsString('aria-label="انتخاب واحد نمایش قیمت" hidden', $html);
        self::assertSame(2, substr_count($html, 'type="radio"'));
        self::assertSame(1, substr_count($html, ' checked'));
        self::assertStringContainsString('value="IRR"', $html);
        self::assertStringContainsString('value="IRT"', $html);
        self::assertStringContainsString('ریال', $html);
        self::assertStringContainsString('تومان', $html);
        self::assertStringContainsString('هر ۱ تومان برابر با ۱۰ ریال است', $html);
        self::assertStringContainsString('aria-describedby="ashco-price-display-help ashco-price-display-status"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('aria-atomic="true"', $html);
    }

    public function test_blocked_context_outputs_and_enqueues_nothing(): void {
        $GLOBALS['ashko_test_request_context'] = array('checkout' => true);
        Storefront_Price_Display::enqueue_assets();
        ob_start();
        Storefront_Price_Display::render_switch();

        self::assertSame('', ob_get_clean());
        self::assertSame(array(), $GLOBALS['ashko_test_enqueued_styles']);
        self::assertSame(array(), $GLOBALS['ashko_test_enqueued_scripts']);
        self::assertSame(array(), $GLOBALS['ashko_test_localized_scripts']);
    }

    public function test_client_guards_dynamic_prices_and_transactional_fragments(): void {
        $script = file_get_contents(dirname(__DIR__) . '/assets/js/storefront-price-display.js');

        self::assertIsString($script);
        self::assertStringContainsString('new WeakMap()', $script);
        self::assertStringContainsString('MutationObserver', $script);
        self::assertStringContainsString('currentHtml !== state.renderedHtml', $script);
        self::assertStringContainsString('data-ashco-render-token', $script);
        self::assertStringContainsString('data-ashco-irr-text-values', $script);
        self::assertStringContainsString('isExactTomanClone', $script);
        self::assertStringContainsString('restoreIrrTextHtml', $script);
        self::assertStringContainsString('textNode.nodeValue = values[index]', $script);
        self::assertStringNotContainsString('data-ashco-irr-html', $script);
        self::assertStringContainsString('.woocommerce-mini-cart', $script);
        self::assertStringContainsString('.wc-block-cart', $script);
        self::assertStringContainsString('.cart-widget-side', $script);
        self::assertStringContainsString('.woocommerce-order-details', $script);
        self::assertStringContainsString('wc-block-order-confirmation', $script);
        self::assertStringContainsString('.woocommerce-MyAccount-content', $script);
        self::assertStringContainsString('excludedTargets.forEach(restoreExcludedTarget)', $script);
        self::assertStringContainsString("element.removeAttribute('data-ashco-display-currency')", $script);
        self::assertStringContainsString('localStorage', $script);
        self::assertStringContainsString('samesite=lax', $script);
        self::assertStringNotContainsString('woocommerce_get_price', $script);
    }
}
