<?php
namespace Ashko\Patris;

final class CLI {
    public static function register(): void {
        \WP_CLI::add_command('ashko patris status', array(self::class, 'status'));
        \WP_CLI::add_command('ashko patris reconcile', array(self::class, 'reconcile'));
        \WP_CLI::add_command('ashko patris dry-run', array(self::class, 'dry_run'));
        \WP_CLI::add_command('ashko patris apply', array(self::class, 'apply'));
        \WP_CLI::add_command('ashko patris cleanup-excerpts', array(self::class, 'cleanup_excerpts'));
    }

    public static function status(): void {
        \WP_CLI::print_value(Product_Sync_Receiver::instance()->get_status(), array('format' => 'json'));
    }

    public static function reconcile(array $args, array $assoc): void {
        $result = Product_Sync_Receiver::instance()->reconcile($assoc['source-id'] ?? null, $assoc['dataset'] ?? null);
        self::print_result($result);
    }

    public static function dry_run(array $args): void {
        self::file_action($args, false);
    }

    public static function apply(array $args, array $assoc): void {
        if (empty($assoc['yes'])) {
            \WP_CLI::error('Use --yes after reviewing an Ashco dry-run report.');
        }
        self::file_action($args, true);
    }

    public static function cleanup_excerpts(array $args, array $assoc): void {
        $apply = !empty($assoc['yes']);
        $result = Product_Presentation::cleanup_legacy_excerpts($apply);
        \WP_CLI::print_value($result, array('format' => 'json'));
        if (!$apply) {
            \WP_CLI::log('Dry run only. Re-run with --yes to clear the exact matched legacy excerpts.');
        } elseif ($result['errors']) {
            \WP_CLI::error('One or more legacy excerpts could not be cleared.');
        }
    }

    private static function file_action(array $args, bool $apply): void {
        $path = $args[0] ?? '';
        if ('' === $path || !is_readable($path)) {
            \WP_CLI::error('Provide a readable patris.product-sync JSON file.');
        }
        $json = file_get_contents($path);
        $service = new Sync_Service();
        self::print_result($apply ? $service->apply($json) : $service->dry_run($json));
    }

    private static function print_result($result): void {
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message());
        }
        \WP_CLI::print_value($result, array('format' => 'json'));
    }
}
