<?php
namespace Ashko\Patris;

final class Integration_Status {
    public static function acf_available(): bool {
        return defined('ACF_VERSION') || function_exists('acf_add_local_field_group');
    }

    public static function currency_switchers(): array {
        if (!function_exists('get_plugins') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            return array();
        }
        $result = array();
        foreach (get_plugins() as $file => $data) {
            $name = (string) ($data['Name'] ?? '');
            $haystack = $file . ' ' . $name . ' ' . (string) ($data['Description'] ?? '');
            if (!preg_match('/(?:CURCY|FOX|currency[ -]?switcher|multi[ -]?currency)/i', $haystack)) {
                continue;
            }
            $result[] = array(
                'file' => $file,
                'name' => $name,
                'version' => (string) ($data['Version'] ?? ''),
                'active' => function_exists('is_plugin_active') ? is_plugin_active($file) : false,
            );
        }
        return $result;
    }
}
