<?php
namespace Ashko\Patris;

/** Exact non-negative decimal arithmetic for Ashko pricing and stock policy. */
final class Decimal_Calculator {
    /** Calculate the approved expression in IRR and round once, at the end. */
    public static function price($foreign_cny, $weight_grams, $fx_irr, $freight_irr_per_kg, $margin_percent): ?array {
        $cny = self::parts($foreign_cny);
        $weight = self::parts($weight_grams);
        $fx = self::parts($fx_irr);
        $freight_rate = self::parts($freight_irr_per_kg);
        $margin = self::parts($margin_percent);
        if (null === $cny || null === $weight || null === $fx || null === $freight_rate || null === $margin) {
            return null;
        }

        $goods_irr = self::multiply($cny, $fx);
        $freight_irr = self::multiply($weight, $freight_rate);
        $freight_irr['scale'] += 3;
        $landed_irr = self::add($goods_irr, $freight_irr);
        $multiplier = self::add(self::parts('100'), $margin);
        $sale_irr = self::multiply($landed_irr, $multiplier);
        $sale_irr['scale'] += 2;

        $woo_irr = self::round_half_up($sale_irr);
        $sale_irt = $sale_irr;
        $sale_irt['scale'] += 1;
        $native_irt = self::round_half_up($sale_irt);

        return array(
            'native_final_irt' => $native_irt,
            'woo_final_irr' => $woo_irr,
            'formula' => '((CNY × FX_IRR) + ((weight_g ÷ 1000) × freight_IRR_per_kg)) × (1 + margin ÷ 100), one final half-up round in IRR',
        );
    }

    public static function stock($total_stock, $percent = '30'): ?int {
        $stock = self::parts($total_stock);
        $factor = self::parts($percent);
        if (null === $stock || null === $factor) {
            return null;
        }
        $scaled = self::multiply($stock, $factor);
        $scaled['scale'] += 2;
        $floor = self::floor_integer($scaled);
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

    private static function round_half_up(array $decimal): string {
        $integer = self::floor_integer($decimal);
        if ($decimal['scale'] <= 0) {
            return $integer;
        }
        $padded = str_pad($decimal['digits'], $decimal['scale'] + 1, '0', STR_PAD_LEFT);
        $cut = strlen($padded) - $decimal['scale'];
        return (int) $padded[$cut] >= 5 ? self::add_integer($integer, '1') : $integer;
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
