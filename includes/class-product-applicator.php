<?php
namespace Ashko\Patris;

use RuntimeException;

final class Product_Applicator {
    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function plan($product, array $data): array {
        $warnings = $this->warning_codes($data);
        $desired = $this->desired_values($data);
        $publication_safety = $this->publication_safety($product, $data, $desired['calculation']);
        $warnings = array_merge($warnings, $publication_safety['warnings']);
        if ($publication_safety['should_draft']) {
            $desired['core']['status'] = 'draft';
            $desired['meta']['_ashko_patris_publication_safety'] = 'draft_incomplete';
        }
        $core_changes = array();
        $meta_changes = array();

        foreach ($desired['core'] as $field => $new_value) {
            $old_value = $this->read_core($product, $field);
            if (!$this->same($old_value, $new_value, $field)) {
                $core_changes[$field] = array('old' => $old_value, 'new' => $new_value);
            }
        }
        foreach ($desired['meta'] as $key => $new_value) {
            $old_value = (string) $product->get_meta($key, true, 'edit');
            if ((string) $new_value !== $old_value) {
                $meta_changes[$key] = array('old' => $old_value, 'new' => (string) $new_value);
            }
        }

        $warnings = array_values(array_unique($warnings));
        sort($warnings, SORT_STRING);

        return array(
            'changed' => array() !== $core_changes || array() !== $meta_changes,
            'core_changes' => $core_changes,
            'meta_changes' => $meta_changes,
            'warnings' => $warnings,
            'calculation' => $desired['calculation'],
            'publication_safety' => $publication_safety,
        );
    }

    public function analyze_source(array $data): array {
        return array(
            'warnings' => $this->warning_codes($data),
            'calculation' => $this->calculate($data),
        );
    }

    /**
     * Return the exact read-only projection used by apply operations.
     *
     * The current-catalog report consumes this instead of maintaining a second
     * list of plugin-owned metadata or a second interpretation of sparse input.
     */
    public function report_projection(array $data): array {
        return $this->desired_values($data);
    }

    /** @return string[] */
    public function managed_meta_keys(): array {
        static $keys = null;
        if (null === $keys) {
            $keys = array_keys($this->desired_values(array())['meta']);
        }
        return $keys;
    }

    /** Apply only fields whose desired value differs. */
    public function apply_product_feed($product, array $data): array {
        $plan = $this->plan($product, $data);
        if (!$plan['changed']) {
            return $plan;
        }

        foreach ($plan['core_changes'] as $field => $change) {
            $value = $change['new'];
            switch ($field) {
                case 'regular_price':
                    $product->set_regular_price((string) $value);
                    break;
                case 'price':
                    $product->set_price((string) $value);
                    break;
                case 'sale_price':
                    $product->set_sale_price((string) $value);
                    break;
                case 'weight':
                    $product->set_weight((string) $value);
                    break;
                case 'manage_stock':
                    $product->set_manage_stock((bool) $value);
                    break;
                case 'stock_quantity':
                    $product->set_stock_quantity((int) $value);
                    break;
                case 'stock_status':
                    $product->set_stock_status((string) $value);
                    break;
                case 'status':
                    $product->set_status((string) $value);
                    break;
            }
        }
        foreach ($plan['meta_changes'] as $key => $change) {
            $product->update_meta_data($key, (string) $change['new']);
        }
        $product->save();

        $expected_hash = (string) ($data['record_hash'] ?? '');
        $stored_hash = (string) get_post_meta($product->get_id(), '_ashko_patris_record_hash', true);
        if ('' === $expected_hash || !hash_equals($expected_hash, $stored_hash)) {
            throw new RuntimeException('Ashco Patris record hash readback failed.');
        }
        return $plan;
    }

