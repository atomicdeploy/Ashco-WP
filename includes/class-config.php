<?php
namespace Ashko\Patris;

final class Config {
    public const OPTION = 'ashko_patris_settings';
    public const SECRET_OPTION = 'ashko_product_sync_v1_secret';
    public const SOURCE_SCOPES_OPTION = 'ashko_product_sync_v1_source_scopes';
    public const OWN_SERIAL_META = '_ashko_patris_serial';

    public static function defaults(): array {
        return array(
            'serial_meta_key' => '_sku',
            'fx_irr_per_cny' => '300000',
            'freight_irr_per_kg' => '22000000',
            'profit_margin_percent' => '30',
            'stock_percent' => '30',
            'default_freight_method' => 'air_express',
            'show_exact_stock' => 'yes',
            'keep_out_of_stock_visible' => 'yes',
            'external_services_enabled' => 'no',
            'external_webhook_url' => '',
        );
    }

    public static function all(): array {
        $stored = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($stored) ? $stored : array());
    }

    public static function get(string $key, $fallback = null) {
        $values = self::all();
        return array_key_exists($key, $values) ? $values[$key] : $fallback;
    }

    public static function sanitize(array $input): array {
        $defaults = self::defaults();
        $meta_key = isset($input['serial_meta_key']) ? sanitize_key((string) $input['serial_meta_key']) : '_sku';
        if ('' === $meta_key || in_array($meta_key, array('_ashko_patris_product_code', '_digitalogic_patris_product_code'), true)) {
            $meta_key = '_sku';
        }

        return array(
            'serial_meta_key' => $meta_key,
            'fx_irr_per_cny' => self::decimal($input['fx_irr_per_cny'] ?? $defaults['fx_irr_per_cny']),
            'freight_irr_per_kg' => self::decimal($input['freight_irr_per_kg'] ?? $defaults['freight_irr_per_kg']),
            'profit_margin_percent' => self::decimal($input['profit_margin_percent'] ?? $defaults['profit_margin_percent']),
            'stock_percent' => self::decimal($input['stock_percent'] ?? $defaults['stock_percent']),
            'default_freight_method' => sanitize_key((string) ($input['default_freight_method'] ?? 'air_express')),
            'show_exact_stock' => self::yes_no($input['show_exact_stock'] ?? 'no'),
            'keep_out_of_stock_visible' => self::yes_no($input['keep_out_of_stock_visible'] ?? 'no'),
            'external_services_enabled' => self::yes_no($input['external_services_enabled'] ?? 'no'),
            'external_webhook_url' => esc_url_raw((string) ($input['external_webhook_url'] ?? '')),
        );
    }

    public static function secret(): string {
        $secret = (string) get_option(self::SECRET_OPTION, '');
        if (strlen($secret) < 32) {
            $secret = wp_generate_password(64, false, false);
            update_option(self::SECRET_OPTION, $secret, false);
        }
        return $secret;
    }

    public static function source_allowed(string $id, string $dataset): bool {
        $scopes = get_option(self::SOURCE_SCOPES_OPTION, array());
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($scopes) || array() === $scopes) {
            return true;
        }
        foreach ($scopes as $scope) {
            if (
                is_array($scope)
                && isset($scope['id'], $scope['dataset'])
                && hash_equals((string) $scope['id'], $id)
                && hash_equals((string) $scope['dataset'], $dataset)
            ) {
                return true;
            }
        }
        return false;
    }

    private static function decimal($value): string {
        $value = str_replace(array(',', '٬', '،', ' '), '', (string) $value);
        return preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value) ? $value : '';
    }

    private static function yes_no($value): string {
        return in_array($value, array('yes', '1', 1, true, 'on'), true) ? 'yes' : 'no';
    }
}
