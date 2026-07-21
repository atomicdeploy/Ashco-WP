<?php
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('ASHKO_WP_FILE', dirname(__DIR__) . '/ashko-wp.php');
define('ASHKO_WP_DIR', dirname(__DIR__) . '/');
define('ASHKO_WP_VERSION', '1.1.0');

$GLOBALS['ashko_test_options'] = array(
    'woocommerce_currency' => 'IRR',
    'woocommerce_weight_unit' => 'g',
);
$GLOBALS['ashko_test_serial_rows'] = array();
$GLOBALS['ashko_test_products'] = array();
$GLOBALS['ashko_test_currency'] = 'IRR';
$GLOBALS['ashko_test_hooks'] = array();
$GLOBALS['ashko_test_current_actions'] = array();
$GLOBALS['ashko_test_current_user_can'] = false;
$GLOBALS['ashko_test_inline_scripts'] = array();
$GLOBALS['ashko_test_post_ids'] = array();
$GLOBALS['ashko_test_product_categories'] = array();
$GLOBALS['ashko_test_raw_post_excerpts'] = array();
$GLOBALS['ashko_test_get_posts_calls'] = array();
$GLOBALS['ashko_test_excerpt_prefilter_calls'] = 0;
$GLOBALS['ashko_test_request_context'] = array();
$GLOBALS['ashko_test_enqueued_styles'] = array();
$GLOBALS['ashko_test_enqueued_scripts'] = array();
$GLOBALS['ashko_test_localized_scripts'] = array();

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

class WP_REST_Request {
    private array $headers;

    public function __construct(array $headers = array()) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function get_header($name) {
        return $this->headers[strtolower((string) $name)] ?? '';
    }
}

class WP_REST_Response {
    private $data;
    private int $status;

