<?php
namespace Ashko\Patris;

use WP_Error;

final class Sync_Service {
    private const REPORT_BATCH_SIZE = 200;
    private const REPORT_TIME_BUDGET_SECONDS = 12.0;

    private Report_Repository $reports;

    public function __construct(?Report_Repository $reports = null) {
        $this->reports = $reports ?? new Report_Repository();
    }

    public function dry_run(string $json) {
        return $this->execute($json, 'dry-run');
    }

    public function apply(string $json) {
        return $this->execute($json, 'apply');
    }

    private function execute(string $json, string $mode) {
        $memory = Memory_Guard::ensure();
        if (is_wp_error($memory)) {
            return $memory;
        }
        $preview = Product_Sync_Receiver::instance()->preview_json($json);
        if (is_wp_error($preview)) {
            return $preview;
        }
        $envelope = $preview['envelope'];
        if (!Config::source_allowed((string) $envelope['source']['id'], (string) $envelope['source']['dataset'])) {
            return new WP_Error('ashko_product_sync_source_forbidden', __('This exact Patris source is not allowed.', 'ashko-wp'), array('status' => 403));
        }

        $products = array_values($preview['transition']['changed_products']);
        $run = $this->reports->event_run($mode, (string) $envelope['event_id']);
        if (!$run) {
            $run_id = $this->reports->start(array(
                'mode' => $mode,
                'event_id' => $envelope['event_id'],
                'source_id' => $envelope['source']['id'],
                'dataset' => $envelope['source']['dataset'],
                'received_products' => count($products),
            ));
            if (is_wp_error($run_id)) {
                return $run_id;
            }
            $run = $this->reports->get_run($run_id);
            if (!$run) {
                return new WP_Error(
                    'ashko_report_readback_failed',
                    __('The durable sync report did not pass creation readback.', 'ashko-wp'),
                    array('status' => 503, 'retryable' => true)
                );
            }
        }
        $run_id = (int) $run['id'];
        $report_product_count = (int) ($run['received_products'] ?? count($products));
        $processed = min($report_product_count, (int) ($run['processed_products'] ?? 0));
        $summary = $this->summary_from_run($run, $report_product_count);

        if ($processed < $report_product_count) {
            if (count($products) !== $report_product_count) {
                return new WP_Error(
                    'ashko_report_resume_projection_mismatch',
                    __('The durable report cannot resume because this event projection differs from its first request.', 'ashko-wp'),
                    array('status' => 409, 'retryable' => false)
                );
            }
            $resolutions = Serial_Resolver::instance()->resolve_catalog(array_values($preview['transition']['products']));
            $started_at = microtime(true);
            $batch_count = 0;
            while (
                $processed < $report_product_count
                && $batch_count < self::REPORT_BATCH_SIZE
                && (microtime(true) - $started_at) < self::REPORT_TIME_BUDGET_SECONDS
            ) {
                $data = $products[$processed];
                $code = (string) $data['product_code'];
                $resolved = $resolutions[$code] ?? new WP_Error(
                    'ashko_product_identifier_not_found',
                    __('No WooCommerce product has that exact Serial.', 'ashko-wp'),
                    array('status' => 404, 'reason' => 'unmatched_woocommerce')
                );
                $row = $this->plan_row($data, $resolved, $summary);
                $stored = $this->reports->add_row($run_id, $row);
                if (is_wp_error($stored)) {
                    $summary['processed_products'] = $processed;
                    $this->reports->finish($run_id, 'report_failed', $summary, array('error' => $stored->get_error_code()));
                    return $stored;
                }
                $processed++;
                $batch_count++;
            }
            $summary['processed_products'] = $processed;
            $report_ready = $processed >= $report_product_count;
            $checkpointed = $this->reports->checkpoint($run_id, $processed, $summary, $report_ready ? 'report_ready' : 'planning');
            if (!$checkpointed) {
                return new WP_Error(
                    'ashko_report_checkpoint_failed',
                    __('The durable sync report checkpoint could not be saved.', 'ashko-wp'),
                    array('status' => 503, 'retryable' => true)
                );
            }
            if (!$report_ready || 'apply' === $mode) {
                return $this->response(
                    $mode,
                    $report_ready ? 'report_ready' : 'report_pending',
                    $envelope,
                    $preview,
                    $run_id,
                    $summary,
                    array(),
                    'apply' === $mode || !$report_ready,
                    $report_product_count - $processed
                );
            }
        }

        $summary['processed_products'] = $report_product_count;
        if ('dry-run' === $mode) {
            $this->reports->finish($run_id, 'dry_run_complete', $summary, array());
            return $this->response('dry-run', 'dry_run_complete', $envelope, $preview, $run_id, $summary);
        }

        $result = Product_Sync_Receiver::instance()->receive_json($json);
        if (is_wp_error($result)) {
            $this->reports->finish($run_id, 'apply_failed', $summary, array('error' => $result->get_error_code()));
            return $result;
        }
        $this->reports->record_receiver_result($run_id, $result, $summary);
        return $this->response(
            'apply',
            (string) ($result['status'] ?? 'applied'),
            $envelope,
            $preview,
            $run_id,
            $summary,
            $result,
            !empty($result['retryable']),
            (int) ($result['pending_products'] ?? 0)
        );
    }

