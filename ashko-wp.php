<?php
/**
 * Plugin Name: Ashko-WP Patris Sync
 * Plugin URI: https://github.com/AtomicDeploy/Ashko-WP
 * Description: هماهنگ‌سازی امن و قابل گزارش کالا، قیمت و موجودی پاتریس با WooCommerce اشکو.
 * Version: 1.0.1
 * Author: AtomicDeploy
 * Text Domain: ashko-wp
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ASHKO_WP_VERSION', '1.0.1');
define('ASHKO_WP_FILE', __FILE__);
define('ASHKO_WP_DIR', plugin_dir_path(__FILE__));

require_once ASHKO_WP_DIR . 'includes/class-config.php';
require_once ASHKO_WP_DIR . 'includes/class-woocommerce-currency-status.php';
require_once ASHKO_WP_DIR . 'includes/class-memory-guard.php';
require_once ASHKO_WP_DIR . 'includes/class-decimal-calculator.php';
require_once ASHKO_WP_DIR . 'includes/class-serial-resolver.php';
require_once ASHKO_WP_DIR . 'includes/class-product-applicator.php';
require_once ASHKO_WP_DIR . 'includes/class-report-repository.php';
require_once ASHKO_WP_DIR . 'includes/class-logger.php';
require_once ASHKO_WP_DIR . 'includes/class-product-sync-receiver.php';
require_once ASHKO_WP_DIR . 'includes/class-sync-service.php';
require_once ASHKO_WP_DIR . 'includes/class-acf-integration.php';
require_once ASHKO_WP_DIR . 'includes/class-frontend-stock.php';
require_once ASHKO_WP_DIR . 'includes/class-jalali.php';
require_once ASHKO_WP_DIR . 'includes/class-integration-status.php';
require_once ASHKO_WP_DIR . 'includes/api/class-rest-controller.php';
require_once ASHKO_WP_DIR . 'includes/admin/class-admin.php';
require_once ASHKO_WP_DIR . 'includes/class-cli.php';
require_once ASHKO_WP_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array(\Ashko\Patris\Plugin::class, 'activate'));
add_action('plugins_loaded', array(\Ashko\Patris\Plugin::class, 'boot'));
add_action('before_woocommerce_init', array(\Ashko\Patris\Plugin::class, 'declare_compatibility'));
