<?php
namespace Ashko\Patris;

final class WooCommerce_Currency_Status {
    public const REQUIRED_CURRENCY = 'IRR';
    public const INCOMPATIBLE_WARNING = 'Ashco stores WooCommerce prices in IRR; Patris contract IRT values are converted by multiplying by 10.';
    public const STRUCTURED_PRICE_PRIORITY = 200;

    private static ?self $instance = null;

    /**
     * Repair machine-readable prices after the Persian WooCommerce integration
     * has applied its priority-100 fallback conversion.
     *
     * That integration treats an empty internal currency cache as IRT and
     * multiplies Schema.org/OpenGraph prices by ten even when WooCommerce
     * already stores native IRR. Read the raw Woo option and edit-context
     * product values so this compatibility layer never changes storefront,
     * cart, order, REST, payment, or persisted prices.
     */
    public static function register(): void {
        add_filter(
            'woocommerce_structured_data_product_offer',
            array(self::class, 'normalize_woocommerce_offer'),
            self::STRUCTURED_PRICE_PRIORITY,
            2
        );
        add_filter(
            'rank_math/snippet/rich_snippet_product_entity',
            array(self::class, 'normalize_rank_math_product_entity'),
            self::STRUCTURED_PRICE_PRIORITY,
            1
        );
        add_filter(
            'rank_math/opengraph/facebook/product_price_amount',
            array(self::class, 'normalize_rank_math_opengraph_amount'),
            self::STRUCTURED_PRICE_PRIORITY,
            1
        );
        add_filter(
            'rank_math/opengraph/facebook/product_price_currency',
            array(self::class, 'normalize_rank_math_opengraph_currency'),
            self::STRUCTURED_PRICE_PRIORITY,
            1
        );
    }

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_status(): array {
        $code = function_exists('get_woocommerce_currency')
            ? strtoupper((string) get_woocommerce_currency())
            : strtoupper((string) get_option('woocommerce_currency', ''));
        return array(
            'code' => $code,
            'compatible' => self::REQUIRED_CURRENCY === $code,
            'required' => self::REQUIRED_CURRENCY,
        );
    }

    public static function normalize_woocommerce_offer(array $offer, $product): array {
        if (!self::uses_native_irr_storage()) {
            return $offer;
        }
        return self::normalize_offer($offer, $product);
    }

    public static function normalize_rank_math_product_entity($entity) {
        if (!is_array($entity) || !self::uses_native_irr_storage()) {
            return $entity;
        }

        $product = self::current_product();
        if (null === $product || !isset($entity['offers']) || !is_array($entity['offers'])) {
            return $entity;
        }

        $entity['offers'] = self::normalize_offer_collection($entity['offers'], $product);
        return $entity;
    }

    public static function normalize_rank_math_opengraph_amount($amount) {
        if (!self::uses_native_irr_storage()) {
            return $amount;
        }

        $product = self::current_product();
        $price = self::raw_product_price($product, 'price');
        return null === $price ? $amount : $price;
    }

    public static function normalize_rank_math_opengraph_currency($currency) {
        return self::uses_native_irr_storage() ? self::REQUIRED_CURRENCY : $currency;
    }

    /**
     * The option is deliberately read directly. A presentation switcher or a
     * third-party filter must not make native IRR storage look like IRT.
     */
    private static function uses_native_irr_storage(): bool {
        return self::REQUIRED_CURRENCY === strtoupper(trim((string) get_option('woocommerce_currency', '')));
    }

    private static function normalize_offer_collection(array $offers, $product): array {
        if (self::is_offer($offers)) {
            return self::normalize_offer($offers, $product);
        }

        foreach ($offers as $key => $offer) {
            if (is_array($offer)) {
                $offers[$key] = self::normalize_offer($offer, self::offer_product($product, $offer));
            }
        }
        return $offers;
    }

