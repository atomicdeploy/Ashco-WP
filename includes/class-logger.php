<?php
namespace Ashko\Patris;

/** Minimal local logger; product payloads and credentials are never logged. */
final class Logger {
    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($event, $channel = '', $object_id = null, $user_id = null, $context = '', $message = ''): void {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Ashko Patris] ' . sanitize_key((string) $event) . ' ' . sanitize_text_field((string) $message));
        }
    }
}
