<?php
namespace Ashko\Patris\Admin;

use Ashko\Patris\Config;
use Ashko\Patris\Current_Catalog_Report;
use Ashko\Patris\Integration_Status;
use Ashko\Patris\Product_Presentation;
use Ashko\Patris\Report_Repository;

final class Admin {
    public static function register(): void {
        add_action('admin_menu', array(self::class, 'menu'));
        add_action('admin_post_ashko_save_patris_settings', array(self::class, 'save_settings'));
        add_action('admin_post_ashko_download_patris_report', array(self::class, 'download_report'));
        add_action('admin_post_ashko_download_current_catalog_report', array(self::class, 'download_current_catalog_report'));
        add_action('admin_post_ashko_cleanup_legacy_excerpts', array(self::class, 'cleanup_legacy_excerpts'));
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
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'current';
        echo '<div class="wrap" dir="rtl"><h1>' . esc_html__('هماهنگ‌سازی کالا و قیمت پاتریس — اشکو', 'ashko-wp') . '</h1>';
        echo '<nav class="nav-tab-wrapper">';
        self::tab_link('current', __('وضعیت فعلی کالا و قیمت', 'ashko-wp'), $tab);
        self::tab_link('reports', __('تاریخچه اجراها', 'ashko-wp'), $tab);
        self::tab_link('settings', __('تنظیمات قیمت و اتصال', 'ashko-wp'), $tab);
        echo '</nav>';
        if ('settings' === $tab) {
            self::settings();
        } elseif ('reports' === $tab) {
            self::reports();
        } else {
            self::current_catalog();
        }
        echo '</div>';
    }