    private function plan_row(array $data, $resolved, array &$summary): array {
        $analysis = Product_Applicator::instance()->analyze_source($data);
        $warnings = $analysis['warnings'];
        $calculation = $analysis['calculation'];
        $row = array(
            'product_code' => (string) $data['product_code'],
            'serial' => (string) ($data['serial'] ?? ''),
            'woo_id' => null,
            'resolution' => '',
            'changed' => false,
            'core_changes' => array(),
            'meta_changes' => array(),
            'warnings' => array(),
            'canonical_final_irt' => null === ($data['final_price'] ?? null) ? '' : (string) $data['final_price'],
            'canonical_final_irr' => null === ($data['final_price'] ?? null) ? '' : (string) ((int) $data['final_price'] * 10),
            'native_final_irt' => null === $calculation ? '' : $calculation['native_final_irt'],
            'final_irr' => null === $calculation ? '' : $calculation['woo_final_irr'],
            'formula_discrepancy_irt' => '',
            'formula_discrepancy_irr' => '',
            'currency_effective_date' => (string) ($data['currency_effective_date'] ?? ''),
            'currency_effective_date_jalali' => Jalali::from_iso((string) ($data['currency_effective_date'] ?? '')),
        );
        if (null !== $calculation && null !== ($data['final_price'] ?? null)) {
            $row['formula_discrepancy_irt'] = (string) Decimal_Calculator::difference(
                $calculation['native_final_irt'],
                (string) $data['final_price']
            );
            $row['formula_discrepancy_irr'] = (string) Decimal_Calculator::difference(
                $calculation['woo_final_irr'],
                (string) ((int) $data['final_price'] * 10)
            );
        }

        if (is_wp_error($resolved)) {
            $reason = (string) (($resolved->get_error_data()['reason'] ?? ''));
            if ('missing_serial' === $reason) {
                $row['resolution'] = 'missing_serial';
                $warnings[] = 'missing_serial';
                $summary['unmatched_products']++;
            } elseif ('duplicate_source_serial' === $reason) {
                $row['resolution'] = 'duplicate_serial';
                $warnings[] = 'duplicate_serial';
                $summary['ambiguous_products']++;
            } elseif ('duplicate_woocommerce_serial' === $reason) {
                $row['resolution'] = 'ambiguous_woo';
                $warnings[] = 'ambiguous_woo';
                $summary['ambiguous_products']++;
            } else {
                $row['resolution'] = 'unmatched_woo';
                $warnings[] = 'unmatched_woo';
                $summary['unmatched_products']++;
            }
        } else {
            $woo_id = (int) $resolved['woocommerce_id'];
            $product = wc_get_product($woo_id);
            if (!$product) {
                $row['resolution'] = 'unmatched_woo';
                $warnings[] = 'unmatched_woo';
                $summary['unmatched_products']++;
            } else {
                $plan = Product_Applicator::instance()->plan($product, $data);
                $row['woo_id'] = $woo_id;
                $row['changed'] = (bool) $plan['changed'];
                $row['core_changes'] = $plan['core_changes'];
                $row['meta_changes'] = $plan['meta_changes'];
                $warnings = array_merge($warnings, $plan['warnings']);
                $row['resolution'] = $plan['changed'] ? 'matched_changed' : 'matched_unchanged';
                $summary['matched_products']++;
                $summary[$plan['changed'] ? 'changed_products' : 'unchanged_products']++;
                foreach (array_keys($plan['core_changes']) as $field) {
                    $summary['core_field_counts'][$field] = ($summary['core_field_counts'][$field] ?? 0) + 1;
                }
                foreach (array_keys($plan['meta_changes']) as $field) {
                    $summary['meta_field_counts'][$field] = ($summary['meta_field_counts'][$field] ?? 0) + 1;
                }
            }
        }

        $warnings = array_values(array_unique($warnings));
        sort($warnings, SORT_STRING);
        $row['warnings'] = $warnings;
        if (array() !== $warnings) {
            $summary['warning_products']++;
        }
        foreach ($warnings as $warning) {
            $summary['warning_counts'][$warning] = ($summary['warning_counts'][$warning] ?? 0) + 1;
        }
        ksort($summary['core_field_counts'], SORT_STRING);
        ksort($summary['meta_field_counts'], SORT_STRING);
        ksort($summary['warning_counts'], SORT_STRING);
        return $row;
    }

    private function empty_summary(int $received): array {
        return array(
            'received_products' => $received,
            'processed_products' => 0,
            'matched_products' => 0,
            'changed_products' => 0,
            'unchanged_products' => 0,
            'unmatched_products' => 0,
            'ambiguous_products' => 0,
            'warning_products' => 0,
            'core_field_counts' => array(),
            'meta_field_counts' => array(),
            'warning_counts' => array(),
        );
    }

    private function summary_from_run(array $run, int $received): array {
        $summary = $this->empty_summary($received);
        foreach (array('matched_products', 'changed_products', 'unchanged_products', 'unmatched_products', 'ambiguous_products', 'warning_products') as $field) {
            $summary[$field] = (int) ($run[$field] ?? 0);
        }
        $summary['processed_products'] = (int) ($run['processed_products'] ?? 0);
        $summary['core_field_counts'] = json_decode((string) ($run['core_field_counts'] ?? '{}'), true) ?: array();
        $summary['meta_field_counts'] = json_decode((string) ($run['meta_field_counts'] ?? '{}'), true) ?: array();
        $summary['warning_counts'] = json_decode((string) ($run['warning_counts'] ?? '{}'), true) ?: array();
        return $summary;
    }

    private function response(
        string $mode,
        string $status,
        array $envelope,
        array $preview,
        int $run_id,
        array $summary,
        array $receiver = array(),
        bool $retryable = false,
        int $pending = 0
    ): array {
        return array(
            'success' => true,
            'mode' => $mode,
            'status' => $status,
            'retryable' => $retryable,
            'pending_products' => $pending,
            'event_id' => $envelope['event_id'],
            'replay' => (bool) $preview['replay'],
            'run_id' => $run_id,
            'summary' => $summary,
            'receiver' => $receiver,
            'report_download_url' => Report_Repository::download_url($run_id),
        );
    }
}
