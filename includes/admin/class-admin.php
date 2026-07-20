<?php
namespace Ashko\Patris\Admin;

use Ashko\Patris\Config;
use Ashko\Patris\Integration_Status;
use Ashko\Patris\Report_Repository;

final class Admin {
    public static function register(): void {
        add_action('admin_menu', array(self::class, 'menu'));
        add_action('admin_post_ashko_save_patris_settings', array(self::class, 'save_settings'));
        add_action('admin_post_ashko_download_patris_report', array(self::class, 'download_report'));
    }

    public static function menu(): void {
        add_menu_page(
            __('هماهنگ‌سازی پاتریس اشکو', 'ashko-wp'),
            __('پاتریس اشکو', 'ashko-wp'),
            'manage_woocommerce',
            'ashko-patris',
            array(self::class, 'render'),
            'dashicons-update',
            56
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'ashko-wp'));
        }
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'reports';
        echo '<div class="wrap" dir="rtl"><h1>' . esc_html__('هماهنگ‌سازی کالا و قیمت پاتریس — اشکو', 'ashko-wp') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        self::tab_link('reports', __('گزارش‌ها و هشدارها', 'ashko-wp'), $tab);
        self::tab_link('settings', __('تنظیمات قیمت و اتصال', 'ashko-wp'), $tab);
        echo '</nav>';
        if ('settings' === $tab) {
            self::settings();
        } else {
            self::reports();
        }
        echo '</div>';
    }

    private static function reports(): void {
        $repository = new Report_Repository();
        $runs = $repository->latest_runs(20);
        if (array() === $runs) {
            echo '<p>' . esc_html__('هنوز اجرای خشک یا اعمال ثبت نشده است.', 'ashko-wp') . '</p>';
            return;
        }
        $selected = isset($_GET['run_id']) ? absint($_GET['run_id']) : (int) $runs[0]['id'];
        $run = $repository->get_run($selected);
        echo '<h2>' . esc_html__('اجرای اخیر', 'ashko-wp') . '</h2><table class="widefat striped"><thead><tr>';
        foreach (array('شناسه', 'حالت', 'وضعیت', 'رویداد', 'دریافتی', 'تطبیق', 'تغییریافته', 'بدون تغییر', 'نامطابق', 'مبهم', 'زمان') as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($runs as $item) {
            $url = admin_url('admin.php?page=ashko-patris&run_id=' . (int) $item['id']);
            echo '<tr><td><a href="' . esc_url($url) . '">' . (int) $item['id'] . '</a></td>';
            echo '<td>' . esc_html($item['mode']) . '</td><td>' . esc_html($item['status']) . '</td>';
            echo '<td><code>' . esc_html(substr($item['event_id'], 0, 20)) . '…</code></td>';
            foreach (array('received_products', 'matched_products', 'changed_products', 'unchanged_products', 'unmatched_products', 'ambiguous_products') as $field) {
                echo '<td>' . number_format_i18n((int) $item[$field]) . '</td>';
            }
            echo '<td>' . esc_html($item['created_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$run) {
            return;
        }

        $core = json_decode((string) $run['core_field_counts'], true) ?: array();
        $meta = json_decode((string) $run['meta_field_counts'], true) ?: array();
        $warnings = json_decode((string) $run['warning_counts'], true) ?: array();
        echo '<h2>' . esc_html(sprintf(__('جزئیات اجرای شماره %d', 'ashko-wp'), $selected)) . '</h2>';
        echo '<p><a class="button button-primary" href="' . esc_url(Report_Repository::download_url($selected)) . '">' . esc_html__('دانلود گزارش کامل CSV', 'ashko-wp') . '</a></p>';
        self::counts_table(__('تغییرات فیلدهای ووکامرس', 'ashko-wp'), $core);
        self::counts_table(__('تغییرات متادیتا', 'ashko-wp'), $meta);
        self::counts_table(__('گروه‌های هشدار', 'ashko-wp'), $warnings);

        $rows = $repository->rows($selected, 200, 0);
        echo '<h3>' . esc_html__('فهرست کالا/قیمت (۲۰۰ ردیف نخست)', 'ashko-wp') . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Serial</th><th>Woo ID</th><th>' . esc_html__('نتیجه', 'ashko-wp') . '</th><th>' . esc_html__('قیمت منبع IRT', 'ashko-wp') . '</th><th>' . esc_html__('قیمت نهایی IRR', 'ashko-wp') . '</th><th>' . esc_html__('مغایرت IRR', 'ashko-wp') . '</th><th>' . esc_html__('تاریخ نرخ (میلادی/جلالی)', 'ashko-wp') . '</th><th>' . esc_html__('فیلدها', 'ashko-wp') . '</th><th>' . esc_html__('هشدارها', 'ashko-wp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $fields = array_merge(array_keys(json_decode($row['core_changes'], true) ?: array()), array_keys(json_decode($row['meta_changes'], true) ?: array()));
            $row_warnings = json_decode($row['warnings'], true) ?: array();
            echo '<tr><td><code>' . esc_html($row['product_code']) . '</code></td><td>' . esc_html($row['serial']) . '</td><td>' . esc_html($row['woo_id']) . '</td>';
            echo '<td>' . esc_html($row['resolution']) . '</td><td>' . esc_html($row['canonical_final_irt']) . '</td><td>' . esc_html($row['final_irr']) . '</td><td>' . esc_html($row['formula_discrepancy_irr']) . '</td><td>' . esc_html($row['currency_effective_date'] . ' / ' . $row['currency_effective_date_jalali']) . '</td>';
            echo '<td><small>' . esc_html(implode('، ', $fields)) . '</small></td><td><small>' . esc_html(implode('، ', $row_warnings)) . '</small></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function settings(): void {
        $settings = Config::all();
        $scopes = get_option(Config::SOURCE_SCOPES_OPTION, array());
        $scopes_json = wp_json_encode(is_array($scopes) ? $scopes : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo '<h2>' . esc_html__('وضعیت یکپارچه‌سازی', 'ashko-wp') . '</h2>';
        echo '<p>' . esc_html__('Ashko-WP نرخ مرجع CNY و متادیتای محصول را مستقیماً مدیریت می‌کند؛ افزونه تبدیل ارز برای فروشگاه پایه IRR لازم نیست.', 'ashko-wp') . '</p>';
        echo '<p><strong>ACF:</strong> ' . esc_html(Integration_Status::acf_available() ? __('در دسترس؛ همگام‌سازی دوطرفه فعال است.', 'ashko-wp') : __('در دسترس نیست؛ متادیتای استاندارد Ashko-WP بدون اختلال کار می‌کند.', 'ashko-wp')) . '</p>';
        $switchers = Integration_Status::currency_switchers();
        if (array() === $switchers) {
            echo '<p>' . esc_html__('افزونه تغییر ارز شناسایی نشد.', 'ashko-wp') . '</p>';
        } else {
            echo '<ul>';
            foreach ($switchers as $switcher) {
                echo '<li><code>' . esc_html($switcher['name'] . ' ' . $switcher['version']) . '</code> — ' . esc_html($switcher['active'] ? __('فعال (احتمال تعارض)', 'ashko-wp') : __('غیرفعال (وضعیت توصیه‌شده)', 'ashko-wp')) . '</li>';
            }
            echo '</ul>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ashko_save_patris_settings">';
        wp_nonce_field('ashko_save_patris_settings');
        echo '<table class="form-table"><tbody>';
        self::input('serial_meta_key', __('کلید متای دقیق Serial', 'ashko-wp'), $settings['serial_meta_key'], __('پیش‌فرض و مقدار تأییدشده سایت: _sku. تطبیق Code مجاز نیست.', 'ashko-wp'));
        self::input('fx_irr_per_cny', __('نرخ هر CNY به ریال', 'ashko-wp'), $settings['fx_irr_per_cny']);
        self::input('freight_irr_per_kg', __('هزینه هوایی/اکسپرس هر کیلو (ریال)', 'ashko-wp'), $settings['freight_irr_per_kg']);
        self::input('profit_margin_percent', __('حاشیه سود (درصد)', 'ashko-wp'), $settings['profit_margin_percent']);
        self::input('stock_percent', __('درصد موجودی ALLANBAR قابل فروش', 'ashko-wp'), $settings['stock_percent']);
        self::input('default_freight_method', __('روش پیش‌فرض حمل', 'ashko-wp'), $settings['default_freight_method']);
        self::checkbox('show_exact_stock', __('نمایش تعداد موجودی در سایت', 'ashko-wp'), $settings['show_exact_stock']);
        self::checkbox('keep_out_of_stock_visible', __('نمایش کالاهای ناموجود', 'ashko-wp'), $settings['keep_out_of_stock_visible']);
        echo '<tr><th>' . esc_html__('محدوده منبع مجاز (JSON)', 'ashko-wp') . '</th><td><textarea name="source_scopes" rows="6" cols="70" dir="ltr">' . esc_textarea($scopes_json) . '</textarea><p class="description">[{"id":"patris-office","dataset":"kala.db"}] — [] یعنی راه‌اندازی اولیه بدون محدودیت.</p></td></tr>';
        echo '<tr><th>' . esc_html__('کلید محرمانه REST', 'ashko-wp') . '</th><td><input type="password" readonly class="regular-text" value="' . esc_attr(Config::secret()) . '"><p class="description">Patris Export: X-Digitalogic-Product-Sync-Secret — alias: X-Ashko-Product-Sync-Secret</p></td></tr>';
        echo '<tr><th>' . esc_html__('نشانی اجرای خشک', 'ashko-wp') . '</th><td><code dir="ltr">' . esc_html(rest_url('ashko/v1/patris/product-sync/dry-run')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('نشانی اعمال', 'ashko-wp') . '</th><td><code dir="ltr">' . esc_html(rest_url('ashko/v1/patris/product-sync/apply')) . '</code></td></tr>';
        echo '</tbody></table>';
        submit_button(__('ذخیره تنظیمات', 'ashko-wp'));
        echo '</form><p>' . esc_html__('اتصال به سرویس‌های خارجی به‌طور پیش‌فرض غیرفعال است و این نسخه هیچ داده‌ای را بدون پیکربندی صریح ارسال نمی‌کند.', 'ashko-wp') . '</p>';
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'ashko-wp'));
        }
        check_admin_referer('ashko_save_patris_settings');
        $settings = Config::sanitize(wp_unslash($_POST));
        update_option(Config::OPTION, $settings, false);
        $raw_scopes = isset($_POST['source_scopes']) ? wp_unslash((string) $_POST['source_scopes']) : '[]';
        $scopes = json_decode($raw_scopes, true);
        if (is_array($scopes)) {
            update_option(Config::SOURCE_SCOPES_OPTION, $scopes, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=ashko-patris&tab=settings&updated=1'));
        exit;
    }

    public static function download_report(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'ashko-wp'));
        }
        $run_id = isset($_GET['run_id']) ? absint($_GET['run_id']) : 0;
        check_admin_referer('ashko_download_patris_report_' . $run_id);
        $repository = new Report_Repository();
        if (!$repository->get_run($run_id)) {
            wp_die(esc_html__('گزارش پیدا نشد.', 'ashko-wp'));
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ashko-patris-report-' . $run_id . '.csv"');
        $stream = fopen('php://output', 'wb');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array('product_code', 'serial', 'woo_id', 'resolution', 'changed', 'canonical_final_irt', 'canonical_final_irr', 'native_final_irt', 'final_irr', 'formula_discrepancy_irt', 'formula_discrepancy_irr', 'currency_effective_date', 'currency_effective_date_jalali', 'core_changes', 'meta_changes', 'warnings'), ',', '"', '');
        foreach ($repository->all_rows($run_id) as $row) {
            fputcsv($stream, array(
                $row['product_code'], $row['serial'], $row['woo_id'], $row['resolution'], $row['changed'],
                $row['canonical_final_irt'], $row['canonical_final_irr'], $row['native_final_irt'], $row['final_irr'],
                $row['formula_discrepancy_irt'], $row['formula_discrepancy_irr'], $row['currency_effective_date'], $row['currency_effective_date_jalali'], $row['core_changes'], $row['meta_changes'], $row['warnings'],
            ), ',', '"', '');
        }
        fclose($stream);
        exit;
    }

    private static function tab_link(string $slug, string $label, string $active): void {
        $class = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=ashko-patris&tab=' . $slug)) . '">' . esc_html($label) . '</a>';
    }

    private static function counts_table(string $title, array $counts): void {
        echo '<h3>' . esc_html($title) . '</h3>';
        if (array() === $counts) {
            echo '<p>—</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>' . esc_html__('فیلد/هشدار', 'ashko-wp') . '</th><th>' . esc_html__('تعداد کالا', 'ashko-wp') . '</th></tr></thead><tbody>';
        foreach ($counts as $key => $count) {
            echo '<tr><td><code>' . esc_html($key) . '</code></td><td>' . number_format_i18n((int) $count) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function input(string $name, string $label, string $value, string $description = ''): void {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" dir="ltr" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        if ('' !== $description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function checkbox(string $name, string $label, string $value): void {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '" value="yes" ' . checked('yes', $value, false) . '> ' . esc_html__('فعال', 'ashko-wp') . '</label></td></tr>';
    }
}
