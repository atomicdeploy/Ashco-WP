<?php
use Ashko\Patris\Decimal_Calculator;
use PHPUnit\Framework\TestCase;

final class DecimalCalculatorTest extends TestCase {
    public function test_canonical_irt_half_ties_round_once_and_map_exactly_to_woo_irr(): void {
        $cases = array(
            array('0.0215', '2', '65590', '6559'),
            array('0.0769', '0.24', '36860', '3686'),
            array('0.03', '0.025', '12420', '1242'),
        );
        foreach ($cases as [$cny, $weight, $expected_irr, $expected_irt]) {
            $result = Decimal_Calculator::price(
                $cny, 'CNY', 'foreign_price', $weight, '300000', '22000000', 'IRR', '30', '0'
            );
            self::assertSame($expected_irr, $result['woo_final_irr']);
            self::assertSame($expected_irt, $result['native_final_irt']);
        }
    }

    public function test_price_uses_approved_native_irr_formula(): void {
        $result = Decimal_Calculator::price(
            '24.5', 'CNY', 'foreign_price', '240', '300000', '22000000', 'IRR', '30', '0'
        );
        self::assertSame('16419000', $result['woo_final_irr']);
    }

    public function test_equivalent_cny_and_irr_shipping_rates_produce_the_same_price(): void {
        $irr = Decimal_Calculator::price(
            '24.5', 'CNY', 'foreign_price', '240', '300000', '30000000', 'IRR', '30', '0'
        );
        $cny = Decimal_Calculator::price(
            '24.5', 'CNY', 'foreign_price', '240', '300000', '100', 'CNY', '30', '0'
        );

        self::assertSame('18915000', $irr['woo_final_irr']);
        self::assertSame($irr['woo_final_irr'], $cny['woo_final_irr']);
        self::assertSame($irr['native_final_irt'], $cny['native_final_irt']);
    }

    public function test_shipping_currency_is_required_and_limited_to_cny_or_irr(): void {
        self::assertNull(Decimal_Calculator::price(
            '24.5', 'CNY', 'foreign_price', '240', '300000', '100', '', '30', '0'
        ));
        self::assertNull(Decimal_Calculator::price(
            '24.5', 'CNY', 'foreign_price', '240', '300000', '100', 'IRT', '30', '0'
        ));
    }

    public function test_partner_irr_price_needs_only_margin_and_rounding(): void {
        $result = Decimal_Calculator::price(
            '1000000', 'IRR', 'partner_price', null, null, null, null, '30', '0'
        );

        self::assertSame('130000', $result['native_final_irt']);
        self::assertSame('1300000', $result['woo_final_irr']);
        self::assertSame('partner_price', $result['price_source_kind']);
        self::assertSame('IRR', $result['shipping_price_per_kg_currency']);
    }

    public function test_configurable_digits_round_up_or_down_to_nearest_canonical_irt_increment(): void {
        $up = Decimal_Calculator::price(
            '1234560', 'IRR', 'partner_price', null, null, null, null, '0', '2'
        );
        $down = Decimal_Calculator::price(
            '1234440', 'IRR', 'partner_price', null, null, null, null, '0', '2'
        );
        $tie = Decimal_Calculator::price(
            '1234500', 'IRR', 'partner_price', null, null, null, null, '0', '2'
        );

        self::assertSame('123500', $up['native_final_irt']);
        self::assertSame('1235000', $up['woo_final_irr']);
        self::assertSame('123400', $down['native_final_irt']);
        self::assertSame('123500', $tie['native_final_irt']);
        self::assertSame('nearest_half_up', $tie['price_rounding_mode']);
    }

    public function test_direct_sale_price_uses_source_irr_unchanged_without_margin_freight_or_rounding(): void {
        $result = Decimal_Calculator::price(
            '1234560', 'IRR', 'sale_price_direct', null, null, null, null, null, null
        );

        self::assertSame('123456', $result['native_final_irt']);
        self::assertSame('1234560', $result['woo_final_irr']);
        self::assertSame('sale_price_direct', $result['price_source_kind']);
        self::assertSame('', $result['price_rounding_digits']);
        self::assertSame('IRR', $result['shipping_price_per_kg_currency']);

        self::assertNull(Decimal_Calculator::price(
            '1234561', 'IRR', 'sale_price_direct', null, null, null, null, null, null
        ));
    }

    public function test_zero_or_invalid_selected_price_is_not_determined(): void {
        self::assertNull(Decimal_Calculator::price(
            '0', 'IRR', 'partner_price', null, null, null, null, '30', '0'
        ));
        self::assertNull(Decimal_Calculator::price(
            '100', 'CNY', 'partner_price', null, null, null, null, '30', '0'
        ));
    }

    public function test_stock_is_floored_but_any_positive_source_stock_has_a_minimum_of_one(): void {
        self::assertSame(642, Decimal_Calculator::stock('2141', '30'));
        self::assertSame(1, Decimal_Calculator::stock('1', '30'));
        self::assertSame(1, Decimal_Calculator::stock('2', '30'));
        self::assertSame(1, Decimal_Calculator::stock('3', '30'));
        self::assertSame(1, Decimal_Calculator::stock('6.5', '30'));
        self::assertSame(0, Decimal_Calculator::stock('0', '30'));
        self::assertSame(0, Decimal_Calculator::stock('-5', '30'));
        self::assertNull(Decimal_Calculator::stock(null, '30'));
        self::assertNull(Decimal_Calculator::stock('invalid', '30'));
    }
}
