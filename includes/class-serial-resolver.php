<?php
namespace Ashko\Patris;

use Throwable;
use WP_Error;

/** Exact case-sensitive Serial resolver. Code and product names are never queried. */
final class Serial_Resolver {
    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @return array<string,array|WP_Error> keyed by canonical product_code */
    public function resolve_catalog(array $products): array {
        $serial_counts = array();
        $serials = array();
        foreach ($products as $product) {
            $serial = is_array($product) ? (string) ($product['serial'] ?? '') : '';
            if ('' !== $serial) {
                $serial_counts[$serial] = ($serial_counts[$serial] ?? 0) + 1;
                $serials[$serial] = $serial;
            }
        }
        $matches = $this->query_serials(array_values($serials));
        if (is_wp_error($matches)) {
            $matches = $matches;
        }

        $resolved = array();
        foreach ($products as $product) {
            if (!is_array($product) || !isset($product['product_code'])) {
                continue;
            }
            $code = (string) $product['product_code'];
            $serial = (string) ($product['serial'] ?? '');
            if ('' === $serial) {
                $resolved[$code] = $this->not_found('missing_serial');
                continue;
            }
            if (($serial_counts[$serial] ?? 0) > 1) {
                $resolved[$code] = $this->ambiguous(array(), 'duplicate_source_serial');
                continue;
            }
            if (is_wp_error($matches)) {
                $resolved[$code] = $matches;
                continue;
            }
            $rows = $matches[$serial] ?? array();
            if (array() === $rows) {
                $resolved[$code] = $this->not_found('unmatched_woocommerce');
                continue;
            }
            $ids = array_values(array_unique(array_map(static fn($row) => (string) $row['ID'], $rows)));
            sort($ids, SORT_STRING);
            if (count($ids) !== 1) {
                $resolved[$code] = $this->ambiguous($ids, 'duplicate_woocommerce_serial');
                continue;
            }
            $row = reset($rows);
            $resolved[$code] = array(
                'woocommerce_id' => (string) $row['ID'],
                'post_type' => (string) $row['post_type'],
                'identifier' => $serial,
                'resolved_by' => (string) $row['meta_key'],
            );
        }
        return $resolved;
    }

    public function resolve(array $identifiers) {
        if (!array_key_exists('serial', $identifiers) || !is_string($identifiers['serial'])) {
            return new WP_Error('ashko_invalid_product_identifier', __('Serial must be supplied as a string.', 'ashko-wp'), array('status' => 400));
        }
        $serial = $identifiers['serial'];
        $result = $this->resolve_catalog(array(array('product_code' => '__single__', 'serial' => $serial)));
        return $result['__single__'];
    }

    private function query_serials(array $serials) {
        if (array() === $serials) {
            return array();
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return $this->query_failed();
        }
        $posts = $wpdb->posts ?? $wpdb->prefix . 'posts';
        $postmeta = $wpdb->postmeta ?? $wpdb->prefix . 'postmeta';
        $keys = array_values(array_unique(array((string) Config::get('serial_meta_key', '_sku'), Config::OWN_SERIAL_META)));
        $result = array();
        try {
            foreach (array_chunk(array_values($serials), 400) as $chunk) {
                $key_placeholders = implode(',', array_fill(0, count($keys), '%s'));
                $serial_placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $sql = "/* ashko_exact_serial_catalog */
                    SELECT pm.post_id AS ID, p.post_type, pm.meta_key, pm.meta_value
                    FROM {$postmeta} pm
                    INNER JOIN {$posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key IN ({$key_placeholders})
                      AND BINARY pm.meta_value IN ({$serial_placeholders})
                      AND p.post_type IN ('product', 'product_variation')
                      AND p.post_status NOT IN ('trash', 'auto-draft')
                    ORDER BY pm.post_id ASC, pm.meta_id DESC";
                $prepared = $wpdb->prepare($sql, ...array_merge($keys, $chunk));
                $rows = $wpdb->get_results($prepared, ARRAY_A);
                if (!is_array($rows) || '' !== trim((string) ($wpdb->last_error ?? ''))) {
                    return $this->query_failed();
                }
                $allowed = array_fill_keys($chunk, true);
                foreach ($rows as $row) {
                    $serial = (string) ($row['meta_value'] ?? '');
                    if (!isset($allowed[$serial])) {
                        continue;
                    }
                    $result[$serial][] = $row;
                }
            }
        } catch (Throwable $exception) {
            return $this->query_failed();
        }
        return $result;
    }

    private function ambiguous(array $ids, string $reason): WP_Error {
        return new WP_Error(
            'ashko_product_identifier_ambiguous',
            __('More than one product shares that exact Serial.', 'ashko-wp'),
            array('status' => 409, 'reason' => $reason, 'woocommerce_ids' => $ids)
        );
    }

    private function not_found(string $reason): WP_Error {
        return new WP_Error(
            'ashko_product_identifier_not_found',
            __('No WooCommerce product has that exact Serial.', 'ashko-wp'),
            array('status' => 404, 'reason' => $reason)
        );
    }

    private function query_failed(): WP_Error {
        return new WP_Error(
            'ashko_product_identifier_query_failed',
            __('The exact Serial lookup could not be completed.', 'ashko-wp'),
            array('status' => 503, 'retryable' => true)
        );
    }
}
