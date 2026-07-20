<?php
namespace Ashko\Patris;

final class Frontend_Stock {
    private static array $single_summary_rendered = array();

    public static function register(): void {
        add_filter('pre_option_woocommerce_hide_out_of_stock_items', array(self::class, 'keep_visible'));
        add_filter('woocommerce_get_stock_html', array(self::class, 'stock_html'), 20, 2);
        add_action('woocommerce_single_product_summary', array(self::class, 'single_product_stock_fallback'), 31);
    }

    public static function keep_visible($value) {
        return 'yes' === Config::get('keep_out_of_stock_visible', 'yes') ? 'no' : $value;
    }

    public static function stock_html($html, $product): string {
        $exact_html = self::exact_stock_html($product);
        if (null === $exact_html) {
            return (string) $html;
        }

        if (doing_action('woocommerce_single_product_summary')) {
            self::$single_summary_rendered[self::product_key($product)] = true;
        }

        return $exact_html;
    }

    public static function single_product_stock_fallback(): void {
        global $product;

        if (!$product || !is_object($product)) {
            return;
        }

        $key = self::product_key($product);
        if (isset(self::$single_summary_rendered[$key])) {
            unset(self::$single_summary_rendered[$key]);
            return;
        }

        $exact_html = self::exact_stock_html($product);
        unset(self::$single_summary_rendered[$key]);
        if (null !== $exact_html) {
            echo wp_kses_post($exact_html);
        }
    }

    private static function exact_stock_html($product): ?string {
        if (
            'yes' !== Config::get('show_exact_stock', 'yes')
            || !$product
            || !is_object($product)
            || !method_exists($product, 'get_meta')
            || !method_exists($product, 'get_stock_quantity')
            || '' === (string) $product->get_meta('_ashko_patris_record_hash', true)
        ) {
            return null;
        }

        $quantity = $product->get_stock_quantity();
        if (null === $quantity) {
            return null;
        }
        $class = $quantity > 0 ? 'in-stock' : 'out-of-stock';
        return '<p class="stock ' . esc_attr($class) . '">' . esc_html(sprintf(__('موجودی قابل فروش اشکو: %s', 'ashko-wp'), number_format_i18n((int) $quantity))) . '</p>';
    }

    private static function product_key($product): string {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $id = (int) $product->get_id();
            if ($id > 0) {
                return 'product:' . $id;
            }
        }

        return is_object($product) ? 'object:' . spl_object_id($product) : 'unknown';
    }
}
