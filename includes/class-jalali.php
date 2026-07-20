<?php
namespace Ashko\Patris;

final class Jalali {
    public static function from_iso(string $iso): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return '';
        }
        [$jy, $jm, $jd] = self::convert((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
    }

    /** @return int[] */
    private static function convert(int $gy, int $gm, int $gd): array {
        $gdm = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return array($jy, $jm, $jd);
    }
}