    private static function normalize_offer(array $offer, $product): array {
        if (!is_object($product)) {
            return $offer;
        }

        $active = self::raw_product_price($product, 'price');
        $regular = self::raw_product_price($product, 'regular_price');
        if (null === $regular) {
            $regular = $active;
        }

        $range = self::raw_price_range($product);
        if (null === $active && null === $range['low'] && null === $range['high']) {
            return $offer;
        }
        if (array_key_exists('price', $offer) && null !== $active) {
            $offer['price'] = $active;
        }
        if (array_key_exists('lowPrice', $offer) && null !== $range['low']) {
            $offer['lowPrice'] = $range['low'];
        }
        if (array_key_exists('highPrice', $offer) && null !== $range['high']) {
            $offer['highPrice'] = $range['high'];
        }

        if (isset($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
            $offer['priceSpecification'] = self::normalize_price_specifications(
                $offer['priceSpecification'],
                $active,
                $regular
            );
        }

        if (array_key_exists('priceCurrency', $offer) && self::has_price_value($offer)) {
            $offer['priceCurrency'] = self::REQUIRED_CURRENCY;
        }
        return $offer;
    }

    private static function normalize_price_specifications(
        array $specifications,
        ?string $active,
        ?string $regular
    ): array {
        if (self::is_price_specification($specifications)) {
            return self::normalize_price_specification($specifications, $active, $regular);
        }

        foreach ($specifications as $key => $specification) {
            if (is_array($specification)) {
                $specifications[$key] = self::normalize_price_specification(
                    $specification,
                    $active,
                    $regular
                );
            }
        }
        return $specifications;
    }

    private static function normalize_price_specification(
        array $specification,
        ?string $active,
        ?string $regular
    ): array {
        $price_type = (string) ($specification['priceType'] ?? '');
        $price = false !== stripos($price_type, 'ListPrice') ? $regular : $active;
        if (array_key_exists('price', $specification) && null !== $price) {
            $specification['price'] = $price;
        }
        if (array_key_exists('priceCurrency', $specification) && array_key_exists('price', $specification)) {
            $specification['priceCurrency'] = self::REQUIRED_CURRENCY;
        }
        return $specification;
    }

    private static function raw_price_range($product): array {
        $prices = array();
        if (is_object($product) && method_exists($product, 'get_children') && function_exists('wc_get_product')) {
            foreach ((array) $product->get_children() as $child_id) {
                $child = wc_get_product((int) $child_id);
                $price = self::raw_product_price($child, 'price');
                if (null !== $price) {
                    $prices[] = $price;
                }
            }
        }

        if (array() === $prices) {
            $price = self::raw_product_price($product, 'price');
            if (null !== $price) {
                $prices[] = $price;
            }
        }
        if (array() === $prices) {
            return array('low' => null, 'high' => null);
        }

        usort($prices, array(self::class, 'compare_decimal_strings'));
        return array(
            'low' => $prices[0],
            'high' => $prices[count($prices) - 1],
        );
    }

    private static function offer_product($product, array $offer) {
        if (
            !isset($offer['sku'])
            || !is_object($product)
            || !method_exists($product, 'get_children')
            || !function_exists('wc_get_product')
        ) {
            return $product;
        }

        $sku = trim((string) $offer['sku']);
        if ('' === $sku) {
            return $product;
        }
        foreach ((array) $product->get_children() as $child_id) {
            $child = wc_get_product((int) $child_id);
            if (
                is_object($child)
                && method_exists($child, 'get_sku')
                && hash_equals($sku, trim((string) $child->get_sku('edit')))
            ) {
                return $child;
            }
        }
        return $product;
    }

    private static function raw_product_price($product, string $kind): ?string {
        $method = 'get_' . $kind;
        if (!is_object($product) || !method_exists($product, $method)) {
            return null;
        }
        return self::normalize_decimal($product->{$method}('edit'));
    }

    private static function normalize_decimal($value): ?string {
        if (null === $value || '' === $value) {
            return null;
        }

        $value = trim((string) $value);
        if ('' === $value || 1 !== preg_match('/\A\d+(?:\.\d+)?\z/', $value)) {
            return null;
        }

        $parts = explode('.', $value, 2);
        $integer = ltrim($parts[0], '0');
        $integer = '' === $integer ? '0' : $integer;
        if (!isset($parts[1])) {
            return $integer;
        }

        $fraction = rtrim($parts[1], '0');
        return '' === $fraction ? $integer : $integer . '.' . $fraction;
    }

    private static function compare_decimal_strings(string $left, string $right): int {
        $left_parts = explode('.', $left, 2);
        $right_parts = explode('.', $right, 2);
        $integer_compare = strlen($left_parts[0]) <=> strlen($right_parts[0]);
        if (0 !== $integer_compare) {
            return $integer_compare;
        }
        $integer_compare = strcmp($left_parts[0], $right_parts[0]);
        if (0 !== $integer_compare) {
            return $integer_compare;
        }

        $left_fraction = $left_parts[1] ?? '';
        $right_fraction = $right_parts[1] ?? '';
        $length = max(strlen($left_fraction), strlen($right_fraction));
        return strcmp(
            str_pad($left_fraction, $length, '0'),
            str_pad($right_fraction, $length, '0')
        );
    }

    private static function is_offer(array $value): bool {
        return isset($value['@type'])
            || array_key_exists('price', $value)
            || array_key_exists('lowPrice', $value)
            || array_key_exists('highPrice', $value);
    }

    private static function is_price_specification(array $value): bool {
        return isset($value['@type']) || array_key_exists('price', $value);
    }

    private static function has_price_value(array $offer): bool {
        return array_key_exists('price', $offer)
            || array_key_exists('lowPrice', $offer)
            || array_key_exists('highPrice', $offer);
    }

    private static function current_product() {
        if (
            !function_exists('get_queried_object_id')
            || !function_exists('get_post_type')
            || !function_exists('wc_get_product')
        ) {
            return null;
        }

        $queried_id = (int) get_queried_object_id();
        if ($queried_id <= 0 || 'product' !== get_post_type($queried_id)) {
            return null;
        }

        $product = wc_get_product($queried_id);
        return is_object($product)
            && method_exists($product, 'get_id')
            && $queried_id === (int) $product->get_id()
                ? $product
                : null;
    }
}
