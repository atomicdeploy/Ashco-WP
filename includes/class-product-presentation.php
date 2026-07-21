<?php
namespace Ashko\Patris;

final class Product_Presentation {
    private const RECORD_HASH_META = '_ashko_patris_record_hash';

    /**
     * Immutable tuple emitted by the reviewed one-time catalog importer.
     * Despite the historical meta-key name, the stored source value is a
     * legacy provenance token and is not the raw kala.db digest.
     */
    private const IMPORT_MARKER_META = '_ashko_patris_import_marker';
    private const IMPORT_LEGACY_SOURCE_TOKEN_META = '_ashko_patris_import_source_sha256';
    private const TRUSTED_IMPORT_MARKER = 'positive-stock-20260720';
    private const TRUSTED_IMPORT_LEGACY_SOURCE_TOKEN = '3da2f89f3c814c3d6b8efc4511984739c87b5c12f9ef3c6ea1e11575925fa321';
    private const CODE_META = '_ashko_patris_product_code';
    private const UNIT_META = '_ashko_patris_unit';
    private const CLEANUP_OPTION = 'ashko_patris_legacy_excerpt_cleanup';

    public static function register(): void {
        add_filter('woocommerce_product_get_short_description', array(self::class, 'filter_product_short_description'), 20, 2);
        add_filter('woocommerce_short_description', array(self::class, 'filter_rendered_short_description'), 1, 1);
        add_filter('get_the_excerpt', array(self::class, 'filter_post_excerpt'), 20, 2);
        add_filter('woocommerce_display_product_attributes', array(self::class, 'product_attributes'), 20, 2);
        add_filter('woocommerce_product_tabs', array(self::class, 'product_tabs'), 99, 1);
        add_filter('woocommerce_structured_data_product', array(self::class, 'structured_data'), 20, 2);
    }

    public static function filter_product_short_description($description, $product): string {
        $description = (string) $description;
        return self::is_legacy_generated_excerpt($description, $product) ? '' : $description;
    }

    public static function filter_rendered_short_description($description): string {
        global $product;
        $description = (string) $description;
        return self::is_legacy_generated_excerpt($description, $product) ? '' : $description;
    }

    public static function filter_post_excerpt($description, $post): string {
        if (!is_object($post) || 'product' !== (string) ($post->post_type ?? '') || !function_exists('wc_get_product')) {
            return (string) $description;
        }
        $product = wc_get_product((int) ($post->ID ?? 0));
        return self::is_legacy_generated_excerpt((string) $description, $product) ? '' : (string) $description;
    }

    /**
     * Add source identifiers and the sale unit to WooCommerce's semantic
     * Additional Information table instead of treating them as prose.
     */
    public static function product_attributes(array $attributes, $product): array {
        if (!self::is_owned_product($product)) {
            return $attributes;
        }

        $code = self::meta($product, self::CODE_META);
        $serial = self::meta($product, Config::OWN_SERIAL_META);
        $unit = self::meta($product, self::UNIT_META);

        if ('' !== $code) {
            $attributes['ashco_patris_product_code'] = array(
                'label' => __('کد پاتریس', 'ashko-wp'),
                'value' => self::identifier_html($code),
            );
        }
        if ('' !== $serial) {
            $attributes['ashco_patris_serial'] = array(
                'label' => __('سریال پاتریس', 'ashko-wp'),
                'value' => self::identifier_html($serial),
            );
        }
        if ('' !== $unit) {
            $attributes['ashco_patris_sale_unit'] = array(
                'label' => __('واحد فروش', 'ashko-wp'),
                'value' => esc_html($unit),
            );
        }

        return $attributes;
    }

    /** Ensure the standard details tab exists when Patris is its only source. */
    public static function product_tabs(array $tabs): array {
        global $product;
        if (
            isset($tabs['additional_information'])
            || !self::is_owned_product($product)
            || !self::has_public_details($product)
        ) {
            return $tabs;
        }

        $tabs['additional_information'] = array(
            'title' => __('توضیحات تکمیلی', 'ashko-wp'),
            'priority' => 20,
            'callback' => 'woocommerce_product_additional_information_tab',
        );
        return $tabs;
    }

    /**
     * Keep custom identifiers as Product additionalProperty values. Neither
     * Patris Code nor the sale unit is a manufacturer part number or GTIN.
     */
    public static function structured_data(array $markup, $product): array {
        if (!self::is_owned_product($product)) {
            return $markup;
        }

        $properties = isset($markup['additionalProperty']) && is_array($markup['additionalProperty'])
            ? $markup['additionalProperty']
            : array();
        if (isset($properties['@type'])) {
            $properties = array($properties);
        }
        $existing = array();
        foreach ($properties as $property) {
            if (is_array($property) && isset($property['name'], $property['value'])) {
                $existing[(string) $property['name'] . "\0" . (string) $property['value']] = true;
            }
        }
        foreach (array(
            __('کد پاتریس', 'ashko-wp') => self::meta($product, self::CODE_META),
            __('سریال پاتریس', 'ashko-wp') => self::meta($product, Config::OWN_SERIAL_META),
            __('واحد فروش', 'ashko-wp') => self::meta($product, self::UNIT_META),
        ) as $name => $value) {
            if ('' === $value) {
                continue;
            }
            $identity = $name . "\0" . $value;
            if (isset($existing[$identity])) {
                continue;
            }
            $properties[] = array(
                '@type' => 'PropertyValue',
                'name' => $name,
                'value' => $value,
            );
            $existing[$identity] = true;
        }
        if (array() !== $properties) {
            $markup['additionalProperty'] = $properties;
        }
        return $markup;
    }

