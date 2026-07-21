<?php
namespace Ashko\Patris;

/**
 * Adds a display-only IRR/تومان preference to non-transactional storefront views.
 *
 * WooCommerce remains authoritative in IRR. This class deliberately does not
 * register any Woo price, total, currency, REST, order, or payment filters.
 */
final class Storefront_Price_Display {
    public const SCRIPT_HANDLE = 'ashco-storefront-price-display';
    public const STYLE_HANDLE = 'ashco-storefront-price-display';

    private const BLOCKED_CONDITIONALS = array(
        'is_cart',
        'is_checkout',
        'is_account_page',
        'is_wc_endpoint_url',
    );

    public static function register(): void {
        add_action('wp_enqueue_scripts', array(self::class, 'enqueue_assets'), 30);
        add_action('wp_footer', array(self::class, 'render_switch'), 20);
    }

    /**
     * The switch is intentionally absent from every transactional surface.
     */
    public static function is_eligible_request(): bool {
        if (function_exists('is_admin') && is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_json_request') && wp_is_json_request())
        ) {
            return false;
        }
        if ((function_exists('is_feed') && is_feed())
            || (function_exists('is_embed') && is_embed())
        ) {
            return false;
        }
        foreach (self::BLOCKED_CONDITIONALS as $conditional) {
            if (function_exists($conditional) && $conditional()) {
                return false;
            }
        }

        return function_exists('get_woocommerce_currency')
            && 'IRR' === strtoupper((string) get_woocommerce_currency());
    }

    public static function enqueue_assets(): void {
        if (!self::is_eligible_request()) {
            return;
        }

        $style = 'assets/css/storefront-price-display.css';
        $script = 'assets/js/storefront-price-display.js';
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugins_url($style, ASHKO_WP_FILE),
            array(),
            self::asset_version($style)
        );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url($script, ASHKO_WP_FILE),
            array(),
            self::asset_version($script),
            array('strategy' => 'defer', 'in_footer' => true)
        );
        wp_localize_script(self::SCRIPT_HANDLE, 'ashcoStorefrontPriceDisplay', array(
            'defaultCurrency' => 'IRR',
            'conversionRate' => 10,
            'storageKey' => 'ashco_storefront_display_currency',
            'cookieName' => 'ashco_display_currency',
            'labels' => array(
                'IRR' => __('ریال', 'ashko-wp'),
                'IRT' => __('تومان', 'ashko-wp'),
            ),
            'status' => array(
                'IRR' => __('قیمت‌ها به ریال نمایش داده می‌شوند.', 'ashko-wp'),
                'IRT' => __('قیمت‌ها به تومان نمایش داده می‌شوند.', 'ashko-wp'),
            ),
        ));
    }

    public static function render_switch(): void {
        if (!self::is_eligible_request()) {
            return;
        }
        ?>
        <aside class="ashco-price-display-switch" dir="rtl" aria-label="<?php echo esc_attr__('انتخاب واحد نمایش قیمت', 'ashko-wp'); ?>" hidden>
            <fieldset class="ashco-price-display-switch__fieldset" aria-describedby="ashco-price-display-help ashco-price-display-status">
                <legend class="ashco-price-display-switch__legend"><?php echo esc_html__('نمایش قیمت', 'ashko-wp'); ?></legend>
                <label class="ashco-price-display-switch__choice">
                    <input type="radio" name="ashco-price-display-currency" value="IRR" checked>
                    <span><?php echo esc_html__('ریال', 'ashko-wp'); ?></span>
                </label>
                <label class="ashco-price-display-switch__choice">
                    <input type="radio" name="ashco-price-display-currency" value="IRT">
                    <span><?php echo esc_html__('تومان', 'ashko-wp'); ?></span>
                </label>
            </fieldset>
            <p id="ashco-price-display-help" class="screen-reader-text">
                <?php echo esc_html__('هر ۱ تومان برابر با ۱۰ ریال است؛ این انتخاب فقط شیوهٔ نمایش قیمت را تغییر می‌دهد.', 'ashko-wp'); ?>
            </p>
            <p id="ashco-price-display-status" class="ashco-price-display-switch__status screen-reader-text" aria-live="polite" aria-atomic="true">
                <?php echo esc_html__('قیمت‌ها به ریال نمایش داده می‌شوند.', 'ashko-wp'); ?>
            </p>
        </aside>
        <?php
    }

    private static function asset_version(string $relative_path): string {
        $path = ASHKO_WP_DIR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        $modified = is_file($path) ? (string) filemtime($path) : '0';
        return ASHKO_WP_VERSION . '.' . $modified;
    }
}
