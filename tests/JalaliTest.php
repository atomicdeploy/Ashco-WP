<?php
use Ashko\Patris\Jalali;
use PHPUnit\Framework\TestCase;

final class JalaliTest extends TestCase {
    public function test_iso_date_has_correct_jalali_equivalent(): void {
        self::assertSame('1405-04-29', Jalali::from_iso('2026-07-20'));
        self::assertSame('1403-01-01', Jalali::from_iso('2024-03-20'));
        self::assertSame('', Jalali::from_iso('2026-99-99'));
    }
}
