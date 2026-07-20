<?php
namespace Ashko\Patris;

final class WooCommerce_Currency_Status {
    public const REQUIRED_CURRENCY = 'IRR';
    public const INCOMPATIBLE_WARNING = 'Ashko stores WooCommerce prices in IRR; Patris contract IRT values are converted by multiplying by 10.';

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_status(): array {
        $code = function_exists('get_woocommerce_currency')
            ? strtoupper((string) get_woocommerce_currency())
            : strtoupper((string) get_option('woocommerce_currency', ''));
        return array(
            'code' => $code,
            'compatible' => self::REQUIRED_CURRENCY === $code,
            'required' => self::REQUIRED_CURRENCY,
        );
    }
}
