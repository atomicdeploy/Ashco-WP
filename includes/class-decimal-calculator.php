<?php
namespace Ashko\Patris;

/** Exact non-negative decimal arithmetic for Ashco pricing and stock policy. */
final class Decimal_Calculator {
    public const DOMESTIC_SHIPPING_METHOD = 'domestic';
    public const DOMESTIC_SHIPPING_PRICE_PER_KG = '0';
    public const DOMESTIC_SHIPPING_CURRENCY = 'IRR';
    public const PRICE_FORMULA = 'foreign_price/CNY: ((amount × FX_IRR) + freight_IRR) × (1 + margin ÷ 100); partner_price/IRR: amount × (1 + margin ÷ 100), with domestic freight fixed at 0 IRR/kg; sale_price_direct/IRR (disabled by default): use source IRR unchanged and convert exactly to integer IRT; calculated paths use one final nearest-half-up round to 10^price_rounding_digits IRT, then ×10 for Woo IRR';
    public const STOCK_FORMULA = 'omitted or null total_stock: no stock write; total_stock <= 0: 0; total_stock > 0: max(1, floor(total_stock × stock_percent ÷ 100))';

    /**
     * Calculate the selected living price-source expression.
     *
     * Rounding precision is expressed in canonical IRT digits. WooCommerce is
     * therefore written with the exact rounded canonical result multiplied by
     * ten, so both representations remain identical.
     */
    public static function price(
        $price_source_amount,
        $price_source_currency,
        $price_source_kind,
        $weight_grams,
        $fx_irr,
        $shipping_price_per_kg,
        $shipping_price_per_kg_currency,
        $margin_percent,
        $price_rounding_digits
    ): ?array {
        $amount = self::parts($price_source_amount);
        $source_currency = (string) $price_source_currency;
        $source_kind = (string) $price_source_kind;
        if (null === $amount || '0' === $amount['digits']) {
            return null;
        }

        if ('sale_price_direct' === $source_kind && 'IRR' === $source_currency) {
            $native_irt = $amount;
            $native_irt['scale'] += 1;
            if (!self::is_integer($native_irt)) {
                return null;
            }
            $native_irt = self::floor_integer($native_irt);
            return array(
                'native_final_irt' => $native_irt,
                'woo_final_irr' => self::text($amount),
                'formula' => self::PRICE_FORMULA,
                'price_source_amount' => self::text($amount),
                'price_source_currency' => $source_currency,
                'price_source_kind' => $source_kind,
                'price_rounding_digits' => '',
                'price_rounding_mode' => '',
                'shipping_price_per_kg_currency' => self::DOMESTIC_SHIPPING_CURRENCY,
            );
        }

        $margin = self::parts($margin_percent);
        $rounding_digits = self::rounding_digits($price_rounding_digits);
        if (null === $margin || null === $rounding_digits) {
            return null;
        }

        if ('foreign_price' === $source_kind && 'CNY' === $source_currency) {
            $weight = self::parts($weight_grams);
            $fx = self::parts($fx_irr);
            $shipping_rate = self::parts($shipping_price_per_kg);
            $shipping_currency = (string) $shipping_price_per_kg_currency;
            if (
                null === $weight
                || null === $fx
                || null === $shipping_rate
                || !in_array($shipping_currency, array('CNY', 'IRR'), true)
            ) {
                return null;
            }
            $goods_irr = self::multiply($amount, $fx);
            $shipping_irr = self::multiply($weight, $shipping_rate);
            $shipping_irr['scale'] += 3;
            if ('CNY' === $shipping_currency) {
                $shipping_irr = self::multiply($shipping_irr, $fx);
            }
            $base_irr = self::add($goods_irr, $shipping_irr);
        } elseif ('partner_price' === $source_kind && 'IRR' === $source_currency) {
            $base_irr = $amount;
            $shipping_currency = self::DOMESTIC_SHIPPING_CURRENCY;
        } else {
            return null;
        }

        $multiplier = self::add(self::parts('100'), $margin);
        $sale_irr = self::multiply($base_irr, $multiplier);
        $sale_irr['scale'] += 2;

        $sale_irt = $sale_irr;
        $sale_irt['scale'] += 1;
        $native_irt = self::round_half_up_to_digits($sale_irt, $rounding_digits);
        $woo_irr = self::multiply_integer($native_irt, '10');

        return array(
            'native_final_irt' => $native_irt,
            'woo_final_irr' => $woo_irr,
            'formula' => self::PRICE_FORMULA,
            'price_source_amount' => self::text($amount),
            'price_source_currency' => $source_currency,
            'price_source_kind' => $source_kind,
            'price_rounding_digits' => (string) $rounding_digits,
            'price_rounding_mode' => 'nearest_half_up',
            'shipping_price_per_kg_currency' => $shipping_currency,
        );
    }

    public static function positive_decimal($value): bool {
        $parts = self::parts($value);
        return null !== $parts && '0' !== $parts['digits'];
    }

