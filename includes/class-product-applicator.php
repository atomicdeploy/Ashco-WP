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

        return array(
            'changed' => array() !== $core_changes || array() !== $meta_changes,
            'core_changes' => $core_changes,
            'meta_changes' => $meta_changes,
            'warnings' => $warnings,
            'calculation' => $desired['calculation'],
        );
    }

    public function analyze_source(array $data): array {
        return array(
            'warnings' => $this->warning_codes($data),
            'calculation' => $this->calculate($data),
        );
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
            }
        }
        foreach ($plan['meta_changes'] as $key => $change) {
            $product->update_meta_data($key, (string) $change['new']);
        }
        $product->save();

        $expected_hash = (string) ($data['record_hash'] ?? '');
        $stored_hash = (string) get_post_meta($product->get_id(), '_ashko_patris_record_hash', true);
        if ('' === $expected_hash || !hash_equals($expected_hash, $stored_hash)) {
            throw new RuntimeException('Ashko Patris record hash readback failed.');
        }
        return $plan;
    }

    public function warning_codes(array $data): array {
        $warnings = array();
        $has_cny = null !== ($data['foreign_price'] ?? null) && 'CNY' === strtoupper((string) ($data['foreign_currency'] ?? ''));
        if (!$has_cny) {
            $warnings[] = 'missing_cny';
        }
        if (null === ($data['weight_grams'] ?? null)) {
            $warnings[] = 'missing_weight';
        }
        if ('' === (string) ($data['unit'] ?? '')) {
            $warnings[] = 'missing_unit';
        }
        if ('' === (string) ($data['serial'] ?? '')) {
            $warnings[] = 'missing_serial';
        }
        if ($has_cny && ('' === (string) Config::get('default_freight_method') || '' === (string) Config::get('freight_irr_per_kg'))) {
            $warnings[] = 'missing_freight';
        }
        if ($has_cny && '' === (string) Config::get('profit_margin_percent')) {
            $warnings[] = 'missing_margin';
        }
        if ($has_cny && '' === (string) Config::get('fx_irr_per_cny')) {
            $warnings[] = 'missing_fx';
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
        if (is_numeric($data['total_stock'] ?? null) && (float) $data['total_stock'] < 0) {
            $stock = 0;
        } elseif (null !== ($data['total_stock'] ?? null)) {
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
        $has_cny = '' !== $cny && 'CNY' === strtoupper((string) ($data['foreign_currency'] ?? ''));
        $effective_method = $has_cny ? (string) Config::get('default_freight_method', 'air_express') : '';

        $meta = array(
            '_ashko_patris_product_code' => (string) ($data['product_code'] ?? ''),
            Config::OWN_SERIAL_META => (string) ($data['serial'] ?? ''),
            '_ashko_patris_name' => (string) ($data['name'] ?? ''),
            '_ashko_patris_unit' => (string) ($data['unit'] ?? ''),
            'woodmart_price_unit_of_measure' => (string) ($data['unit'] ?? ''),
            '_ashko_patris_cny' => $cny,
            'ashko_cny_price' => $cny,
            '_ashko_patris_foreign_currency' => (string) ($data['foreign_currency'] ?? ''),
            '_ashko_patris_weight_grams' => $weight,
            '_ashko_patris_allanbar_full' => $full_stock,
            '_ashko_patris_stock_percent' => (string) Config::get('stock_percent', '30'),
            '_ashko_patris_stock_applied' => null === $stock ? '' : (string) $stock,
            '_ashko_patris_warehouse_stock' => wp_json_encode($data['warehouse_stock'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '_ashko_patris_import_freight_method_id' => $effective_method,
            '_ashko_patris_source_freight_method_id' => (string) ($data['import_freight_method_id'] ?? ''),
            '_ashko_patris_freight_irr_per_kg' => $has_cny ? (string) Config::get('freight_irr_per_kg', '') : '',
            '_ashko_patris_source_freight_cny_per_kg' => null === ($data['freight_cny_per_kg'] ?? null) ? '' : $this->scalar($data['freight_cny_per_kg']),
            '_ashko_patris_markup_percent' => $has_cny ? (string) Config::get('profit_margin_percent', '') : '',
            '_ashko_patris_source_markup_percent' => null === ($data['markup_percent'] ?? null) ? '' : $this->scalar($data['markup_percent']),
            '_ashko_patris_fx_irr_per_cny' => $has_cny ? (string) Config::get('fx_irr_per_cny', '') : '',
            '_ashko_patris_source_irt_per_cny' => null === ($data['irt_per_cny'] ?? null) ? '' : $this->scalar($data['irt_per_cny']),
            '_ashko_patris_source_final_irt' => $source_final_irt,
            '_ashko_patris_source_final_irr' => $source_final_irr,
            '_ashko_patris_native_final_irt' => $native_final_irt,
            '_ashko_patris_final_irr' => $final_irr,
            '_ashko_patris_formula_discrepancy_irt' => null === $difference ? '' : (string) $difference,
            '_ashko_patris_formula_discrepancy_irr' => null === $difference_irr ? '' : (string) $difference_irr,
            '_ashko_patris_formula' => null === $calculation ? '' : $calculation['formula'],
            '_ashko_patris_formula_version' => (string) ($data['formula_version'] ?? ''),
            '_ashko_patris_category_code' => (string) ($data['category_code'] ?? ''),
            '_ashko_patris_currency_effective_date' => (string) ($data['currency_effective_date'] ?? ''),
            'ashko_currency_effective_date' => (string) ($data['currency_effective_date'] ?? ''),
            '_ashko_patris_pricing_catalog_revision' => (string) ($data['pricing_catalog_revision'] ?? ''),
            '_ashko_patris_pricing_catalog_status' => (string) ($data['pricing_catalog_status'] ?? ''),
            '_ashko_patris_source_updated_at' => (string) ($data['source_updated_at'] ?? ''),
            '_ashko_patris_record_hash' => (string) ($data['record_hash'] ?? ''),
            '_ashko_patris_warnings' => wp_json_encode($this->warning_codes_without_recursion($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return array('core' => $core, 'meta' => $meta, 'calculation' => $calculation);
    }

    private function warning_codes_without_recursion(array $data): array {
        $source = array_values(array_filter((array) ($data['warnings'] ?? array()), 'is_string'));
        sort($source, SORT_STRING);
        return $source;
    }

    private function calculate(array $data): ?array {
        $has_cny = null !== ($data['foreign_price'] ?? null) && 'CNY' === strtoupper((string) ($data['foreign_currency'] ?? ''));
        if (!$has_cny || null === ($data['weight_grams'] ?? null)) {
            return null;
        }
        return Decimal_Calculator::price(
            $data['foreign_price'],
            $data['weight_grams'],
            Config::get('fx_irr_per_cny', ''),
            Config::get('freight_irr_per_kg', ''),
            Config::get('profit_margin_percent', '')
        );
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
