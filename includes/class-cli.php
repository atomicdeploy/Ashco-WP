<?php
namespace Ashko\Patris;

final class CLI {
    public const MAX_STATIC_JSON_BYTES = 8388608;

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

    public static function dry_run(array $args, array $assoc = array()): void {
        self::file_action($args, false);
    }

    public static function apply(array $args, array $assoc): void {
        if (empty($assoc['yes'])) {
            \WP_CLI::error(__('Use --yes after reviewing an Ashco dry-run report.', 'ashko-wp'));
        }
        self::require_administrator_user($assoc);
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
        $json = self::read_static_json((string) $path);
        if (is_wp_error($json)) {
            \WP_CLI::error($json->get_error_code() . ': ' . $json->get_error_message());
        }
        $service = new Sync_Service();
        self::print_result($apply ? $service->apply($json) : $service->dry_run($json));
    }

    /** @return string|\WP_Error */
    public static function resolve_static_json_path(string $path, ?string $web_root = null) {
        if ('' === trim($path)) {
            return new \WP_Error('ashko_static_json_path_required', __('Provide a canonical Ashco kala.json path.', 'ashko-wp'));
        }
        $resolved = realpath($path);
        if (false === $resolved || !is_file($resolved) || !is_readable($resolved)) {
            return new \WP_Error('ashko_static_json_unreadable', __('The canonical Ashco kala.json file is not readable.', 'ashko-wp'));
        }
        $root = realpath(null === $web_root ? ABSPATH : $web_root);
        if (false === $root) {
            return new \WP_Error('ashko_web_root_unresolved', __('The Ashco web root could not be resolved safely.', 'ashko-wp'));
        }
        $normalized_path = str_replace('\\', '/', $resolved);
        $normalized_root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if ('\\' === DIRECTORY_SEPARATOR) {
            $normalized_path = strtolower($normalized_path);
            $normalized_root = strtolower($normalized_root);
        }
        if (rtrim($normalized_path, '/') === rtrim($normalized_root, '/') || str_starts_with($normalized_path, $normalized_root)) {
            return new \WP_Error('ashko_static_json_inside_web_root', __('Static kala.json input must be stored outside the Ashco web root.', 'ashko-wp'));
        }
        $size = filesize($resolved);
        if (false === $size || $size < 1 || $size > self::MAX_STATIC_JSON_BYTES) {
            return new \WP_Error('ashko_static_json_size', __('The canonical Ashco kala.json file is empty or exceeds the safe size limit.', 'ashko-wp'));
        }
        return $resolved;
    }

    /** @return string|\WP_Error */
    public static function read_static_json(string $path) {
        $resolved = self::resolve_static_json_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $expected_size = filesize($resolved);
        $handle = @fopen($resolved, 'rb');
        if (false === $handle) {
            return new \WP_Error('ashko_static_json_open_failed', __('The canonical Ashco kala.json file could not be opened.', 'ashko-wp'));
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return new \WP_Error('ashko_static_json_lock_failed', __('The canonical Ashco kala.json file could not be locked for reading.', 'ashko-wp'));
            }
            $stat = fstat($handle);
            $open_size = is_array($stat) ? (int) ($stat['size'] ?? -1) : -1;
            if ($open_size < 1 || $open_size > self::MAX_STATIC_JSON_BYTES || $open_size !== (int) $expected_size) {
                return new \WP_Error('ashko_static_json_changed', __('The canonical Ashco kala.json file changed during its safety check.', 'ashko-wp'));
            }
            $json = stream_get_contents($handle, self::MAX_STATIC_JSON_BYTES + 1);
            if (false === $json || strlen($json) !== $open_size) {
                return new \WP_Error('ashko_static_json_read_failed', __('The canonical Ashco kala.json file could not be read completely.', 'ashko-wp'));
            }
            return $json;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function require_administrator_user(array $assoc): void {
        $user = (string) ($assoc['user'] ?? '');
        if ('' === $user && method_exists('\WP_CLI', 'get_runner')) {
            $runner = \WP_CLI::get_runner();
            $user = is_object($runner) && is_array($runner->config ?? null)
                ? (string) ($runner->config['user'] ?? '')
                : '';
        }
        if ('' === $user) {
            \WP_CLI::error(__('Mutation requires an explicit administrator --user and --yes.', 'ashko-wp'));
        }
        if (!current_user_can('manage_options') || !current_user_can('manage_woocommerce')) {
            \WP_CLI::error(__('The explicit --user must be an Ashco administrator with WooCommerce management access.', 'ashko-wp'));
        }
    }

    private static function print_result($result): void {
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message());
        }
        \WP_CLI::print_value($result, array('format' => 'json'));
    }
}