    private static function current_catalog(): void {
        $service = new Current_Catalog_Report();
        $snapshot = isset($_GET['snapshot']) ? sanitize_key((string) $_GET['snapshot']) : '';
        if (!in_array($snapshot, array('candidate', 'applied'), true)) {
            $snapshot = Current_Catalog_Report::has_staged_candidate() ? 'candidate' : 'applied';
        }
        $report = $service->build(null, null, $snapshot);
        if (is_wp_error($report)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($report->get_error_message()) . '</p></div>';
            return;
        }

        $criteria = array(
            'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '',
            'scope' => isset($_GET['scope']) ? sanitize_key((string) $_GET['scope']) : 'all',
            'warning' => isset($_GET['warning']) ? sanitize_text_field(wp_unslash((string) $_GET['warning'])) : '',
            'snapshot' => $report['snapshot_kind'],
        );
        $page_number = isset($_GET['catalog_page']) ? max(1, absint($_GET['catalog_page'])) : 1;
        $page_size = isset($_GET['per_page']) ? absint($_GET['per_page']) : Current_Catalog_Report::DEFAULT_PAGE_SIZE;
        $filtered = $service->filtered_rows($report, $criteria);
        $page = $service->page($filtered, $page_number, $page_size);

        echo '<p>' . esc_html__('این نما مستقل از تاریخچه اجراها، تصویر کامل انتخاب‌شده را با وضعیت فعلی WooCommerce تطبیق می‌دهد و هیچ داده‌ای را تغییر نمی‌دهد.', 'ashko-wp') . '</p>';
        if ('candidate' === $report['snapshot_kind']) {
            echo '<div class="notice notice-info inline"><p><strong>' . esc_html__('پیش‌نمایش معتبر و اعمال‌نشده', 'ashko-wp') . '</strong> — ';
            echo esc_html(sprintf(__('زمان مرحله‌بندی: %s', 'ashko-wp'), $report['staged_at'])) . '</p></div>';
        }
        self::current_provenance($report['provenance']);
        self::current_summary($report['summary']);
        echo '<form method="get" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:16px 0">';
        echo '<input type="hidden" name="page" value="ashko-patris"><input type="hidden" name="tab" value="current">';
        echo '<label>' . esc_html__('تصویر گزارش', 'ashko-wp') . '<br><select name="snapshot">';
        foreach (array(
            'candidate' => __('پیش‌نمایش معتبر اعمال‌نشده', 'ashko-wp'),
            'applied' => __('وضعیت پذیرفته‌شده فعلی', 'ashko-wp'),
        ) as $value => $label) {
            if ('candidate' === $value && !Current_Catalog_Report::has_staged_candidate()) {
                continue;
            }
            echo '<option value="' . esc_attr($value) . '" ' . selected($value, $criteria['snapshot'], false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('جست‌وجو', 'ashko-wp') . '<br><input type="search" name="s" value="' . esc_attr($criteria['search']) . '" placeholder="' . esc_attr__('نام، کد، سریال یا شناسه', 'ashko-wp') . '"></label>';
        echo '<label>' . esc_html__('دامنه', 'ashko-wp') . '<br><select name="scope">';
        foreach (array(
            'all' => __('همه ردیف‌ها', 'ashko-wp'),
            'matched' => __('تطبیق‌یافته', 'ashko-wp'),
            'source_only' => __('فقط در منبع', 'ashko-wp'),
            'woo_only' => __('فقط در WooCommerce', 'ashko-wp'),
            'ambiguous' => __('مبهم', 'ashko-wp'),
            'drift' => __('دارای مغایرت', 'ashko-wp'),
            'quarantined' => __('قرنطینه‌شده', 'ashko-wp'),
            'source_warning' => __('دارای هشدار سراسری منبع', 'ashko-wp'),
        ) as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($value, $criteria['scope'], false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('هشدار', 'ashko-wp') . '<br><select name="warning"><option value="">' . esc_html__('همه هشدارها', 'ashko-wp') . '</option>';
        foreach ($report['warnings'] as $warning => $count) {
            echo '<option value="' . esc_attr($warning) . '" ' . selected($warning, $criteria['warning'], false) . '>' . esc_html(self::warning_label($warning) . ' (' . number_format_i18n($count) . ')') . '</option>';
        }
        echo '</select></label>';
        echo '<label>' . esc_html__('تعداد در صفحه', 'ashko-wp') . '<br><select name="per_page">';
        foreach (array(25, 50, 100) as $size) {
            echo '<option value="' . $size . '" ' . selected($size, $page['page_size'], false) . '>' . $size . '</option>';
        }
        echo '</select></label>';
        submit_button(__('اعمال فیلتر', 'ashko-wp'), 'secondary', '', false);
        echo '<a class="button button-primary" href="' . esc_url(Current_Catalog_Report::download_url($criteria)) . '">' . esc_html__('دانلود CSV همین نتیجه', 'ashko-wp') . '</a>';
        echo '</form>';

        echo '<p><strong>' . esc_html(sprintf(__('تعداد نتیجه: %d', 'ashko-wp'), $page['total'])) . '</strong></p>';
        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr>';
        foreach (array(
            __('وضعیت', 'ashko-wp'), __('نام کالا', 'ashko-wp'), __('کد کالا', 'ashko-wp'), __('سریال کالا', 'ashko-wp'),
            __('CNY منبع', 'ashko-wp'), __('وزن منبع', 'ashko-wp'), __('واحد', 'ashko-wp'), __('موجودی کل/قابل فروش', 'ashko-wp'),
            __('شناسه WooCommerce', 'ashko-wp'), __('قیمت فعلی/محاسبه‌شده IRR', 'ashko-wp'), __('مغایرت‌های مستقل', 'ashko-wp'), __('هشدارها', 'ashko-wp'),
        ) as $heading) {
            echo '<th style="white-space:nowrap">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (array() === $page['rows']) {
            echo '<tr><td colspan="12">' . esc_html__('ردیفی مطابق فیلتر پیدا نشد.', 'ashko-wp') . '</td></tr>';
        }
        foreach ($page['rows'] as $row) {
            $woo = $row['woo'];
            $projection = $row['projection'];
            $current_price = '' !== (string) ($woo['regular_price'] ?? '') ? (string) $woo['regular_price'] : '—';
            $projected_price = array_key_exists('price_irr', $projection) ? (string) $projection['price_irr'] : '—';
            $current_stock = array_key_exists('stock_quantity', $woo) && null !== $woo['stock_quantity'] ? (string) $woo['stock_quantity'] : '—';
            $projected_stock = array_key_exists('stock_quantity', $projection) ? (string) $projection['stock_quantity'] : '—';
            $drifts = array();
            foreach (array(
                'price' => __('قیمت', 'ashko-wp'),
                'stock' => __('موجودی', 'ashko-wp'),
                'weight' => __('وزن ووکامرس', 'ashko-wp'),
                'hash' => __('هش رکورد', 'ashko-wp'),
                'product_code' => __('کد کالا', 'ashko-wp'),
                'serial' => __('سریال مرجع', 'ashko-wp'),
                'cny' => __('CNY', 'ashko-wp'),
                'foreign_currency' => __('ارز خارجی', 'ashko-wp'),
                'unit' => __('واحد فروش', 'ashko-wp'),
                'source_weight' => __('متای وزن منبع', 'ashko-wp'),
                'stock_metadata' => __('متادیتای موجودی', 'ashko-wp'),
                'pricing_metadata' => __('متادیتای قیمت‌گذاری', 'ashko-wp'),
            ) as $key => $label) {
                if (!empty($row['drift'][$key])) {
                    $drifts[] = $label;
                }
            }
            echo '<tr><td><strong>' . esc_html(self::kind_label($row['kind'])) . '</strong><br><code>' . esc_html($row['resolution']) . '</code>';
            if (!empty($row['quarantined'])) {
                echo '<br><span style="color:#b32d2e;font-weight:600">' . esc_html__('قرنطینه', 'ashko-wp') . '</span>';
            }
            if (!empty($row['preserved_quarantined'])) {
                echo '<br><small>' . esc_html(sprintf(__('داده نگه‌داری‌شده از: %s', 'ashko-wp'), $row['stale_since'] ?: '—')) . '</small>';
            }
            if ('' !== (string) $row['snapshot_generated_at']) {
                echo '<br><small>' . esc_html(sprintf(__('زمان منبع: %s', 'ashko-wp'), $row['snapshot_generated_at'])) . '</small>';
            }
            echo '</td>';
            echo '<td>' . self::source_field_html($row, 'name', (string) ($woo['name'] ?? ''));
            if (isset($row['source_fields']['name'], $woo['name']) && '' !== (string) $woo['name']) {
                echo '<br><small>' . esc_html__('Woo فعلی:', 'ashko-wp') . ' ' . esc_html($woo['name']) . '</small>';
            }
            echo '</td><td><code>' . self::source_field_html($row, 'product_code') . '</code></td>';
            echo '<td>' . self::source_field_html($row, 'serial', $row['serial'] ?: implode('، ', $woo['serials'] ?? array())) . '</td>';
            echo '<td>' . self::source_field_html($row, 'foreign_price') . '<br><small>' . self::source_field_html($row, 'foreign_currency') . '</small></td><td>' . self::source_field_html($row, 'weight_grams') . '</td><td>' . self::source_field_html($row, 'unit') . '</td>';
            echo '<td>' . self::source_field_html($row, 'total_stock') . ' / ' . esc_html($projected_stock) . '<br><small>' . esc_html__('فعلی:', 'ashko-wp') . ' ' . esc_html($current_stock) . '</small></td>';
            echo '<td>' . (isset($woo['id']) ? '<a href="' . esc_url(get_edit_post_link((int) $woo['id'])) . '">' . (int) $woo['id'] . '</a>' : '—') . '</td>';
            echo '<td><span dir="ltr">' . esc_html($current_price . ' / ' . $projected_price) . '</span><br><small>' . esc_html__('قیمت نهایی منبع IRT:', 'ashko-wp') . ' ' . self::source_field_html($row, 'final_price') . '</small></td>';
            $warning_labels = array_map(array(self::class, 'warning_label'), $row['warnings']);
            echo '<td>' . esc_html($drifts ? implode('، ', $drifts) : '—');
            if (!empty($row['meta_drift'])) {
                echo '<details><summary>' . esc_html(sprintf(__('%d فیلد متادیتا', 'ashko-wp'), count($row['meta_drift']))) . '</summary><ul style="direction:ltr;text-align:left">';
                foreach ($row['meta_drift'] as $key => $change) {
                    echo '<li><code>' . esc_html($key) . '</code>: <span>' . esc_html((string) ($change['old'] ?? '')) . '</span> → <strong>' . esc_html((string) ($change['new'] ?? '')) . '</strong></li>';
                }
                echo '</ul></details>';
            }
            echo '</td><td><small>' . esc_html($warning_labels ? implode('، ', $warning_labels) : '—');
            if (!empty($row['envelope_warnings'])) {
                echo '<br><span dir="ltr">' . esc_html(implode(' | ', $row['envelope_warnings'])) . '</span>';
            }
            echo '</small></td></tr>';
        }
        echo '</tbody></table></div>';

        if ($page['pages'] > 1) {
            $base_args = array_filter(array(
                'page' => 'ashko-patris', 'tab' => 'current', 's' => $criteria['search'],
                'scope' => $criteria['scope'], 'warning' => $criteria['warning'], 'snapshot' => $criteria['snapshot'],
                'per_page' => $page['page_size'],
            ), static fn($value) => '' !== (string) $value);
            $placeholder = 999999999;
            $pagination_base = add_query_arg(
                array_merge($base_args, array('catalog_page' => $placeholder)),
                admin_url('admin.php')
            );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base' => str_replace((string) $placeholder, '%#%', $pagination_base),
                'format' => '', 'current' => $page['page'], 'total' => $page['pages'], 'type' => 'plain',
            ))) . '</div></div>';
        }
    }

    private static function current_provenance(array $provenance): void {
        $labels = array(
            'store_currency' => __('ارز پایه WooCommerce', 'ashko-wp'),
            'fx_irr_per_cny' => __('نرخ CNY به IRR', 'ashko-wp'),
            'shipping_method_id' => __('روش حمل', 'ashko-wp'),
            'shipping_price_per_kg' => __('هزینه حمل هر کیلو', 'ashko-wp'),
            'shipping_price_per_kg_currency' => __('ارز هزینه حمل', 'ashko-wp'),
            'profit_margin_percent' => __('حاشیه سود درصدی', 'ashko-wp'),
            'stock_percent' => __('درصد موجودی قابل فروش', 'ashko-wp'),
            'price_formula' => __('فرمول قیمت', 'ashko-wp'),
            'stock_formula' => __('فرمول موجودی', 'ashko-wp'),
        );
        echo '<h2>' . esc_html__('مبنای محاسبه این گزارش', 'ashko-wp') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1100px;margin-bottom:16px"><tbody>';
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $provenance)) {
                continue;
            }
            echo '<tr><th style="width:230px">' . esc_html($label) . '</th><td><code dir="ltr">' . esc_html((string) $provenance[$key]) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function current_summary(array $summary): void {
        $cards = array(
            __('کالای منبع', 'ashko-wp') => $summary['source_products'],
            __('تطبیق دقیق', 'ashko-wp') => $summary['matched'],
            __('فقط در منبع', 'ashko-wp') => $summary['source_only'],
            __('موجودی مثبت و فاقد Woo', 'ashko-wp') => $summary['positive_stock_missing_in_woocommerce'],
            __('فقط در WooCommerce', 'ashko-wp') => $summary['woo_only'],
            __('مبهم', 'ashko-wp') => $summary['ambiguous'],
            __('کد قرنطینه‌شده', 'ashko-wp') => $summary['quarantined_codes'],
            __('قرنطینه با داده قدیمی', 'ashko-wp') => $summary['preserved_quarantined'],
            __('هشدار سراسری منبع', 'ashko-wp') => $summary['envelope_warnings'],
            __('مغایرت قیمت', 'ashko-wp') => $summary['price_drift'],
            __('مغایرت موجودی', 'ashko-wp') => $summary['stock_drift'],
            __('مغایرت وزن', 'ashko-wp') => $summary['weight_drift'],
            __('مغایرت هش', 'ashko-wp') => $summary['hash_drift'],
            __('مغایرت متادیتا', 'ashko-wp') => $summary['metadata_drift'],
            __('والد متغیر کنارگذاشته‌شده', 'ashko-wp') => $summary['variable_parents_excluded'],
        );
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:1200px">';
        foreach ($cards as $label => $value) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px"><strong style="font-size:22px;display:block">' . number_format_i18n((int) $value) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';
    }

