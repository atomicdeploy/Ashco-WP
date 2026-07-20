<?php
namespace Ashko\Patris;

use WP_Error;

final class Report_Repository {
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $runs = self::runs_table();
        $rows = self::rows_table();
        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            mode varchar(16) NOT NULL,
            event_id varchar(80) NOT NULL,
            schema_version varchar(16) NOT NULL,
            source_id varchar(191) NOT NULL,
            dataset varchar(191) NOT NULL,
            status varchar(32) NOT NULL,
            received_products bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_products bigint(20) unsigned NOT NULL DEFAULT 0,
            matched_products bigint(20) unsigned NOT NULL DEFAULT 0,
            changed_products bigint(20) unsigned NOT NULL DEFAULT 0,
            unchanged_products bigint(20) unsigned NOT NULL DEFAULT 0,
            unmatched_products bigint(20) unsigned NOT NULL DEFAULT 0,
            ambiguous_products bigint(20) unsigned NOT NULL DEFAULT 0,
            warning_products bigint(20) unsigned NOT NULL DEFAULT 0,
            core_field_counts longtext NOT NULL,
            meta_field_counts longtext NOT NULL,
            warning_counts longtext NOT NULL,
            result_json longtext NOT NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$rows} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            product_code varchar(191) NOT NULL,
            serial varchar(191) NOT NULL,
            woo_id bigint(20) unsigned NULL,
            resolution varchar(40) NOT NULL,
            changed tinyint(1) NOT NULL DEFAULT 0,
            core_changes longtext NOT NULL,
            meta_changes longtext NOT NULL,
            warnings longtext NOT NULL,
            canonical_final_irt varchar(80) NOT NULL,
            canonical_final_irr varchar(80) NOT NULL,
            native_final_irt varchar(80) NOT NULL,
            final_irr varchar(80) NOT NULL,
            formula_discrepancy_irt varchar(80) NOT NULL,
            formula_discrepancy_irr varchar(80) NOT NULL,
            currency_effective_date varchar(16) NOT NULL,
            currency_effective_date_jalali varchar(16) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_product_code (run_id, product_code),
            KEY run_id (run_id),
            KEY product_code (product_code),
            KEY serial (serial),
            KEY resolution (resolution)
        ) {$charset};");
    }

    public function start(array $context) {
        global $wpdb;
        $ok = $wpdb->insert(
            self::runs_table(),
            array(
                'mode' => (string) $context['mode'],
                'event_id' => (string) $context['event_id'],
                'schema_version' => (string) $context['schema_version'],
                'source_id' => (string) $context['source_id'],
                'dataset' => (string) $context['dataset'],
                'status' => 'planning',
                'received_products' => (int) $context['received_products'],
                'processed_products' => 0,
                'core_field_counts' => '{}',
                'meta_field_counts' => '{}',
                'warning_counts' => '{}',
                'result_json' => '{}',
                'created_at' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        if (false === $ok) {
            return new WP_Error('ashko_report_unavailable', __('The durable sync report could not be started.', 'ashko-wp'), array('status' => 503));
        }
        return (int) $wpdb->insert_id;
    }

    public function add_row(int $run_id, array $row) {
        global $wpdb;
        $ok = $wpdb->replace(
            self::rows_table(),
            array(
                'run_id' => $run_id,
                'product_code' => (string) ($row['product_code'] ?? ''),
                'serial' => (string) ($row['serial'] ?? ''),
                'woo_id' => empty($row['woo_id']) ? null : (int) $row['woo_id'],
                'resolution' => (string) ($row['resolution'] ?? ''),
                'changed' => !empty($row['changed']) ? 1 : 0,
                'core_changes' => wp_json_encode($row['core_changes'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'meta_changes' => wp_json_encode($row['meta_changes'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'warnings' => wp_json_encode($row['warnings'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'canonical_final_irt' => (string) ($row['canonical_final_irt'] ?? ''),
                'canonical_final_irr' => (string) ($row['canonical_final_irr'] ?? ''),
                'native_final_irt' => (string) ($row['native_final_irt'] ?? ''),
                'final_irr' => (string) ($row['final_irr'] ?? ''),
                'formula_discrepancy_irt' => (string) ($row['formula_discrepancy_irt'] ?? ''),
                'formula_discrepancy_irr' => (string) ($row['formula_discrepancy_irr'] ?? ''),
                'currency_effective_date' => (string) ($row['currency_effective_date'] ?? ''),
                'currency_effective_date_jalali' => (string) ($row['currency_effective_date_jalali'] ?? ''),
                'created_at' => current_time('mysql', true),
            ),
            array('%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        return false === $ok
            ? new WP_Error('ashko_report_row_failed', __('A per-product report row could not be saved.', 'ashko-wp'), array('status' => 503))
            : true;
    }

    public function finish(int $run_id, string $status, array $summary, array $result = array(), bool $completed = true): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::runs_table(),
            array(
                'status' => $status,
                'processed_products' => (int) ($summary['processed_products'] ?? $summary['received_products'] ?? 0),
                'matched_products' => (int) ($summary['matched_products'] ?? 0),
                'changed_products' => (int) ($summary['changed_products'] ?? 0),
                'unchanged_products' => (int) ($summary['unchanged_products'] ?? 0),
                'unmatched_products' => (int) ($summary['unmatched_products'] ?? 0),
                'ambiguous_products' => (int) ($summary['ambiguous_products'] ?? 0),
                'warning_products' => (int) ($summary['warning_products'] ?? 0),
                'core_field_counts' => wp_json_encode($summary['core_field_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'meta_field_counts' => wp_json_encode($summary['meta_field_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'warning_counts' => wp_json_encode($summary['warning_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result_json' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at' => $completed ? current_time('mysql', true) : null,
            ),
            array('id' => $run_id)
        );
    }

    public function checkpoint(int $run_id, int $processed, array $summary, string $status = 'planning'): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::runs_table(),
            array(
                'status' => $status,
                'processed_products' => $processed,
                'matched_products' => (int) ($summary['matched_products'] ?? 0),
                'changed_products' => (int) ($summary['changed_products'] ?? 0),
                'unchanged_products' => (int) ($summary['unchanged_products'] ?? 0),
                'unmatched_products' => (int) ($summary['unmatched_products'] ?? 0),
                'ambiguous_products' => (int) ($summary['ambiguous_products'] ?? 0),
                'warning_products' => (int) ($summary['warning_products'] ?? 0),
                'core_field_counts' => wp_json_encode($summary['core_field_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'meta_field_counts' => wp_json_encode($summary['meta_field_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'warning_counts' => wp_json_encode($summary['warning_counts'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ),
            array('id' => $run_id)
        );
    }

    public function event_run(string $mode, string $event_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::runs_table() . ' WHERE mode = %s AND event_id = %s ORDER BY id DESC LIMIT 1',
            $mode,
            $event_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function record_receiver_result(int $run_id, array $receiver, array $summary): void {
        $run = $this->get_run($run_id);
        $history = $run ? (json_decode((string) $run['result_json'], true) ?: array()) : array();
        $history['receiver_calls'] = (int) ($history['receiver_calls'] ?? 0) + 1;
        $history['woocommerce_totals'] = is_array($history['woocommerce_totals'] ?? null) ? $history['woocommerce_totals'] : array();
        foreach (array('attempted', 'updated', 'already_applied', 'missing', 'ambiguous', 'failed', 'write_attempts') as $field) {
            $history['woocommerce_totals'][$field] = (int) ($history['woocommerce_totals'][$field] ?? 0)
                + (int) ($receiver['woocommerce'][$field] ?? 0);
        }
        $history['latest'] = $receiver;
        $completed = empty($receiver['retryable']) && 0 === (int) ($receiver['pending_products'] ?? 0);
        $this->finish($run_id, (string) ($receiver['status'] ?? 'apply_unknown'), $summary, $history, $completed);
    }

    public function get_run(int $run_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::runs_table() . ' WHERE id = %d', $run_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function latest_runs(int $limit = 20): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::runs_table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public function rows(int $run_id, int $limit = 100, int $offset = 0): array {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::rows_table() . ' WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
            $run_id,
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public function all_rows(int $run_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::rows_table() . ' WHERE run_id = %d ORDER BY id ASC',
            $run_id
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function download_url(int $run_id): string {
        return wp_nonce_url(
            admin_url('admin-post.php?action=ashko_download_patris_report&run_id=' . $run_id),
            'ashko_download_patris_report_' . $run_id
        );
    }

    private static function runs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ashko_patris_sync_runs';
    }

    private static function rows_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ashko_patris_sync_report_rows';
    }
}