    /**
     * Recognize only the exact sentence emitted by the one-time catalog import.
     * Any extra text, changed identity, or merchant-authored prose is preserved.
     */
    public static function is_legacy_generated_excerpt(string $excerpt, $product): bool {
        if (!self::is_owned_product($product)) {
            return false;
        }

        $text = self::plain_text($excerpt);
        $name = self::plain_text((string) $product->get_name('edit'));
        $code = self::meta($product, self::CODE_META);
        $serial = self::meta($product, Config::OWN_SERIAL_META);
        $unit = self::meta($product, self::UNIT_META);
        if ('' === $text || '' === $name || '' === $code || '' === $serial) {
            return false;
        }

        $prefix = '«' . $name . '» از گروه «';
        $suffix = '» با سریال ' . $serial . ' و کد پاتریس ' . $code . ' است.';
        if ('' !== $unit) {
            $suffix .= ' واحد فروش: ' . $unit . '.';
        }
        $pattern = '/^' . preg_quote($prefix, '/') . '(?<group>[^«»]+)' . preg_quote($suffix, '/') . '$/u';
        $matches = array();
        return 1 === preg_match($pattern, $text, $matches)
            && self::is_assigned_category((string) ($matches['group'] ?? ''), $product);
    }

    /**
     * Scan or clear only proven legacy excerpts. This is deliberately invoked
     * through the explicit WP-CLI maintenance command, never on activation.
     */
    public static function cleanup_legacy_excerpts(bool $apply = false): array {
        if (!function_exists('wc_get_product')) {
            return array(
                'mode' => $apply ? 'apply' : 'dry-run',
                'scanned' => 0,
                'matched' => 0,
                'cleared' => 0,
                'matched_product_ids' => array(),
                'errors' => array(array(
                    'product_id' => 0,
                    'message' => 'WooCommerce product API is unavailable.',
                )),
            );
        }
        $ids = function_exists('get_posts') ? get_posts(array(
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => self::RECORD_HASH_META,
                    'value' => '^sha256:[a-f0-9]{64}$',
                    'compare' => 'REGEXP',
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key' => self::IMPORT_MARKER_META,
                        'value' => self::TRUSTED_IMPORT_MARKER,
                        'compare' => '=',
                    ),
                    array(
                        'key' => self::IMPORT_LEGACY_SOURCE_TOKEN_META,
                        'value' => self::TRUSTED_IMPORT_LEGACY_SOURCE_TOKEN,
                        'compare' => '=',
                    ),
                ),
            ),
        )) : array();

        $result = array(
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => count($ids),
            'matched' => 0,
            'cleared' => 0,
            'matched_product_ids' => array(),
            'errors' => array(),
        );
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if (!is_object($product)) {
                continue;
            }
            $excerpt = (string) $product->get_short_description('edit');
            if (!self::is_legacy_generated_excerpt($excerpt, $product)) {
                continue;
            }

            ++$result['matched'];
            $result['matched_product_ids'][] = (int) $id;
            if (!$apply) {
                continue;
            }
            try {
                $product->set_short_description('');
                $product->save();
                if ('' !== (string) $product->get_short_description('edit')) {
                    throw new \RuntimeException('Short-description readback was not empty.');
                }
                ++$result['cleared'];
            } catch (\Throwable $error) {
                $result['errors'][] = array(
                    'product_id' => (int) $id,
                    'message' => $error->getMessage(),
                );
            }
        }

        if ($apply) {
            update_option(self::CLEANUP_OPTION, array(
                'completed_at' => current_time('mysql'),
                'scanned' => $result['scanned'],
                'matched' => $result['matched'],
                'cleared' => $result['cleared'],
                'errors' => count($result['errors']),
            ), false);
        }
        return $result;
    }

    private static function has_public_details($product): bool {
        return '' !== self::meta($product, self::CODE_META)
            || '' !== self::meta($product, Config::OWN_SERIAL_META)
            || '' !== self::meta($product, self::UNIT_META);
    }

    private static function is_owned_product($product): bool {
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return false;
        }

        $record_hash = (string) $product->get_meta(self::RECORD_HASH_META, true, 'edit');
        if (1 === preg_match('/\Asha256:[a-f0-9]{64}\z/', $record_hash)) {
            return true;
        }

        $import_marker = (string) $product->get_meta(self::IMPORT_MARKER_META, true, 'edit');
        $source_token = (string) $product->get_meta(self::IMPORT_LEGACY_SOURCE_TOKEN_META, true, 'edit');
        return hash_equals(self::TRUSTED_IMPORT_MARKER, $import_marker)
            && hash_equals(self::TRUSTED_IMPORT_LEGACY_SOURCE_TOKEN, $source_token);
    }

    private static function meta($product, string $key): string {
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return '';
        }
        return self::plain_text((string) $product->get_meta($key, true, 'edit'));
    }

    private static function identifier_html(string $value): string {
        return '<bdi class="ashco-patris-identifier" dir="ltr">' . esc_html($value) . '</bdi>';
    }

    private static function is_assigned_category(string $group, $product): bool {
        if (!function_exists('wp_get_post_terms') || !method_exists($product, 'get_id')) {
            return false;
        }
        $terms = wp_get_post_terms((int) $product->get_id(), 'product_cat', array('fields' => 'names'));
        if (is_wp_error($terms) || !is_array($terms)) {
            return false;
        }
        $group = self::plain_text($group);
        foreach ($terms as $term_name) {
            if ($group === self::plain_text((string) $term_name)) {
                return true;
            }
        }
        return false;
    }

    private static function plain_text(string $value): string {
        $value = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value, true) : strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', trim($value));
        return is_string($value) ? $value : '';
    }
}
