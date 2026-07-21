<?php
namespace Ashko\Patris;

final class Product_Commerce {
    public const UNIT_META = '_ashko_patris_unit';
    public const VARIATION_UNIT_KEY = 'ashco_patris_unit';

    public static function register(): void {
        add_action('woocommerce_after_add_to_cart_quantity', array(self::class, 'render_quantity_unit'));
        add_filter('woocommerce_available_variation', array(self::class, 'available_variation'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(self::class, 'add_cart_item_data'), 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', array(self::class, 'restore_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array(self::class, 'cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(self::class, 'create_order_line_item'), 10, 4);
        add_filter('woocommerce_order_item_get_formatted_meta_data', array(self::class, 'formatted_order_item_meta'), 10, 2);
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_variation_script'), 20);
    }

    public static function resolve_unit($product): string {
        if (!$product || !is_object($product) || !method_exists($product, 'get_meta')) {
            return '';
        }

        $unit = self::normalize_unit($product->get_meta(self::UNIT_META, true, 'edit'));
        if ('' !== $unit) {
            return $unit;
        }

        if (!self::is_variation($product) || !method_exists($product, 'get_parent_id')) {
            return '';
        }

        $parent_id = (int) $product->get_parent_id();
        if ($parent_id <= 0 || !function_exists('wc_get_product')) {
            return '';
        }

        $parent = wc_get_product($parent_id);
        if (!$parent || $parent === $product || !method_exists($parent, 'get_meta')) {
            return '';
        }

        return self::normalize_unit($parent->get_meta(self::UNIT_META, true, 'edit'));
    }

    public static function render_quantity_unit(): void {
        global $product;

        if (!$product || !is_object($product)) {
            return;
        }

        $unit = self::resolve_unit($product);
        $variable = method_exists($product, 'is_type') && $product->is_type('variable');
        if ('' === $unit && !$variable) {
            return;
        }

        $hidden = '' === $unit;
        echo '<span class="ashco-patris-sales-unit" data-default-unit="' . esc_attr($unit) . '" aria-live="polite"';
        if ($hidden) {
            echo ' hidden aria-hidden="true"';
        } else {
            echo ' aria-hidden="false"';
        }
        echo '><span class="ashco-patris-sales-unit__label">' . esc_html__('واحد فروش:', 'ashko-wp') . '</span> ';
        echo '<span class="ashco-patris-sales-unit__value">' . esc_html($unit) . '</span></span>';
    }

    public static function available_variation(array $data, $product, $variation): array {
        $unit = self::resolve_unit($variation);
        if ('' === $unit) {
            unset($data[self::VARIATION_UNIT_KEY]);
            return $data;
        }

        $data[self::VARIATION_UNIT_KEY] = $unit;
        return $data;
    }

    public static function add_cart_item_data(array $cart_item_data, $product_id, $variation_id, $quantity): array {
        $selected_id = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
        $product = $selected_id > 0 && function_exists('wc_get_product') ? wc_get_product($selected_id) : null;
        $cart_item_data[self::UNIT_META] = self::resolve_unit($product);
        return $cart_item_data;
    }

    public static function restore_cart_item_data(array $session_data, array $values, $cart_item_key): array {
        if (array_key_exists(self::UNIT_META, $values)) {
            $session_data[self::UNIT_META] = self::normalize_unit($values[self::UNIT_META]);
            return $session_data;
        }

        $product = $session_data['data'] ?? null;
        $session_data[self::UNIT_META] = self::resolve_unit($product);
        return $session_data;
    }

    public static function cart_item_data(array $item_data, array $cart_item): array {
        if (!array_key_exists(self::UNIT_META, $cart_item)) {
            return $item_data;
        }

        $unit = self::normalize_unit($cart_item[self::UNIT_META]);
        if ('' === $unit) {
            return $item_data;
        }

        $row = array(
            'key' => __('واحد فروش', 'ashko-wp'),
            'value' => $unit,
            'display' => esc_html($unit),
        );
        foreach ($item_data as $existing) {
            if (
                is_array($existing)
                && $row['key'] === (string) ($existing['key'] ?? '')
                && $unit === self::normalize_unit($existing['value'] ?? '')
                && $unit === self::normalize_unit($existing['display'] ?? '')
            ) {
                return $item_data;
            }
        }
        $item_data[] = $row;
        return $item_data;
    }

    public static function create_order_line_item($item, $cart_item_key, array $values, $order): void {
        if (!$item || !is_object($item) || !method_exists($item, 'add_meta_data')) {
            return;
        }

        if (array_key_exists(self::UNIT_META, $values)) {
            $unit = self::normalize_unit($values[self::UNIT_META]);
        } else {
            $unit = self::resolve_unit($values['data'] ?? null);
        }
        if ('' !== $unit) {
            $item->add_meta_data(self::UNIT_META, $unit, true);
        }
    }

    public static function formatted_order_item_meta(array $formatted_meta, $item): array {
        if (!$item || !is_object($item) || !method_exists($item, 'get_meta')) {
            return $formatted_meta;
        }

        $unit = self::normalize_unit($item->get_meta(self::UNIT_META, true));
        $first = null;
        foreach ($formatted_meta as $index => $meta) {
            if (!is_object($meta) || self::UNIT_META !== (string) ($meta->key ?? '')) {
                continue;
            }
            if (null === $first) {
                $first = $index;
            } else {
                unset($formatted_meta[$index]);
            }
        }

        if ('' === $unit) {
            if (null !== $first) {
                unset($formatted_meta[$first]);
            }
            return $formatted_meta;
        }

        if (null !== $first) {
            $formatted_meta[$first]->key = self::UNIT_META;
            $formatted_meta[$first]->value = $unit;
            $formatted_meta[$first]->display_key = __('واحد فروش', 'ashko-wp');
            $formatted_meta[$first]->display_value = esc_html($unit);
            return $formatted_meta;
        }

        $formatted_meta['ashco_patris_unit'] = (object) array(
            'key' => self::UNIT_META,
            'value' => $unit,
            'display_key' => __('واحد فروش', 'ashko-wp'),
            'display_value' => esc_html($unit),
        );
        return $formatted_meta;
    }

    public static function enqueue_variation_script(): void {
        if (!function_exists('wp_add_inline_script')) {
            return;
        }

        $script = <<<'JS'
(function ($) {
    'use strict';

    function setUnit($form, unit) {
        var $container = $form.find('.ashco-patris-sales-unit').first();
        if (!$container.length) {
            return;
        }
        unit = typeof unit === 'string' ? unit.trim() : '';
        $container.find('.ashco-patris-sales-unit__value').text(unit);
        $container.prop('hidden', unit === '').attr('aria-hidden', unit === '' ? 'true' : 'false');
    }

    $(document).on('found_variation', '.variations_form', function (event, variation) {
        setUnit($(this), variation && variation.ashco_patris_unit ? variation.ashco_patris_unit : '');
    });

    $(document).on('reset_data hide_variation', '.variations_form', function () {
        var $form = $(this);
        setUnit($form, $form.find('.ashco-patris-sales-unit').first().attr('data-default-unit') || '');
    });
}(jQuery));
JS;

        wp_add_inline_script('wc-add-to-cart-variation', $script, 'after');
    }

    private static function normalize_unit($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        return sanitize_text_field((string) $value);
    }

    private static function is_variation($product): bool {
        if (method_exists($product, 'is_type')) {
            return (bool) $product->is_type('variation');
        }
        if (method_exists($product, 'get_type')) {
            return 'variation' === (string) $product->get_type();
        }
        return false;
    }
}