    public static function stock($total_stock, $percent = '30'): ?int {
        if (null === $total_stock || '' === $total_stock) {
            return null;
        }
        $stock_text = is_float($total_stock)
            ? json_encode($total_stock, JSON_PRESERVE_ZERO_FRACTION)
            : (string) $total_stock;
        if (preg_match('/^-(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $stock_text)) {
            return 0;
        }
        $stock = self::parts($total_stock);
        if (null === $stock) {
            return null;
        }
        if ('0' === $stock['digits']) {
            return 0;
        }
        $factor = self::parts($percent);
        if (null === $factor) {
            return null;
        }
        $scaled = self::multiply($stock, $factor);
        $scaled['scale'] += 2;
        $floor = self::floor_integer($scaled);
        if ('0' !== $stock['digits'] && '0' === $floor) {
            $floor = '1';
        }
        if (self::compare_integer($floor, (string) PHP_INT_MAX) > 0) {
            return PHP_INT_MAX;
        }
        return (int) $floor;
    }

    public static function difference($left, $right): ?int {
        if (!preg_match('/^-?[0-9]+$/', (string) $left) || !preg_match('/^-?[0-9]+$/', (string) $right)) {
            return null;
        }
        return (int) $left - (int) $right;
    }

    private static function parts($value): ?array {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_float($value)) {
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        }
        $text = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $text, $matches)) {
            return null;
        }
        $fraction = $matches[2] ?? '';
        $digits = ltrim($matches[1] . $fraction, '0');
        return array('digits' => '' === $digits ? '0' : $digits, 'scale' => strlen($fraction));
    }

    private static function add(array $left, array $right): array {
        $scale = max($left['scale'], $right['scale']);
        $a = $left['digits'] . str_repeat('0', $scale - $left['scale']);
        $b = $right['digits'] . str_repeat('0', $scale - $right['scale']);
        return array('digits' => self::add_integer($a, $b), 'scale' => $scale);
    }

    private static function multiply(array $left, array $right): array {
        return array(
            'digits' => self::multiply_integer($left['digits'], $right['digits']),
            'scale' => $left['scale'] + $right['scale'],
        );
    }

    private static function floor_integer(array $decimal): string {
        if ($decimal['scale'] <= 0) {
            return self::normalize($decimal['digits'] . str_repeat('0', -$decimal['scale']));
        }
        $padded = str_pad($decimal['digits'], $decimal['scale'] + 1, '0', STR_PAD_LEFT);
        return self::normalize(substr($padded, 0, strlen($padded) - $decimal['scale']));
    }

    private static function is_integer(array $decimal): bool {
        if ($decimal['scale'] <= 0) {
            return true;
        }
        $padded = str_pad($decimal['digits'], $decimal['scale'] + 1, '0', STR_PAD_LEFT);
        return '' === trim(substr($padded, -$decimal['scale']), '0');
    }

    private static function round_half_up(array $decimal): string {
        $integer = self::floor_integer($decimal);
        if ($decimal['scale'] <= 0) {
            return $integer;
        }
        $padded = str_pad($decimal['digits'], $decimal['scale'] + 1, '0', STR_PAD_LEFT);
        $cut = strlen($padded) - $decimal['scale'];
        return (int) $padded[$cut] >= 5 ? self::add_integer($integer, '1') : $integer;
    }

    private static function round_half_up_to_digits(array $decimal, int $digits): string {
        $scaled = $decimal;
        $scaled['scale'] += $digits;
        return self::normalize(self::round_half_up($scaled) . str_repeat('0', $digits));
    }

    private static function rounding_digits($value): ?int {
        $text = (string) $value;
        return preg_match('/^[0-9]$/', $text) ? (int) $text : null;
    }

    private static function text(array $decimal): string {
        if (0 === $decimal['scale']) {
            return self::normalize($decimal['digits']);
        }
        $digits = str_pad($decimal['digits'], $decimal['scale'] + 1, '0', STR_PAD_LEFT);
        $cut = strlen($digits) - $decimal['scale'];
        return self::normalize(substr($digits, 0, $cut)) . '.' . substr($digits, $cut);
    }

    private static function add_integer(string $left, string $right): string {
        $left = strrev(self::normalize($left));
        $right = strrev(self::normalize($right));
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $sum = ($i < strlen($left) ? (int) $left[$i] : 0)
                + ($i < strlen($right) ? (int) $right[$i] : 0) + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry) {
            $result .= (string) $carry;
        }
        return self::normalize(strrev($result));
    }

    private static function multiply_integer(string $left, string $right): string {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if ('0' === $left || '0' === $right) {
            return '0';
        }
        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }
        return self::normalize(implode('', $result));
    }

    private static function compare_integer(string $left, string $right): int {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }
        return strcmp($left, $right) <=> 0;
    }

    private static function normalize(string $value): string {
        $value = ltrim($value, '0');
        return '' === $value ? '0' : $value;
    }
}
