<?php
/**
 * One-time production maintenance command.
 *
 * Usage: wp eval-file scripts/repair-rank-math-branding.php
 */

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this maintenance script through WP-CLI.');
}

require_once __DIR__ . '/lib/class-rank-math-branding-repair.php';

$result = \AtomicDeploy\Ashco\Maintenance\Rank_Math_Branding_Repair::run();
if (0 !== $result['post_meta_failed']) {
    \WP_CLI::error('Ashco Rank Math repair encountered a post-meta deletion failure.');
}

\WP_CLI::success(wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
