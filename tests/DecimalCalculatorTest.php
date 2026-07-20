<?php
use Ashko\Patris\Decimal_Calculator;
use PHPUnit\Framework\TestCase;

final class DecimalCalculatorTest extends TestCase {
    public function test_native_irr_half_ties_are_not_rounded_through_irt(): void {
        $cases = array(
            array('0.0215', '2', '65585', '6559'),
            array('0.0769', '0.24', '36855', '3686'),
            array('0.03', '0.025', '12415', '1242'),
        );
        foreach ($cases as [$cny, $weight, $expected_irr, $expected_irt]) {
            $result = Decimal_Calculator::price($cny, $weight, '300000', '22000000', '30');
            self::assertSame($expected_irr, $result['woo_final_irr']);
            self::assertSame($expected_irt, $result['native_final_irt']);
        }
    }

    public function test_price_uses_approved_native_irr_formula(): void {
        $result = Decimal_Calculator::price('24.5', '240', '300000', '22000000', '30');
        self::assertSame('16419000', $result['woo_final_irr']);
    }

    public function test_stock_is_floor_of_thirty_percent(): void {
        self::assertSame(642, Decimal_Calculator::stock('2141', '30'));
        self::assertSame(0, Decimal_Calculator::stock('3', '30'));
        self::assertSame(1, Decimal_Calculator::stock('6.5', '30'));
    }
}