    private static function source_field_html(array $row, string $field, string $fallback = ''): string {
        if (!isset($row['source_fields'][$field])) {
            return '' !== $fallback ? esc_html($fallback) : '—';
        }
        $state = $row['source_fields'][$field];
        if ('omitted' === $state['state']) {
            return '<span style="color:#646970">' . esc_html__('فاقد داده منبع', 'ashko-wp') . '</span>';
        }
        if ('null' === $state['state']) {
            return '<span style="color:#b32d2e">' . esc_html__('null صریح منبع', 'ashko-wp') . '</span>';
        }
        $value = $state['value'];
        if (is_array($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return esc_html((string) $value);
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
        echo '<p>' . esc_html__('Ashco-WP نرخ مرجع CNY و متادیتای محصول را مستقیماً مدیریت می‌کند؛ افزونه تبدیل ارز برای فروشگاه پایه IRR لازم نیست.', 'ashko-wp') . '</p>';
        echo '<p><strong>ACF:</strong> ' . esc_html(Integration_Status::acf_available() ? __('در دسترس؛ همگام‌سازی دوطرفه فعال است.', 'ashko-wp') : __('در دسترس نیست؛ متادیتای استاندارد Ashco-WP بدون اختلال کار می‌کند.', 'ashko-wp')) . '</p>';
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
        self::input('shipping_price_per_kg', __('هزینه هوایی/اکسپرس هر کیلو', 'ashko-wp'), $settings['shipping_price_per_kg']);
        self::select(
            'shipping_price_per_kg_currency',
            __('ارز هزینه حمل هر کیلو', 'ashko-wp'),
            $settings['shipping_price_per_kg_currency'],
            array('IRR' => __('ریال ایران (IRR)', 'ashko-wp'), 'CNY' => __('یوان چین (CNY)', 'ashko-wp')),
            __('انتخاب ارز الزامی است و مبلغ حمل بر اساس همین ارز محاسبه می‌شود.', 'ashko-wp')
        );
        self::input('profit_margin_percent', __('حاشیه سود (درصد)', 'ashko-wp'), $settings['profit_margin_percent']);
        self::input('stock_percent', __('درصد موجودی ALLANBAR قابل فروش', 'ashko-wp'), $settings['stock_percent']);
        self::input('default_shipping_method', __('روش پیش‌فرض حمل', 'ashko-wp'), $settings['default_shipping_method']);
        self::checkbox('show_exact_stock', __('نمایش تعداد موجودی در سایت', 'ashko-wp'), $settings['show_exact_stock']);
        self::checkbox('keep_out_of_stock_visible', __('نمایش کالاهای ناموجود', 'ashko-wp'), $settings['keep_out_of_stock_visible']);
        echo '<tr><th>' . esc_html__('محدوده منبع مجاز (JSON)', 'ashko-wp') . '</th><td><textarea name="source_scopes" rows="6" cols="70" dir="ltr">' . esc_textarea($scopes_json) . '</textarea><p class="description">[{"id":"patris-office","dataset":"kala.db"}] — [] یعنی راه‌اندازی اولیه بدون محدودیت.</p></td></tr>';
        echo '<tr><th>' . esc_html__('کلید محرمانه REST', 'ashko-wp') . '</th><td><input type="password" readonly class="regular-text" value="' . esc_attr(Config::secret()) . '"><p class="description">Patris Export: X-Patris-Product-Sync-Secret</p></td></tr>';
        echo '<tr><th>' . esc_html__('نشانی اجرای خشک', 'ashko-wp') . '</th><td><code dir="ltr">' . esc_html(rest_url('ashko/patris/product-sync/dry-run')) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('نشانی اعمال', 'ashko-wp') . '</th><td><code dir="ltr">' . esc_html(rest_url('ashko/patris/product-sync/apply')) . '</code></td></tr>';
        echo '</tbody></table>';
        submit_button(__('ذخیره تنظیمات', 'ashko-wp'));
        echo '</form><p>' . esc_html__('اتصال به سرویس‌های خارجی به‌طور پیش‌فرض غیرفعال است و این نسخه هیچ داده‌ای را بدون پیکربندی صریح ارسال نمی‌کند.', 'ashko-wp') . '</p>';
        self::legacy_excerpt_cleanup();
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
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="ashko-patris-report-' . $run_id . '.csv"');
        $stream = fopen('php://output', 'wb');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array('product_code', 'serial', 'woo_id', 'resolution', 'changed', 'canonical_final_irt', 'canonical_final_irr', 'native_final_irt', 'final_irr', 'formula_discrepancy_irt', 'formula_discrepancy_irr', 'currency_effective_date', 'currency_effective_date_jalali', 'core_changes', 'meta_changes', 'warnings'), ',', '"', '');
        $offset = 0;
        do {
            $rows = $repository->rows($run_id, 1000, $offset);
            foreach ($rows as $row) {
                $values = array(
                    $row['product_code'], $row['serial'], $row['woo_id'], $row['resolution'], $row['changed'],
                    $row['canonical_final_irt'], $row['canonical_final_irr'], $row['native_final_irt'], $row['final_irr'],
                    $row['formula_discrepancy_irt'], $row['formula_discrepancy_irr'], $row['currency_effective_date'], $row['currency_effective_date_jalali'], $row['core_changes'], $row['meta_changes'], $row['warnings'],
                );
                fputcsv($stream, array_map(array(Current_Catalog_Report::class, 'csv_cell'), $values), ',', '"', '');
            }
            $offset += count($rows);
        } while (1000 === count($rows) && $offset < Current_Catalog_Report::MAX_CSV_ROWS);
        if ($offset >= Current_Catalog_Report::MAX_CSV_ROWS) {
            fputcsv($stream, array(Current_Catalog_Report::csv_cell(__('CSV export stopped at the safe row limit.', 'ashko-wp'))), ',', '"', '');
        }
        fclose($stream);
        exit;
    }

    public static function download_current_catalog_report(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'ashko-wp'));
        }
        check_admin_referer('ashko_download_current_catalog_report');
        $criteria = array(
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '',
            'scope' => isset($_GET['scope']) ? sanitize_key((string) $_GET['scope']) : 'all',
            'warning' => isset($_GET['warning']) ? sanitize_text_field(wp_unslash((string) $_GET['warning'])) : '',
            'snapshot' => isset($_GET['snapshot']) ? sanitize_key((string) $_GET['snapshot']) : 'applied',
        );
        if (!in_array($criteria['snapshot'], array('candidate', 'applied'), true)) {
            $criteria['snapshot'] = 'applied';
        }
        $service = new Current_Catalog_Report();
        $report = $service->build(null, null, $criteria['snapshot']);
        if (is_wp_error($report)) {
            wp_die(esc_html($report->get_error_message()));
        }
        $rows = $service->filtered_rows($report, $criteria);
        if (count($rows) > Current_Catalog_Report::MAX_CSV_ROWS) {
            wp_die(esc_html(sprintf(
                __('نتیجه CSV از سقف امن %d ردیف بیشتر است؛ فیلتر محدودتری انتخاب کنید.', 'ashko-wp'),
                Current_Catalog_Report::MAX_CSV_ROWS
            )));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="ashko-current-catalog-' . gmdate('Ymd-His') . '.csv"');
        $stream = fopen('php://output', 'wb');
        if (false === $stream) {
            wp_die(esc_html__('خروجی CSV باز نشد.', 'ashko-wp'));
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $headers = array(
            'snapshot_kind', 'report_generated_at', 'staged_at', 'candidate', 'kind', 'resolution', 'source_id', 'dataset',
            'snapshot_generated_at', 'source_received_at', 'quarantined', 'preserved_quarantined', 'stale_since',
            'envelope_warnings', 'product_code_state', 'product_code',
            'name_state', 'name', 'serial_state', 'serial', 'foreign_currency_state', 'foreign_currency',
            'cny_state', 'cny', 'weight_state', 'weight_grams', 'unit_state', 'unit',
            'stock_state', 'total_stock', 'woo_id', 'woo_name', 'woo_serials', 'woo_regular_price_irr',
            'source_final_price_state', 'source_final_price_irt', 'projected_price_irr', 'woo_stock', 'projected_stock',
            'woo_weight', 'projected_weight', 'source_record_hash_state', 'source_record_hash',
            'store_currency', 'fx_irr_per_cny', 'shipping_method_id', 'shipping_price_per_kg', 'shipping_price_per_kg_currency',
            'profit_margin_percent', 'stock_percent', 'price_formula', 'stock_formula',
            'woo_product_code_meta', 'expected_product_code_meta', 'woo_canonical_serial_meta', 'expected_canonical_serial_meta',
            'woo_cny_meta', 'expected_cny_meta', 'woo_foreign_currency_meta', 'expected_foreign_currency_meta',
            'woo_unit_meta', 'expected_unit_meta', 'woo_managed_meta_json', 'expected_managed_meta_json', 'meta_changes_json',
            'price_drift', 'stock_drift', 'weight_drift', 'hash_drift', 'product_code_drift', 'serial_drift',
            'cny_drift', 'foreign_currency_drift', 'unit_drift', 'source_weight_drift', 'stock_metadata_drift',
            'pricing_metadata_drift', 'metadata_drift', 'warnings',
        );
        fputcsv($stream, $headers, ',', '"', '');
        foreach ($rows as $row) {
            $product_code = self::csv_source_field($row, 'product_code');
            $name = self::csv_source_field($row, 'name');
            $serial = self::csv_source_field($row, 'serial');
            $foreign_currency = self::csv_source_field($row, 'foreign_currency');
            $cny = self::csv_source_field($row, 'foreign_price');
            $weight = self::csv_source_field($row, 'weight_grams');
            $unit = self::csv_source_field($row, 'unit');
            $stock = self::csv_source_field($row, 'total_stock');
            $source_final = self::csv_source_field($row, 'final_price');
            $source_hash = self::csv_source_field($row, 'record_hash');
            $woo = $row['woo'];
            $projection = $row['projection'];
            $woo_meta = is_array($woo['managed_meta'] ?? null) ? $woo['managed_meta'] : array();
            $expected_meta = is_array($projection['managed_meta'] ?? null) ? $projection['managed_meta'] : array();
            $provenance = $report['provenance'];
            $values = array(
                $report['snapshot_kind'], $report['generated_at'], $report['staged_at'], $row['candidate'],
                $row['kind'], $row['resolution'], $row['source_id'], $row['dataset'], $row['snapshot_generated_at'],
                $row['source_received_at'], $row['quarantined'], $row['preserved_quarantined'], $row['stale_since'],
                implode('|', $row['envelope_warnings']),
                $product_code['state'], $product_code['value'], $name['state'], $name['value'],
                $serial['state'], $serial['value'], $foreign_currency['state'], $foreign_currency['value'],
                $cny['state'], $cny['value'],
                $weight['state'], $weight['value'], $unit['state'], $unit['value'], $stock['state'], $stock['value'],
                $woo['id'] ?? '', $woo['name'] ?? '', implode('|', $woo['serials'] ?? array()),
                $woo['regular_price'] ?? '', $source_final['state'], $source_final['value'],
                $projection['price_irr'] ?? '', $woo['stock_quantity'] ?? '',
                $projection['stock_quantity'] ?? '', $woo['weight'] ?? '', $projection['weight'] ?? '',
                $source_hash['state'], $source_hash['value'],
                $provenance['store_currency'] ?? '', $provenance['fx_irr_per_cny'] ?? '', $provenance['shipping_method_id'] ?? '',
                $provenance['shipping_price_per_kg'] ?? '', $provenance['shipping_price_per_kg_currency'] ?? '',
                $provenance['profit_margin_percent'] ?? '', $provenance['stock_percent'] ?? '',
                $provenance['price_formula'] ?? '', $provenance['stock_formula'] ?? '',
                $woo_meta['_ashko_patris_product_code'] ?? '', $expected_meta['_ashko_patris_product_code'] ?? '',
                $woo_meta[Config::OWN_SERIAL_META] ?? '', $expected_meta[Config::OWN_SERIAL_META] ?? '',
                $woo_meta['_ashko_patris_cny'] ?? '', $expected_meta['_ashko_patris_cny'] ?? '',
                $woo_meta['_ashko_patris_foreign_currency'] ?? '', $expected_meta['_ashko_patris_foreign_currency'] ?? '',
                $woo_meta['_ashko_patris_unit'] ?? '', $expected_meta['_ashko_patris_unit'] ?? '',
                $woo_meta, $expected_meta, $row['meta_drift'],
                !empty($row['drift']['price']), !empty($row['drift']['stock']), !empty($row['drift']['weight']),
                !empty($row['drift']['hash']), !empty($row['drift']['product_code']), !empty($row['drift']['serial']),
                !empty($row['drift']['cny']), !empty($row['drift']['foreign_currency']), !empty($row['drift']['unit']),
                !empty($row['drift']['source_weight']), !empty($row['drift']['stock_metadata']),
                !empty($row['drift']['pricing_metadata']), !empty($row['drift']['metadata']), implode('|', $row['warnings']),
            );
            fputcsv(
                $stream,
                array_map(array(Current_Catalog_Report::class, 'csv_cell'), $values),
                ',',
                '"',
                ''
            );
        }
        fclose($stream);
        exit;
    }

    public static function cleanup_legacy_excerpts(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('دسترسی کافی ندارید.', 'ashko-wp'));
        }
        check_admin_referer('ashko_cleanup_legacy_excerpts');
        $result = Product_Presentation::cleanup_legacy_excerpts(true);
        $url = admin_url(
            'admin.php?page=ashko-patris&tab=settings'
            . '&cleanup_matched=' . (int) $result['matched']
            . '&cleanup_cleared=' . (int) $result['cleared']
            . '&cleanup_errors=' . count($result['errors'])
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function legacy_excerpt_cleanup(): void {
        $dry_run = Product_Presentation::cleanup_legacy_excerpts(false);
        echo '<hr><h2>' . esc_html__('پاک‌سازی توضیح کوتاه قدیمی', 'ashko-wp') . '</h2>';
        echo '<p>' . esc_html__(
            'این ابزار فقط جملهٔ دقیق و ماشینیِ واردشده را پاک می‌کند. توضیح‌های نوشته‌شده توسط فروشنده بدون تغییر می‌مانند.',
            'ashko-wp'
        ) . '</p>';
        if (isset($_GET['cleanup_cleared'])) {
            $cleared = absint($_GET['cleanup_cleared']);
            $errors = isset($_GET['cleanup_errors']) ? absint($_GET['cleanup_errors']) : 0;
            echo '<div class="notice notice-' . ($errors ? 'warning' : 'success') . ' inline"><p>'
                . esc_html(sprintf(
                    __('%1$d توضیح کوتاه قدیمی پاک شد؛ تعداد خطا: %2$d.', 'ashko-wp'),
                    $cleared,
                    $errors
                ))
                . '</p></div>';
        }
        echo '<p><strong>' . esc_html(sprintf(
            __('نتیجهٔ اجرای آزمایشی: %d توضیح کوتاه منطبق است.', 'ashko-wp'),
            (int) $dry_run['matched']
        )) . '</strong></p>';
        if (0 === (int) $dry_run['matched']) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ashko_cleanup_legacy_excerpts">';
        wp_nonce_field('ashko_cleanup_legacy_excerpts');
        submit_button(__('پاک‌سازی موارد منطبق', 'ashko-wp'), 'secondary', 'submit', false, array(
            'onclick' => "return confirm('" . esc_js(__('فقط توضیح‌های ماشینیِ دقیق پاک شوند؟', 'ashko-wp')) . "');",
        ));
        echo '</form>';
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

    private static function select(string $name, string $label, string $value, array $options, string $description = ''): void {
        echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><select required dir="ltr" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $option_value => $option_label) {
            echo '<option value="' . esc_attr($option_value) . '" ' . selected($option_value, $value, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select>';
        if ('' !== $description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function csv_source_field(array $row, string $field): array {
        $source_field = $row['source_fields'][$field] ?? array('state' => 'not_applicable');
        $value = array_key_exists('value', $source_field) ? $source_field['value'] : '';
        return array('state' => (string) $source_field['state'], 'value' => $value);
    }

    private static function warning_label(string $warning): string {
        $labels = array(
            'missing_cny' => __('CNY موجود نیست یا ارز آن صحیح نیست', 'ashko-wp'),
            'missing_weight' => __('وزن موجود نیست', 'ashko-wp'),
            'missing_unit' => __('واحد فروش موجود نیست', 'ashko-wp'),
            'missing_serial' => __('سریال کالا موجود نیست', 'ashko-wp'),
            'missing_shipping' => __('تنظیمات مبلغ یا ارز حمل کامل نیست', 'ashko-wp'),
            'missing_margin' => __('حاشیه سود موجود نیست', 'ashko-wp'),
            'missing_fx' => __('نرخ تبدیل CNY موجود نیست', 'ashko-wp'),
            'negative_stock' => __('موجودی منبع منفی است', 'ashko-wp'),
            'missing_final_price' => __('قیمت نهایی قابل محاسبه نیست', 'ashko-wp'),
            'formula_discrepancy' => __('مغایرت فرمول قیمت', 'ashko-wp'),
            'unmatched_woocommerce' => __('کالای متناظر در WooCommerce پیدا نشد', 'ashko-wp'),
            'positive_stock_missing_in_woocommerce' => __('موجودی منبع مثبت است اما کالای WooCommerce پیدا نشد', 'ashko-wp'),
            'duplicate_source_serial' => __('سریال در منبع تکراری است', 'ashko-wp'),
            'duplicate_woocommerce_serial' => __('سریال در WooCommerce تکراری است', 'ashko-wp'),
            'serial_lookup_failed' => __('جست‌وجوی دقیق سریال ناموفق بود', 'ashko-wp'),
            'variable_parent_excluded' => __('والد کالای متغیر از تطبیق کنار گذاشته شد', 'ashko-wp'),
            'woocommerce_product_unavailable' => __('کالای WooCommerce قابل بارگذاری نیست', 'ashko-wp'),
            'missing_source_product' => __('کالا فقط در WooCommerce وجود دارد', 'ashko-wp'),
            'missing_woocommerce_serial' => __('کالای WooCommerce سریال ندارد', 'ashko-wp'),
            'price_drift' => __('مغایرت قیمت', 'ashko-wp'),
            'stock_drift' => __('مغایرت موجودی', 'ashko-wp'),
            'weight_drift' => __('مغایرت وزن', 'ashko-wp'),
            'hash_drift' => __('مغایرت هش رکورد', 'ashko-wp'),
            'product_code_drift' => __('مغایرت کد کالا', 'ashko-wp'),
            'serial_drift' => __('مغایرت سریال مرجع', 'ashko-wp'),
            'cny_drift' => __('مغایرت CNY', 'ashko-wp'),
            'foreign_currency_drift' => __('مغایرت ارز خارجی', 'ashko-wp'),
            'unit_drift' => __('مغایرت واحد فروش', 'ashko-wp'),
            'source_weight_drift' => __('مغایرت متای وزن منبع', 'ashko-wp'),
            'stock_metadata_drift' => __('مغایرت متادیتای موجودی', 'ashko-wp'),
            'pricing_metadata_drift' => __('مغایرت متادیتای قیمت‌گذاری', 'ashko-wp'),
            'metadata_drift' => __('مغایرت در متادیتای مدیریت‌شده', 'ashko-wp'),
            'quarantined_source_record' => __('کد منبع قرنطینه شده است', 'ashko-wp'),
            'quarantined_without_product' => __('کد قرنطینه‌شده داده کالای معتبر ندارد', 'ashko-wp'),
            'quarantined_preserved_stale' => __('داده قدیمی کالا به علت قرنطینه نگه‌داری شده است', 'ashko-wp'),
            'source_envelope_warning' => __('هشدار سراسری در تصویر منبع وجود دارد', 'ashko-wp'),
        );
        if (isset($labels[$warning])) {
            return $labels[$warning];
        }
        if (str_starts_with($warning, 'source_omitted_')) {
            return sprintf(__('فیلد منبع حذف شده است: %s', 'ashko-wp'), self::source_field_label(substr($warning, strlen('source_omitted_'))));
        }
        if (str_starts_with($warning, 'source_explicit_null_')) {
            return sprintf(__('فیلد منبع دارای null صریح است: %s', 'ashko-wp'), self::source_field_label(substr($warning, strlen('source_explicit_null_'))));
        }
        if (str_starts_with($warning, 'source:')) {
            return sprintf(__('هشدار منبع: %s', 'ashko-wp'), substr($warning, strlen('source:')));
        }
        return $warning;
    }

    private static function source_field_label(string $field): string {
        return array(
            'serial' => __('سریال کالا', 'ashko-wp'),
            'foreign_currency' => __('ارز قیمت خارجی', 'ashko-wp'),
            'foreign_price' => __('قیمت CNY', 'ashko-wp'),
            'weight_grams' => __('وزن', 'ashko-wp'),
            'unit' => __('واحد فروش', 'ashko-wp'),
            'total_stock' => __('موجودی کل', 'ashko-wp'),
        )[$field] ?? $field;
    }

    private static function kind_label(string $kind): string {
        return array(
            'matched' => __('تطبیق دقیق', 'ashko-wp'),
            'source_only' => __('فقط در منبع', 'ashko-wp'),
            'woo_only' => __('فقط در WooCommerce', 'ashko-wp'),
            'ambiguous' => __('مبهم', 'ashko-wp'),
            'quarantined' => __('قرنطینه‌شده در منبع', 'ashko-wp'),
            'source_warning' => __('هشدار سراسری منبع', 'ashko-wp'),
        )[$kind] ?? $kind;
    }
}