    public function warning_codes(array $data): array {
        $warnings = array();
        $source = $this->selected_price_source($data);
        $uses_cny = is_array($source) && 'foreign_price' === $source['kind'];
        $uses_partner_price = is_array($source) && 'partner_price' === $source['kind'];
        $uses_direct_sale_price = is_array($source) && 'sale_price_direct' === $source['kind'];
        $has_raw_cny = Decimal_Calculator::positive_decimal($data['foreign_price'] ?? null)
            && 'CNY' === strtoupper((string) ($data['foreign_currency'] ?? ''));
        if (!$has_raw_cny) {
            $warnings[] = 'missing_cny';
        }
        if (null === $source) {
            $warnings[] = 'missing_price_source';
        } elseif ($uses_partner_price) {
            $warnings[] = 'partner_price_source_used';
        } elseif ($uses_direct_sale_price) {
            $warnings[] = 'direct_sale_price_source_used';
        }
        if ($uses_cny && null === ($data['weight_grams'] ?? null)) {
            $warnings[] = 'missing_weight';
        }
        if ('' === (string) ($data['unit'] ?? '')) {
            $warnings[] = 'missing_unit';
        }
        if ('' === (string) ($data['serial'] ?? '')) {
            $warnings[] = 'missing_serial';
        }
        $shipping_currency = (string) Config::get('shipping_price_per_kg_currency', '');
        if (
            $uses_cny
            && (
                '' === (string) Config::get('default_shipping_method')
                || '' === (string) Config::get('shipping_price_per_kg')
                || !in_array($shipping_currency, array('CNY', 'IRR'), true)
            )
        ) {
            $warnings[] = 'missing_shipping';
        }
        if (null !== $source && !$uses_direct_sale_price && '' === (string) Config::get('profit_margin_percent')) {
            $warnings[] = 'missing_margin';
        }
        if ($uses_cny && '' === (string) Config::get('fx_irr_per_cny')) {
            $warnings[] = 'missing_fx';
        }
        if (!$uses_direct_sale_price && !preg_match('/^[0-9]$/', (string) Config::get('price_rounding_digits', ''))) {
            $warnings[] = 'missing_rounding';
        }
        if (is_numeric($data['total_stock'] ?? null) && (float) $data['total_stock'] < 0) {
            $warnings[] = 'negative_stock';
        }

        $calculation = $this->calculate($data);
        if (null === $calculation) {
            $warnings[] = 'missing_final_price';
        } elseif (null !== ($data['final_price'] ?? null)) {
            $difference = Decimal_Calculator::difference(
                $calculation['woo_final_irr'],
                (string) ((int) $data['final_price'] * 10)
            );
            if (0 !== $difference) {
                $warnings[] = 'formula_discrepancy';
            }
        }
        foreach ((array) ($data['warnings'] ?? array()) as $source_warning) {
            if (is_string($source_warning) && '' !== $source_warning) {
                $warnings[] = 'source:' . $source_warning;
            }
        }
        $warnings = array_values(array_unique($warnings));
        sort($warnings, SORT_STRING);
        return $warnings;
    }

