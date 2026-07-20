<?php
namespace Ashko\Patris;

final class Frontend_Stock {
    public static function register(): void {
        add_filter('pre_option_woocommerce_hide_out_of_stock_items', array(self::class, 'keep_visible'));
        add_filter('woocommerce_get_stock_html', array(self::class, 'stock_html'), 20, 2);
    }

    public static function keep_visible($value) {
        return 'yes' === Config::get('keep_out_of_stock_visible', 'yes') ? 'no' : $value;
    }

    public static function stock_html($html, $product): string {
        if ('yes' !== Config::get('show_exact_stock', 'yes') || !$product || '' === (string) $product->get_meta('_ashko_patris_record_hash', true)) {
            return (string) $html;
        }
        $quantity = $product->get_stock_quantity();
        if (null === $quantity) {
            return (string) $html;
        }
        $class = $quantity > 0 ? 'in-stock' : 'out-of-stock';
        return '<p class="stock ' . esc_attr($class) . '">' . esc_html(sprintf(__('موجودی قابل فروش اشکو: %s', 'ashko-wp'), number_format_i18n((int) $quantity))) . '</p>';
    }
}
