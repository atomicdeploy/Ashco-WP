<?php
namespace Ashko\Patris;

use Ashko\Patris\Admin\Admin;
use Ashko\Patris\API\REST_Controller;

final class Plugin {
    public const DB_VERSION = '2';

    public static function activate(): void {
        $stored = get_option(Config::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        update_option(Config::OPTION, Config::sanitize(array_merge(Config::defaults(), $stored)), false);
        add_option(Config::SOURCE_SCOPES_OPTION, array(), '', false);
        Config::secret();
        Report_Repository::install();
        update_option('ashko_patris_db_version', self::DB_VERSION, false);
    }

    public static function boot(): void {
        load_plugin_textdomain('ashko-wp', false, dirname(plugin_basename(ASHKO_WP_FILE)) . '/languages');
        if (self::DB_VERSION !== (string) get_option('ashko_patris_db_version', '')) {
            Report_Repository::install();
            update_option('ashko_patris_db_version', self::DB_VERSION, false);
        }
        add_action('rest_api_init', array(REST_Controller::class, 'register'));
        Admin::register();
        ACF_Integration::register();
        Frontend_Stock::register();
        add_action('admin_notices', array(self::class, 'requirements_notice'));
        if (defined('WP_CLI') && WP_CLI) {
            CLI::register();
        }
    }

    public static function declare_compatibility(): void {
        if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ASHKO_WP_FILE, true);
        }
    }

    public static function requirements_notice(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Ashko-WP برای همگام‌سازی کالا به WooCommerce نیاز دارد.', 'ashko-wp') . '</p></div>';
            return;
        }
        $status = WooCommerce_Currency_Status::instance()->get_status();
        if (!$status['compatible']) {
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('ارز پایه WooCommerce باید IRR باشد؛ مقدار فعلی %s است. هیچ قیمت IRT بدون تبدیل روی اشکو نوشته نمی‌شود.', 'ashko-wp'), $status['code'])) . '</p></div>';
        }
        $active_switchers = array_filter(Integration_Status::currency_switchers(), static fn($plugin) => !empty($plugin['active']));
        if ($active_switchers) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('یک افزونه تغییر ارز فعال است. Ashko-WP نرخ CNY مرجع را خود مدیریت می‌کند؛ برای جلوگیری از تغییر قیمت IRR، وضعیت افزونه ارزی را بررسی کنید.', 'ashko-wp') . '</p></div>';
        }
    }
}
