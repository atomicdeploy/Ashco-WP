<?php
namespace Ashko\Patris\API;

use Ashko\Patris\Config;
use Ashko\Patris\Product_Sync_Receiver;
use Ashko\Patris\Report_Repository;
use Ashko\Patris\Sync_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class REST_Controller {
    public const NAMESPACE = 'ashko';

    public static function register(): void {
        register_rest_route(self::NAMESPACE, '/patris/product-sync/dry-run', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'dry_run'),
            'permission_callback' => array(self::class, 'permission'),
        ));
        register_rest_route(self::NAMESPACE, '/patris/product-sync/apply', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'apply'),
            'permission_callback' => array(self::class, 'permission'),
        ));
        register_rest_route(self::NAMESPACE, '/patris/product-sync/status', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'status'),
            'permission_callback' => static fn() => current_user_can('manage_woocommerce'),
        ));
    }

    public static function permission(WP_REST_Request $request) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        $provided = (string) $request->get_header('x-ashko-product-sync-secret');
        if ('' === $provided) {
            $provided = (string) $request->get_header('x-digitalogic-product-sync-secret');
        }
        if ('' !== $provided && hash_equals(Config::secret(), $provided)) {
            return true;
        }
        return new WP_Error('ashko_product_sync_unauthorized', __('A valid Ashko sync credential is required.', 'ashko-wp'), array('status' => 401));
    }

    public static function dry_run(WP_REST_Request $request) {
        $header_check = self::validate_headers($request);
        if (is_wp_error($header_check)) {
            return $header_check;
        }
        return self::respond((new Sync_Service())->dry_run((string) $request->get_body()));
    }

    public static function apply(WP_REST_Request $request) {
        $header_check = self::validate_headers($request);
        if (is_wp_error($header_check)) {
            return $header_check;
        }
        return self::respond((new Sync_Service())->apply((string) $request->get_body()));
    }

    public static function status(): WP_REST_Response {
        return new WP_REST_Response(array(
            'success' => true,
            'receiver' => Product_Sync_Receiver::instance()->get_status(),
            'recent_reports' => (new Report_Repository())->latest_runs(10),
        ), 200);
    }

    private static function validate_headers(WP_REST_Request $request) {
        $headers = array(
            'x-patris-contract' => 'schema',
            'x-patris-event-id' => 'event_id',
        );
        $body = null;
        foreach ($headers as $header => $field) {
            $provided = (string) $request->get_header($header);
            if ('' === $provided) {
                continue;
            }
            if (null === $body) {
                $body = json_decode((string) $request->get_body(), true);
            }
            if (!is_array($body) || !isset($body[$field]) || !is_string($body[$field]) || !hash_equals($provided, $body[$field])) {
                return new WP_Error(
                    'ashko_product_sync_header_mismatch',
                    __('A Patris contract header does not match the JSON document.', 'ashko-wp'),
                    array('status' => 400, 'header' => $header)
                );
            }
        }
        return true;
    }

    private static function respond($result) {
        if (is_wp_error($result)) {
            return $result;
        }
        return new WP_REST_Response($result, 200);
    }
}