    private function desired_values(array $data): array {
        $calculation = $this->calculate($data);
        $stock = null;
        if (null !== ($data['total_stock'] ?? null)) {
            $stock = Decimal_Calculator::stock($data['total_stock'], (string) Config::get('stock_percent', '30'));
        }
        $core = array();
        if (null !== $stock) {
            $core['manage_stock'] = true;
            $core['stock_quantity'] = $stock;
            $core['stock_status'] = $stock > 0 ? 'instock' : 'outofstock';
        }
        if (null !== ($data['weight_grams'] ?? null)) {
            $core['weight'] = $this->store_weight($data['weight_grams']);
        }
        if (null !== $calculation) {
            $core['regular_price'] = $calculation['woo_final_irr'];
            $core['price'] = $calculation['woo_final_irr'];
            $core['sale_price'] = '';
        }

        $cny = null === ($data['foreign_price'] ?? null) ? '' : $this->scalar($data['foreign_price']);
        $weight = null === ($data['weight_grams'] ?? null) ? '' : $this->scalar($data['weight_grams']);
        $full_stock = null === ($data['total_stock'] ?? null) ? '' : $this->scalar($data['total_stock']);
        $source_final_irt = null === ($data['final_price'] ?? null) ? '' : $this->scalar($data['final_price']);
        $source_final_irr = '' === $source_final_irt ? '' : (string) ((int) $source_final_irt * 10);
        $native_final_irt = null === $calculation ? '' : $calculation['native_final_irt'];
        $final_irr = null === $calculation ? '' : $calculation['woo_final_irr'];
        $difference = null;
        $difference_irr = null;
        if (null !== $calculation && '' !== $source_final_irt) {
            $difference = Decimal_Calculator::difference($native_final_irt, $source_final_irt);
            $difference_irr = Decimal_Calculator::difference($final_irr, $source_final_irr);
        }
        $selected_source = $this->selected_price_source($data);
        $uses_cny = is_array($selected_source) && 'foreign_price' === $selected_source['kind'];
        $uses_direct_sale_price = is_array($selected_source) && 'sale_price_direct' === $selected_source['kind'];
        $has_selected_source = is_array($selected_source);
        $effective_method = $uses_cny
            ? (string) Config::get('default_shipping_method', 'air_express')
            : ($has_selected_source ? Decimal_Calculator::DOMESTIC_SHIPPING_METHOD : '');
        $effective_shipping_price = $uses_cny
            ? (string) Config::get('shipping_price_per_kg', '')
            : ($has_selected_source ? Decimal_Calculator::DOMESTIC_SHIPPING_PRICE_PER_KG : '');
        $effective_shipping_currency = $uses_cny
            ? (string) Config::get('shipping_price_per_kg_currency', '')
            : ($has_selected_source ? Decimal_Calculator::DOMESTIC_SHIPPING_CURRENCY : '');
        $source_shipping_price = null === ($data['shipping_price_per_kg'] ?? null)
            ? ''
            : $this->scalar($data['shipping_price_per_kg']);
        $source_shipping_currency = null === ($data['shipping_price_per_kg_currency'] ?? null)
            ? ''
            : (string) $data['shipping_price_per_kg_currency'];

        $meta = array(
            '_ashko_patris_product_code' => (string) ($data['product_code'] ?? ''),
            Config::OWN_SERIAL_META => (string) ($data['serial'] ?? ''),
            '_ashko_patris_name' => (string) ($data['name'] ?? ''),
            '_ashko_patris_unit' => (string) ($data['unit'] ?? ''),
            'woodmart_price_unit_of_measure' => (string) ($data['unit'] ?? ''),
            '_ashko_patris_cny' => $cny,
            'ashko_cny_price' => $cny,
            '_ashko_patris_foreign_currency' => (string) ($data['foreign_currency'] ?? ''),
            '_ashko_patris_partner_price_source' => null === ($data['partner_price_source'] ?? null)
                ? ''
                : $this->scalar($data['partner_price_source']),
            '_ashko_patris_sale_price_source' => null === ($data['sale_price_source'] ?? null)
                ? ''
                : $this->scalar($data['sale_price_source']),
            '_ashko_patris_purchase_price_source' => null === ($data['purchase_price_source'] ?? null)
                ? ''
                : $this->scalar($data['purchase_price_source']),
            '_ashko_patris_price_source_amount' => $has_selected_source ? $selected_source['amount'] : '',
            '_ashko_patris_price_source_currency' => $has_selected_source ? $selected_source['currency'] : '',
            '_ashko_patris_price_source_kind' => $has_selected_source ? $selected_source['kind'] : '',
            '_ashko_patris_weight_grams' => $weight,
            '_ashko_patris_allanbar_full' => $full_stock,
            '_ashko_patris_stock_percent' => (string) Config::get('stock_percent', '30'),
            '_ashko_patris_stock_applied' => null === $stock ? '' : (string) $stock,
            '_ashko_patris_warehouse_stock' => wp_json_encode($data['warehouse_stock'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '_ashko_patris_shipping_method_id' => $effective_method,
            '_ashko_patris_source_shipping_method_id' => (string) ($data['shipping_method_id'] ?? ''),
            '_ashko_patris_shipping_price_per_kg' => $effective_shipping_price,
            '_ashko_patris_shipping_price_per_kg_currency' => $effective_shipping_currency,
            '_ashko_patris_source_shipping_price_per_kg' => $source_shipping_price,
            '_ashko_patris_source_shipping_price_per_kg_currency' => $source_shipping_currency,
            '_ashko_patris_markup_percent' => $has_selected_source && !$uses_direct_sale_price
                ? (string) Config::get('profit_margin_percent', '')
                : '',
            '_ashko_patris_source_markup_percent' => null === ($data['markup_percent'] ?? null) ? '' : $this->scalar($data['markup_percent']),
            '_ashko_patris_fx_irr_per_cny' => $uses_cny ? (string) Config::get('fx_irr_per_cny', '') : '',
            '_ashko_patris_source_irt_per_cny' => null === ($data['irt_per_cny'] ?? null) ? '' : $this->scalar($data['irt_per_cny']),
            '_ashko_patris_price_rounding_digits' => $has_selected_source && !$uses_direct_sale_price
                ? (string) Config::get('price_rounding_digits', '0')
                : '',
            '_ashko_patris_price_rounding_mode' => $has_selected_source && !$uses_direct_sale_price
                ? 'nearest_half_up'
                : '',
            '_ashko_patris_source_price_rounding_digits' => null === ($data['price_rounding_digits'] ?? null)
                ? ''
                : $this->scalar($data['price_rounding_digits']),
            '_ashko_patris_source_price_rounding_mode' => null === ($data['price_rounding_mode'] ?? null)
                ? ''
                : (string) $data['price_rounding_mode'],
            '_ashko_patris_source_final_irt' => $source_final_irt,
            '_ashko_patris_source_final_irr' => $source_final_irr,
            '_ashko_patris_native_final_irt' => $native_final_irt,
            '_ashko_patris_final_irr' => $final_irr,
            '_ashko_patris_formula_discrepancy_irt' => null === $difference ? '' : (string) $difference,
            '_ashko_patris_formula_discrepancy_irr' => null === $difference_irr ? '' : (string) $difference_irr,
            '_ashko_patris_formula' => null === $calculation ? '' : $calculation['formula'],
            '_ashko_patris_category_code' => (string) ($data['category_code'] ?? ''),
            '_ashko_patris_currency_effective_date' => (string) ($data['currency_effective_date'] ?? ''),
            'ashko_currency_effective_date' => (string) ($data['currency_effective_date'] ?? ''),
            '_ashko_patris_pricing_catalog_revision' => (string) ($data['pricing_catalog_revision'] ?? ''),
            '_ashko_patris_pricing_catalog_status' => (string) ($data['pricing_catalog_status'] ?? ''),
            '_ashko_patris_source_updated_at' => (string) ($data['source_updated_at'] ?? ''),
            '_ashko_patris_record_hash' => (string) ($data['record_hash'] ?? ''),
            '_ashko_patris_warnings' => wp_json_encode($this->warning_codes_without_recursion($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '_ashko_patris_publication_safety' => '',
        );

        return array('core' => $core, 'meta' => $meta, 'calculation' => $calculation);
    }

    private function warning_codes_without_recursion(array $data): array {
        $source = array_values(array_filter((array) ($data['warnings'] ?? array()), 'is_string'));
        sort($source, SORT_STRING);
        return $source;
    }

    private function calculate(array $data): ?array {
        $source = $this->selected_price_source($data);
        if (null === $source) {
            return null;
        }
        return Decimal_Calculator::price(
            $source['amount'],
            $source['currency'],
            $source['kind'],
            $data['weight_grams'] ?? null,
            Config::get('fx_irr_per_cny', ''),
            Config::get('shipping_price_per_kg', ''),
            Config::get('shipping_price_per_kg_currency', ''),
            Config::get('profit_margin_percent', ''),
            Config::get('price_rounding_digits', '')
        );
    }

    private function selected_price_source(array $data): ?array {
        if (
            !array_key_exists('price_source_amount', $data)
            || !array_key_exists('price_source_currency', $data)
            || !array_key_exists('price_source_kind', $data)
            || !Decimal_Calculator::positive_decimal($data['price_source_amount'])
        ) {
            return null;
        }
        $currency = (string) $data['price_source_currency'];
        $kind = (string) $data['price_source_kind'];
        if (
            !(
                ('foreign_price' === $kind && 'CNY' === $currency)
                || ('partner_price' === $kind && 'IRR' === $currency)
                || (
                    'sale_price_direct' === $kind
                    && 'IRR' === $currency
                    && 'yes' === (string) Config::get('use_sale_price_direct_fallback', 'no')
                )
            )
        ) {
            return null;
        }
        return array(
            'amount' => $this->scalar($data['price_source_amount']),
            'currency' => $currency,
            'kind' => $kind,
        );
    }

    private function publication_safety($product, array $data, ?array $calculation): array {
        $source_stock_is_known_nonpositive = array_key_exists('total_stock', $data)
            && null !== $data['total_stock']
            && is_numeric($data['total_stock'])
            && (float) $data['total_stock'] <= 0;
        $woo_stock_is_positive = method_exists($product, 'get_stock_quantity')
            && null !== $product->get_stock_quantity('edit')
            && (float) $product->get_stock_quantity('edit') > 0;
        // The incoming snapshot is authoritative for stock because this apply also
        // replaces Woo's quantity. A positive Woo quantity can therefore be stale.
        $no_positive_stock = $source_stock_is_known_nonpositive;

        $canonical_price_is_positive = array_key_exists('final_price', $data)
            && null !== $data['final_price']
            && Decimal_Calculator::positive_decimal($data['final_price']);
        $calculated_price_is_positive = is_array($calculation)
            && Decimal_Calculator::positive_decimal($calculation['woo_final_irr'] ?? null);
        $woo_price_is_positive = false;
        foreach (array('get_regular_price', 'get_price', 'get_sale_price') as $getter) {
            if (method_exists($product, $getter) && Decimal_Calculator::positive_decimal($product->{$getter}('edit'))) {
                $woo_price_is_positive = true;
                break;
            }
        }
        $price_undetermined = !$calculated_price_is_positive
            && !$canonical_price_is_positive
            && !$woo_price_is_positive;
        $image_missing = $this->image_is_known_missing($product);
        $should_draft = $image_missing && $price_undetermined && $no_positive_stock;
        $current_status = method_exists($product, 'get_status') ? (string) $product->get_status('edit') : '';

        $warnings = array();
        if ($image_missing) {
            $warnings[] = 'publication_missing_image';
        }
        if ($price_undetermined) {
            $warnings[] = 'publication_price_undetermined';
        }
        if ($no_positive_stock) {
            $warnings[] = 'publication_no_positive_stock';
        }
        if ($should_draft) {
            $warnings[] = 'draft' === $current_status
                ? 'publication_safety_kept_draft'
                : 'publication_safety_draft_required';
        }

        return array(
            'should_draft' => $should_draft,
            'image_missing' => $image_missing,
            'price_undetermined' => $price_undetermined,
            'no_positive_stock' => $no_positive_stock,
            'woo_stock_is_positive' => $woo_stock_is_positive,
            'current_status' => $current_status,
            'desired_status' => $should_draft ? 'draft' : '',
            'warnings' => $warnings,
        );
    }

    private function image_is_known_missing($product): bool {
        if (!method_exists($product, 'get_image_id')) {
            return false;
        }
        if ((int) $product->get_image_id('edit') > 0) {
            return false;
        }
        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            $parent = wc_get_product((int) $product->get_parent_id());
            if (!$parent || !method_exists($parent, 'get_image_id')) {
                return false;
            }
            return (int) $parent->get_image_id('edit') <= 0;
        }
        return true;
    }

    private function read_core($product, string $field) {
        switch ($field) {
            case 'regular_price': return (string) $product->get_regular_price('edit');
            case 'price': return (string) $product->get_price('edit');
            case 'sale_price': return (string) $product->get_sale_price('edit');
            case 'weight': return (string) $product->get_weight('edit');
            case 'manage_stock': return (bool) $product->get_manage_stock('edit');
            case 'stock_quantity': return null === $product->get_stock_quantity('edit') ? null : (int) $product->get_stock_quantity('edit');
            case 'stock_status': return (string) $product->get_stock_status('edit');
            case 'status': return method_exists($product, 'get_status') ? (string) $product->get_status('edit') : '';
        }
        return null;
    }

    private function same($old, $new, string $field): bool {
        if ('manage_stock' === $field) {
            return (bool) $old === (bool) $new;
        }
        if ('stock_quantity' === $field) {
            return null !== $old && (int) $old === (int) $new;
        }
        if (in_array($field, array('regular_price', 'price', 'weight'), true)) {
            return $this->normalize_decimal((string) $old) === $this->normalize_decimal((string) $new);
        }
        return (string) $old === (string) $new;
    }

    private function normalize_decimal(string $value): string {
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
            return $value;
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return '' === $value || '-0' === $value ? '0' : $value;
    }

    private function store_weight($grams): string {
        $unit = (string) get_option('woocommerce_weight_unit', 'g');
        if ('g' === $unit) {
            return $this->scalar($grams);
        }
        if (function_exists('wc_get_weight')) {
            $converted = wc_get_weight((float) $grams, $unit, 'g');
            return function_exists('wc_format_decimal') ? wc_format_decimal($converted, 8, true) : (string) $converted;
        }
        return $this->scalar($grams);
    }

    private function scalar($value): string {
        if (is_float($value)) {
            return (string) json_decode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION), true);
        }
        return (string) $value;
    }
}
