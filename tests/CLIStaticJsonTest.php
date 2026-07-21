<?php
use Ashko\Patris\CLI;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-cli.php';

if (!class_exists('WP_CLI')) {
    final class WP_CLI {
        public static function error(string $message): void {
            throw new RuntimeException($message);
        }

        public static function get_runner(): object {
            return (object) array('config' => array());
        }
    }
}

final class CLIStaticJsonTest extends TestCase {
    private array $files = array();

    protected function tearDown(): void {
        $GLOBALS['ashko_test_current_user_can'] = false;
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_static_json_is_resolved_and_read_only_outside_web_root(): void {
        $file = tempnam(sys_get_temp_dir(), 'ashco-kala-');
        $this->files[] = $file;
        file_put_contents($file, '{"schema":"patris.product-sync"}');

        $resolved = CLI::resolve_static_json_path($file);

        self::assertFalse(is_wp_error($resolved));
        self::assertSame(realpath($file), $resolved);
        self::assertSame('{"schema":"patris.product-sync"}', CLI::read_static_json($file));
    }

    public function test_static_json_inside_web_root_is_rejected_after_realpath_resolution(): void {
        $file = dirname(__DIR__) . '/tests/ashco-current-report-temp.json';
        $this->files[] = $file;
        file_put_contents($file, '{}');

        $result = CLI::resolve_static_json_path($file, dirname(__DIR__));

        self::assertTrue(is_wp_error($result));
        self::assertSame('ashko_static_json_inside_web_root', $result->get_error_code());
    }

    public function test_empty_static_json_is_rejected_before_read(): void {
        $file = tempnam(sys_get_temp_dir(), 'ashco-kala-empty-');
        $this->files[] = $file;

        $result = CLI::resolve_static_json_path($file);

        self::assertTrue(is_wp_error($result));
        self::assertSame('ashko_static_json_size', $result->get_error_code());
    }

    public function test_oversized_static_json_is_rejected_before_read(): void {
        $file = tempnam(sys_get_temp_dir(), 'ashco-kala-large-');
        $this->files[] = $file;
        $handle = fopen($file, 'wb');
        ftruncate($handle, CLI::MAX_STATIC_JSON_BYTES + 1);
        fclose($handle);

        $result = CLI::resolve_static_json_path($file);

        self::assertTrue(is_wp_error($result));
        self::assertSame('ashko_static_json_size', $result->get_error_code());
    }

    public function test_mutation_requires_yes_an_explicit_user_and_administrator_capabilities(): void {
        try {
            CLI::apply(array('/not/read'), array('user' => 'administrator'));
            self::fail('Missing --yes was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('--yes', $exception->getMessage());
        }

        try {
            CLI::apply(array('/not/read'), array('yes' => true));
            self::fail('Missing --user was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('--user', $exception->getMessage());
        }

        try {
            CLI::apply(array('/not/read'), array('yes' => true, 'user' => 'subscriber'));
            self::fail('A non-administrator user was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('administrator', $exception->getMessage());
        }

        $GLOBALS['ashko_test_current_user_can'] = true;
        try {
            CLI::apply(array('/not/read'), array('yes' => true, 'user' => 'administrator'));
            self::fail('An unreadable path was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ashko_static_json_unreadable', $exception->getMessage());
        }
    }
}