    public function __construct($data = null, int $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data() { return $this->data; }
    public function get_status(): int { return $this->status; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value, $domain = null) { return $value; }
function current_user_can($capability) { return (bool) $GLOBALS['ashko_test_current_user_can']; }
function get_option($key, $default = false) { return $GLOBALS['ashko_test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['ashko_test_options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = null) { if (!array_key_exists($key, $GLOBALS['ashko_test_options'])) { $GLOBALS['ashko_test_options'][$key] = $value; } return true; }
function wp_generate_password($length = 12) { return str_repeat('x', $length); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function esc_url_raw($value) { return (string) $value; }
function wp_unslash($value) { return $value; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function current_time($type, $gmt = false) { return 'mysql' === $type ? '2026-07-20 12:00:00' : time(); }
function maybe_serialize($value) { return serialize($value); }
function maybe_unserialize($value) { $decoded = @unserialize($value); return false === $decoded && 'b:0;' !== $value ? $value : $decoded; }
function wp_cache_delete($key, $group = '') { return true; }
function get_woocommerce_currency() { return $GLOBALS['ashko_test_currency']; }
function wp_raise_memory_limit($context = 'admin') { return ini_set('memory_limit', '512M'); }
function do_action($name, ...$args) {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['ashko_test_hooks'][] = array('type' => 'filter', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args);
    return true;
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['ashko_test_hooks'][] = array('type' => 'action', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args);
    return true;
}
function doing_action($hook = null) { return null === $hook ? !empty($GLOBALS['ashko_test_current_actions']) : !empty($GLOBALS['ashko_test_current_actions'][$hook]); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($value, $domain = null) { return esc_html($value); }
function esc_attr__($value, $domain = null) { return esc_attr($value); }
function wp_strip_all_tags($value, $remove_breaks = false) {
    $value = strip_tags((string) $value);
    return $remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $value) : $value;
}
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals, '.', ','); }
function wp_kses_post($value) {
    // The focused test double mirrors the relevant WordPress post allowlist:
    // bdi is stripped, while span and its global class/dir attributes survive.
    return preg_replace('#</?bdi\\b[^>]*>#i', '', (string) $value);
}
function wp_add_inline_script($handle, $data, $position = 'after') {
    $GLOBALS['ashko_test_inline_scripts'][] = array('handle' => $handle, 'data' => $data, 'position' => $position);
    return true;
}
function is_admin() { return !empty($GLOBALS['ashko_test_request_context']['admin']); }
function wp_doing_ajax() { return !empty($GLOBALS['ashko_test_request_context']['ajax']); }
function wp_is_json_request() { return !empty($GLOBALS['ashko_test_request_context']['json']); }
function is_feed() { return !empty($GLOBALS['ashko_test_request_context']['feed']); }
function is_embed() { return !empty($GLOBALS['ashko_test_request_context']['embed']); }
function is_cart() { return !empty($GLOBALS['ashko_test_request_context']['cart']); }
function is_checkout() { return !empty($GLOBALS['ashko_test_request_context']['checkout']); }
function is_account_page() { return !empty($GLOBALS['ashko_test_request_context']['account']); }
function is_wc_endpoint_url() { return !empty($GLOBALS['ashko_test_request_context']['wc_endpoint']); }
function plugins_url($path = '', $plugin = '') { return 'https://example.test/wp-content/plugins/ashko-wp/' . ltrim((string) $path, '/'); }
function wp_enqueue_style($handle, $src = '', $deps = array(), $version = false, $media = 'all') {
    $GLOBALS['ashko_test_enqueued_styles'][] = compact('handle', 'src', 'deps', 'version', 'media');
}
function wp_enqueue_script($handle, $src = '', $deps = array(), $version = false, $args = array()) {
    $GLOBALS['ashko_test_enqueued_scripts'][] = compact('handle', 'src', 'deps', 'version', 'args');
}
function wp_localize_script($handle, $object_name, $data) {
    $GLOBALS['ashko_test_localized_scripts'][] = compact('handle', 'object_name', 'data');
    return true;
}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function wp_nonce_url($url, $action = -1, $name = '_wpnonce') { return (string) $url . '&_wpnonce=test'; }
function get_post_type($post_id) { return $GLOBALS['ashko_test_post_types'][(int) $post_id] ?? 'product'; }
function get_post_meta($post_id, $key, $single = false) {
    $product = $GLOBALS['ashko_test_products'][(int) $post_id] ?? null;
    return $product ? $product->get_meta($key, $single, 'edit') : '';
}
function delete_post_meta($post_id, $key, $value = '') {
    $product = $GLOBALS['ashko_test_products'][(int) $post_id] ?? null;
    return $product ? $product->delete_meta_data_exact($key, $value) : false;
}
function wc_get_product($post_id) { return $GLOBALS['ashko_test_products'][(int) $post_id] ?? null; }
function get_queried_object_id() { return (int) ($GLOBALS['ashko_test_queried_object_id'] ?? 0); }
function get_posts($args = array()) {
    $GLOBALS['ashko_test_get_posts_args'] = $args;
    $GLOBALS['ashko_test_get_posts_calls'][] = $args;
    $ids = $GLOBALS['ashko_test_post_ids'];
    if (isset($args['post__in']) && is_array($args['post__in'])) {
        $ids = array_values(array_filter($ids, static function($id) use ($args) {
            return in_array((int) $id, array_map('intval', $args['post__in']), true);
        }));
    }
    return $ids;
}
function wp_get_post_terms($post_id, $taxonomy, $args = array()) {
    return $GLOBALS['ashko_test_product_categories'][(int) $post_id] ?? array();
}

final class Ashko_Test_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare($query, ...$args) { return array('query' => $query, 'args' => $args); }
    public function get_results($prepared, $output = ARRAY_A) {
        $query = $prepared['query'] ?? '';
        if (false === strpos($query, 'ashko_exact_serial_catalog')) {
            return array();
        }
        $args = $prepared['args'];
        $keys = array_slice($args, 0, 2);
        $serials = array_slice($args, 2);
        return array_values(array_filter($GLOBALS['ashko_test_serial_rows'], static function($row) use ($keys, $serials) {
            return in_array((string) $row['meta_key'], $keys, true) && in_array((string) $row['meta_value'], $serials, true);
        }));
    }
    public function get_col($prepared) {
        $query = is_array($prepared) ? ($prepared['query'] ?? '') : (string) $prepared;
        if (false === strpos($query, 'ashko_nonempty_product_excerpts')) {
            return array();
        }
        ++$GLOBALS['ashko_test_excerpt_prefilter_calls'];
        $ids = array();
        foreach ($GLOBALS['ashko_test_raw_post_excerpts'] as $id => $excerpt) {
            if ('' !== (string) $excerpt) {
                $ids[] = (int) $id;
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
$GLOBALS['wpdb'] = new Ashko_Test_WPDB();

final class Ashko_Test_Product {
    private int $id;
    private array $core;
    private array $meta;
    public int $save_count = 0;
    public function __construct(int $id, array $core = array(), array $meta = array()) {
        $this->id = $id;
        $this->core = array_merge(array(
            'regular_price' => '', 'price' => '', 'sale_price' => '', 'weight' => '',
            'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock',
        ), $core);
        $this->meta = $meta;
        $GLOBALS['ashko_test_products'][$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_regular_price($context = 'view') { return $this->core['regular_price']; }
    public function get_price($context = 'view') { return $this->core['price']; }
    public function get_sale_price($context = 'view') { return $this->core['sale_price']; }
    public function get_weight($context = 'view') { return $this->core['weight']; }
    public function get_manage_stock($context = 'view') { return $this->core['manage_stock']; }
    public function get_stock_quantity($context = 'view') { return $this->core['stock_quantity']; }
    public function get_stock_status($context = 'view') { return $this->core['stock_status']; }
    public function get_meta($key, $single = true, $context = 'view') { return $this->meta[$key] ?? ''; }
    public function set_regular_price($value) { $this->core['regular_price'] = (string) $value; }
    public function set_price($value) { $this->core['price'] = (string) $value; }
    public function set_sale_price($value) { $this->core['sale_price'] = (string) $value; }
    public function set_weight($value) { $this->core['weight'] = (string) $value; }
    public function set_manage_stock($value) { $this->core['manage_stock'] = (bool) $value; }
    public function set_stock_quantity($value) { $this->core['stock_quantity'] = (int) $value; }
    public function set_stock_status($value) { $this->core['stock_status'] = (string) $value; }
    public function update_meta_data($key, $value) { $this->meta[$key] = (string) $value; }
    public function delete_meta_data_exact($key, $value = '') {
        if (!array_key_exists($key, $this->meta)) { return false; }
        if ('' !== $value && (string) $this->meta[$key] !== (string) $value) { return false; }
        unset($this->meta[$key]);
        return true;
    }
    public function save() { $this->save_count++; $GLOBALS['ashko_test_products'][$this->id] = $this; return $this->id; }
}

$root = dirname(__DIR__);
require_once $root . '/includes/class-config.php';
require_once $root . '/includes/class-woocommerce-currency-status.php';
require_once $root . '/includes/class-memory-guard.php';
require_once $root . '/includes/class-decimal-calculator.php';
require_once $root . '/includes/class-serial-resolver.php';
require_once $root . '/includes/class-product-applicator.php';
require_once $root . '/includes/class-product-commerce.php';
require_once $root . '/includes/class-product-presentation.php';
require_once $root . '/includes/class-storefront-price-display.php';
require_once $root . '/includes/class-jalali.php';
require_once $root . '/includes/class-logger.php';
require_once $root . '/includes/class-product-sync-receiver.php';
require_once $root . '/includes/class-frontend-stock.php';
