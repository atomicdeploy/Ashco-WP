<?php
use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase {
    public function test_runtime_strings_are_present_in_template_and_persian_catalog(): void {
        $root = dirname(__DIR__);
        $runtime_strings = $this->runtime_strings($root);
        $template = $this->catalog($root . '/languages/ashko-wp.pot');
        $persian = $this->catalog($root . '/languages/ashko-wp-fa_IR.po');

        foreach ($runtime_strings as $message) {
            self::assertArrayHasKey($message, $template, 'Missing POT message: ' . $message);
            self::assertArrayHasKey($message, $persian, 'Missing fa_IR message: ' . $message);
            self::assertNotSame('', $persian[$message], 'Empty fa_IR translation: ' . $message);
        }
    }

    /** @return string[] */
    private function runtime_strings(string $root): array {
        $files = array($root . '/ashko-wp.php');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === strtolower($file->getExtension())) {
                $files[] = $file->getPathname();
            }
        }

        $messages = array();
        $pattern = '/(?:__|esc_html__|esc_attr__)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])ashko-wp\3/s';
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $message = "'" === $match[1]
                    ? str_replace(array("\\\\", "\\'"), array("\\", "'"), $match[2])
                    : stripcslashes($match[2]);
                if ('' !== $message) {
                    $messages[$message] = $message;
                }
            }
        }
        ksort($messages, SORT_STRING);
        return array_values($messages);
    }

    /** @return array<string,string> */
    private function catalog(string $path): array {
        $entries = array();
        $message = null;
        $translation = null;
        $field = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: array() as $line) {
            if (str_starts_with($line, 'msgid ')) {
                if (null !== $message && '' !== $message) {
                    $entries[$message] = (string) $translation;
                }
                $message = $this->po_string(substr($line, 6));
                $translation = '';
                $field = 'id';
            } elseif (str_starts_with($line, 'msgstr ')) {
                $translation = $this->po_string(substr($line, 7));
                $field = 'str';
            } elseif (preg_match('/^".*"$/s', $line)) {
                if ('id' === $field) {
                    $message .= $this->po_string($line);
                } elseif ('str' === $field) {
                    $translation .= $this->po_string($line);
                }
            } elseif ('' === trim($line)) {
                if (null !== $message && '' !== $message) {
                    $entries[$message] = (string) $translation;
                }
                $message = null;
                $translation = null;
                $field = null;
            }
        }
        if (null !== $message && '' !== $message) {
            $entries[$message] = (string) $translation;
        }
        return $entries;
    }

    private function po_string(string $quoted): string {
        $decoded = json_decode($quoted, true);
        self::assertIsString($decoded, 'Invalid PO string: ' . $quoted);
        return $decoded;
    }
}
