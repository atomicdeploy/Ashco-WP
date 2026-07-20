<?php
namespace Ashko\Patris;

use WP_Error;

final class Memory_Guard {
    private const MINIMUM_BYTES = 201326592; // 192 MiB.

    public static function ensure() {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        $raw = (string) ini_get('memory_limit');
        $bytes = self::bytes($raw);
        if ($bytes > 0 && $bytes < self::MINIMUM_BYTES) {
            return new WP_Error(
                'ashko_product_sync_memory_limit',
                __('The Ashko sync receiver needs at least 192 MiB of PHP memory for a complete Patris envelope.', 'ashko-wp'),
                array('status' => 503, 'memory_limit' => $raw, 'retryable' => false)
            );
        }
        return true;
    }

    private static function bytes(string $value): int {
        $value = trim($value);
        if ('-1' === $value || '' === $value) {
            return -1;
        }
        if (function_exists('wp_convert_hr_to_bytes')) {
            return (int) wp_convert_hr_to_bytes($value);
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        if ('g' === $unit) {
            return $number * 1073741824;
        }
        if ('m' === $unit) {
            return $number * 1048576;
        }
        if ('k' === $unit) {
            return $number * 1024;
        }
        return $number;
    }
}
