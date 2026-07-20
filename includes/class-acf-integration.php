<?php
namespace Ashko\Patris;

final class ACF_Integration {
    private static bool $syncing = false;

    public static function register(): void {
        add_action('acf/init', array(self::class, 'register_fields'));
        add_filter('acf/update_value/name=ashko_cny_price', array(self::class, 'update_cny'), 10, 3);
        add_filter('acf/update_value/name=ashko_currency_effective_date', array(self::class, 'update_date'), 10, 3);
        add_action('updated_post_meta', array(self::class, 'meta_updated'), 10, 4);
        add_action('added_post_meta', array(self::class, 'meta_updated'), 10, 4);
    }

    public static function register_fields(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        acf_add_local_field_group(array(
            'key' => 'group_ashko_patris_pricing',
            'title' => __('قیمت‌گذاری پاتریس اشکو', 'ashko-wp'),
            'fields' => array(
                array(
                    'key' => 'field_ashko_cny_price',
                    'label' => __('قیمت خرید (یوان چین)', 'ashko-wp'),
                    'name' => 'ashko_cny_price',
                    'type' => 'number',
                    'step' => '0.000000000001',
                    'min' => 0,
                ),
                array(
                    'key' => 'field_ashko_currency_effective_date',
                    'label' => __('تاریخ مؤثر نرخ ارز', 'ashko-wp'),
                    'name' => 'ashko_currency_effective_date',
                    'type' => 'date_picker',
                    'display_format' => 'Y-m-d',
                    'return_format' => 'Y-m-d',
                    'instructions' => __('مقدار مرجع به‌صورت میلادی ISO ذخیره می‌شود؛ پنل اشکو معادل جلالی را نیز نمایش می‌دهد.', 'ashko-wp'),
                ),
            ),
            'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'product'))),
            'position' => 'side',
            'active' => true,
        ));
    }

    public static function update_cny($value, $post_id, $field) {
        if (self::$syncing || 'product' !== get_post_type($post_id)) {
            return $value;
        }
        $value = preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', (string) $value) ? (string) $value : '';
        self::$syncing = true;
        update_post_meta((int) $post_id, '_ashko_patris_cny', $value);
        self::$syncing = false;
        return $value;
    }

    public static function update_date($value, $post_id, $field) {
        if (self::$syncing || 'product' !== get_post_type($post_id)) {
            return $value;
        }
        $value = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';
        self::$syncing = true;
        update_post_meta((int) $post_id, '_ashko_patris_currency_effective_date', $value);
        self::$syncing = false;
        return $value;
    }

    public static function meta_updated($meta_id, $post_id, $meta_key, $meta_value): void {
        if (self::$syncing || !function_exists('update_field') || 'product' !== get_post_type($post_id)) {
            return;
        }
        $fields = array(
            '_ashko_patris_cny' => 'ashko_cny_price',
            '_ashko_patris_currency_effective_date' => 'ashko_currency_effective_date',
        );
        if (!isset($fields[$meta_key])) {
            return;
        }
        self::$syncing = true;
        update_field($fields[$meta_key], (string) $meta_value, (int) $post_id);
        self::$syncing = false;
    }
}
