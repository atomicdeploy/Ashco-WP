<?php
use Ashko\Patris\Product_Sync_Receiver;
use PHPUnit\Framework\TestCase;

final class ProductSyncContractTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['ashko_test_options']['ashko_product_sync_state']);
        $GLOBALS['ashko_test_options']['ashko_product_sync_source_scopes'] = array();
        unset($GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION]);
        $GLOBALS['ashko_test_currency'] = 'IRR';
        $GLOBALS['ashko_test_products'] = array();
    }

    public function test_living_sparse_contract_is_accepted(): void {
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertSame('landed_price', $preview['envelope']['formula_id']);
        self::assertCount(2, $preview['transition']['categories']);
        self::assertSame(array('999010'), $preview['transition']['excluded_codes']);
        self::assertSame('foreign_price', $preview['envelope']['products'][0]['price_source_kind']);
        self::assertSame('CNY', $preview['envelope']['products'][0]['price_source_currency']);
        self::assertSame(0, $preview['envelope']['products'][0]['price_rounding_digits']);
        self::assertSame('nearest_half_up', $preview['envelope']['products'][0]['price_rounding_mode']);
    }

    public function test_absent_and_explicit_null_values_remain_distinct(): void {
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview));
        $product = $preview['envelope']['products'][1];
        self::assertArrayNotHasKey('foreign_price', $product);
        self::assertArrayNotHasKey('final_price', $product);
        self::assertArrayHasKey('location', $product);
        self::assertNull($product['location']);
        self::assertSame(array(), $product['warehouse_stock']);

        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $without = array('product_code' => 'P1', 'warnings' => array());
        $with_null = array('product_code' => 'P1', 'location' => null, 'warnings' => array());
        self::assertNotSame(
            $hash->invoke(Product_Sync_Receiver::instance(), $without),
            $hash->invoke(Product_Sync_Receiver::instance(), $with_null)
        );
    }

    public function test_empty_warehouse_object_is_not_interchangeable_with_an_array(): void {
        $json = str_replace('"warehouse_stock": {}', '"warehouse_stock": []', $this->fixture());
        $result = Product_Sync_Receiver::instance()->preview_json($json);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[1].warehouse_stock', $result->get_error_data()['field']);
    }

    public function test_explicit_null_category_name_is_preserved(): void {
        $receiver = Product_Sync_Receiver::instance();
        $category = array(
            'category_code' => 'ROOT',
            'name' => null,
            'parent_code' => '',
            'depth' => 1,
            'warnings' => array(),
        );
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'category_record_hash');
        $category['record_hash'] = $hash->invoke($receiver, $category);
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_categories');
        $result = $validate->invoke($receiver, array($category));
        self::assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        self::assertArrayHasKey('name', $result[0]);
        self::assertNull($result[0]['name']);
    }

    public function test_currency_and_formula_identifiers_are_optional_without_defaults(): void {
        $payload = json_decode($this->fixture(), true);
        unset($payload['local_currency'], $payload['formula_id']);
        $payload['products'][1]['warehouse_stock'] = (object) array();
        $event_id = new ReflectionMethod(Product_Sync_Receiver::class, 'event_id');
        $payload['event_id'] = $event_id->invoke(Product_Sync_Receiver::instance(), $payload);
        $preview = Product_Sync_Receiver::instance()->preview_json((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertArrayNotHasKey('local_currency', $preview['envelope']);
        self::assertArrayNotHasKey('formula_id', $preview['envelope']);
    }

    public function test_currency_and_formula_identifiers_must_be_supplied_together(): void {
        $without_formula = json_decode($this->fixture(), true);
        unset($without_formula['formula_id']);
        $result = Product_Sync_Receiver::instance()->receive($without_formula);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('formula_id', $result->get_error_data()['field']);

        $without_currency = json_decode($this->fixture(), true);
        unset($without_currency['local_currency']);
        $result = Product_Sync_Receiver::instance()->receive($without_currency);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('formula_id', $result->get_error_data()['field']);
    }

    public function test_explicit_null_final_price_is_rejected_and_absence_remains_valid(): void {
        $payload = json_decode($this->fixture(), true);
        $product = $payload['products'][0];
        $product['final_price'] = null;

        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $result = $validate->invoke(Product_Sync_Receiver::instance(), $product, 0);

        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].final_price', $result->get_error_data()['field']);
        self::assertStringContainsString('omit it when unavailable', $result->get_error_data()['reason']);

        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertArrayNotHasKey('final_price', $preview['envelope']['products'][1]);
    }

    public function test_shipping_amount_and_currency_must_be_supplied_as_a_pair(): void {
        $payload = json_decode($this->fixture(), true);
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');

        $without_currency = $payload['products'][0];
        unset($without_currency['shipping_price_per_kg_currency']);
        $result = $validate->invoke(Product_Sync_Receiver::instance(), $without_currency, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].shipping_price_per_kg_currency', $result->get_error_data()['field']);

        $without_amount = $payload['products'][0];
        unset($without_amount['shipping_price_per_kg']);
        $result = $validate->invoke(Product_Sync_Receiver::instance(), $without_amount, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].shipping_price_per_kg', $result->get_error_data()['field']);
    }

    public function test_shipping_currency_accepts_only_uppercase_cny_or_irr(): void {
        $payload = json_decode($this->fixture(), true);
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');

        foreach (array('cny', 'IRT', 'USD') as $currency) {
            $product = $payload['products'][0];
            $product['shipping_price_per_kg_currency'] = $currency;
            $result = $validate->invoke(Product_Sync_Receiver::instance(), $product, 0);
            self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
            self::assertSame('products[0].shipping_price_per_kg_currency', $result->get_error_data()['field']);
        }
    }

    public function test_equivalent_irr_shipping_is_accepted_by_the_irt_formula_validator(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $payload['products'][0]['shipping_price_per_kg'] = 34800000;
        $payload['products'][0]['shipping_price_per_kg_currency'] = 'IRR';

        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $payload['products'][0]['record_hash'] = $hash->invoke($receiver, $payload['products'][0]);
        $source_revision = new ReflectionMethod(Product_Sync_Receiver::class, 'source_revision');
        $payload['source']['revision'] = $source_revision->invoke(
            $receiver,
            $payload['products'],
            $payload['categories'],
            $payload['excluded_codes'],
            $payload['quarantined_codes']
        );
        $event_id = new ReflectionMethod(Product_Sync_Receiver::class, 'event_id');
        $payload['event_id'] = $event_id->invoke($receiver, $payload);
        $payload['products'][1]['warehouse_stock'] = (object) array();

        $preview = $receiver->preview_json((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertFalse(is_wp_error($preview), is_wp_error($preview) ? $preview->get_error_message() : '');
        self::assertSame('IRR', $preview['envelope']['products'][0]['shipping_price_per_kg_currency']);
        self::assertSame('34800000', $preview['envelope']['products'][0]['shipping_price_per_kg']);
        self::assertSame(2009410, $preview['envelope']['products'][0]['final_price']);
    }

    public function test_explicit_null_shipping_pair_is_preserved_but_cannot_produce_final_price(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['shipping_price_per_kg'] = null;
        $product['shipping_price_per_kg_currency'] = null;
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $result = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].price_source_amount', $result->get_error_data()['field']);

        $product['sale_price_source'] = 0;
        unset(
            $product['price_source_amount'],
            $product['price_source_currency'],
            $product['price_source_kind'],
            $product['final_price']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);
        $validated = $validate->invoke($receiver, $product, 0);
        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertArrayHasKey('shipping_price_per_kg', $validated);
        self::assertNull($validated['shipping_price_per_kg']);
        self::assertArrayHasKey('shipping_price_per_kg_currency', $validated);
        self::assertNull($validated['shipping_price_per_kg_currency']);
    }

    public function test_positive_cny_has_priority_over_partner_price(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['partner_price_source'] = 100000;
        $product['price_source_amount'] = $product['partner_price_source'];
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'partner_price';
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $result = $validate->invoke($receiver, $product, 0);

        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].price_source_amount', $result->get_error_data()['field']);
        self::assertStringContainsString('priority', $result->get_error_data()['reason']);
    }

    public function test_partner_irr_fallback_requires_explicit_zero_rate_domestic_shipping(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['foreign_price'] = 0;
        $product['partner_price_source'] = 1000000;
        $product['price_source_amount'] = 1000000;
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'partner_price';
        $product['price_rounding_digits'] = 2;
        $product['markup_percent'] = 30;
        $product['final_price'] = 130000;
        $product['shipping_method_id'] = 'domestic';
        $product['shipping_price_per_kg'] = 0;
        $product['shipping_price_per_kg_currency'] = 'IRR';
        unset(
            $product['weight_grams'],
            $product['irt_per_cny']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);

        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertSame('0', $validated['foreign_price']);
        self::assertSame('1000000', $validated['partner_price_source']);
        self::assertSame('100000', $validated['sale_price_source']);
        self::assertSame('1000000', $validated['price_source_amount']);
        self::assertSame('partner_price', $validated['price_source_kind']);
        self::assertSame('domestic', $validated['shipping_method_id']);
        self::assertSame('0', $validated['shipping_price_per_kg']);
        self::assertSame('IRR', $validated['shipping_price_per_kg_currency']);
        self::assertSame(130000, $validated['final_price']);
    }

    public function test_positive_cny_without_weight_falls_back_to_partner_price_and_domestic_shipping(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        unset($product['weight_grams'], $product['irt_per_cny']);
        $product['partner_price_source'] = 100000;
        $product['price_source_amount'] = $product['partner_price_source'];
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'partner_price';
        $product['shipping_method_id'] = 'domestic';
        $product['shipping_price_per_kg'] = 0;
        $product['shipping_price_per_kg_currency'] = 'IRR';
        $product['final_price'] = 13000;
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);

        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertSame('24.5', $validated['foreign_price']);
        self::assertSame('partner_price', $validated['price_source_kind']);
        self::assertSame('domestic', $validated['shipping_method_id']);
        self::assertSame(13000, $validated['final_price']);
    }

    public function test_explicit_zero_weight_is_preserved_but_cannot_select_the_cny_path(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['weight_grams'] = 0;
        unset(
            $product['price_source_amount'],
            $product['price_source_currency'],
            $product['price_source_kind'],
            $product['final_price']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);

        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertSame('0', $validated['weight_grams']);
        self::assertArrayNotHasKey('price_source_kind', $validated);
        self::assertArrayNotHasKey('final_price', $validated);
    }

    public function test_domestic_shipping_is_exactly_zero_irr_and_foreign_shipping_is_positive(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');

        $domestic = $payload['products'][0];
        $domestic['foreign_price'] = 0;
        $domestic['partner_price_source'] = 100000;
        $domestic['price_source_amount'] = $domestic['partner_price_source'];
        $domestic['price_source_currency'] = 'IRR';
        $domestic['price_source_kind'] = 'partner_price';
        $domestic['shipping_method_id'] = 'domestic';
        $domestic['shipping_price_per_kg'] = 1;
        $domestic['shipping_price_per_kg_currency'] = 'IRR';
        $domestic['final_price'] = 13000;
        $domestic['record_hash'] = $hash->invoke($receiver, $domestic);
        $result = $validate->invoke($receiver, $domestic, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].shipping_price_per_kg', $result->get_error_data()['field']);

        $foreign = $payload['products'][0];
        $foreign['shipping_price_per_kg'] = 0;
        $foreign['sale_price_source'] = 0;
        unset(
            $foreign['price_source_amount'],
            $foreign['price_source_currency'],
            $foreign['price_source_kind'],
            $foreign['final_price']
        );
        $foreign['record_hash'] = $hash->invoke($receiver, $foreign);
        $result = $validate->invoke($receiver, $foreign, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].shipping_price_per_kg', $result->get_error_data()['field']);
    }

    public function test_direct_sale_price_is_disabled_by_default_and_exact_when_explicitly_enabled(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['foreign_price'] = 0;
        $product['sale_price_source'] = 1234560;
        $product['price_source_amount'] = 1234560;
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'sale_price_direct';
        $product['shipping_method_id'] = 'domestic';
        $product['shipping_price_per_kg'] = 0;
        $product['shipping_price_per_kg_currency'] = 'IRR';
        $product['final_price'] = 123456;
        unset(
            $product['weight_grams'],
            $product['markup_percent'],
            $product['irt_per_cny'],
            $product['price_rounding_digits'],
            $product['price_rounding_mode']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $disabled = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_field_invalid', $disabled->get_error_code());
        self::assertSame('products[0].price_source_kind', $disabled->get_error_data()['field']);

        $GLOBALS['ashko_test_options'][Ashko\Patris\Config::OPTION] = array(
            'use_sale_price_direct_fallback' => 'yes',
        );
        $validated = $validate->invoke($receiver, $product, 0);
        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertSame('sale_price_direct', $validated['price_source_kind']);
        self::assertSame('domestic', $validated['shipping_method_id']);
        self::assertSame('0', $validated['shipping_price_per_kg']);
        self::assertSame(123456, $validated['final_price']);

        $unexpected_calculation_input = $product;
        $unexpected_calculation_input['markup_percent'] = 30;
        $unexpected_calculation_input['record_hash'] = $hash->invoke($receiver, $unexpected_calculation_input);
        $unexpected = $validate->invoke($receiver, $unexpected_calculation_input, 0);
        self::assertSame('ashko_product_sync_field_invalid', $unexpected->get_error_code());
        self::assertSame('products[0].markup_percent', $unexpected->get_error_data()['field']);
        self::assertStringContainsString('without modification', $unexpected->get_error_data()['reason']);

        $partner_ready = $product;
        $partner_ready['partner_price_source'] = 900000;
        $partner_ready['markup_percent'] = 30;
        $partner_ready['price_rounding_digits'] = 0;
        $partner_ready['price_rounding_mode'] = 'nearest_half_up';
        $partner_ready['record_hash'] = $hash->invoke($receiver, $partner_ready);
        $not_last_fallback = $validate->invoke($receiver, $partner_ready, 0);
        self::assertSame('ashko_product_sync_field_invalid', $not_last_fallback->get_error_code());
        self::assertSame('products[0].price_source_amount', $not_last_fallback->get_error_data()['field']);
        self::assertStringContainsString('partner calculation is complete', $not_last_fallback->get_error_data()['reason']);

        $product['sale_price_source'] = 1234561;
        $product['price_source_amount'] = 1234561;
        $product['record_hash'] = $hash->invoke($receiver, $product);
        $not_exact = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_field_invalid', $not_exact->get_error_code());
        self::assertSame('products[0].price_source_amount', $not_exact->get_error_data()['field']);
        self::assertStringContainsString('exactly', $not_exact->get_error_data()['reason']);
    }

    public function test_zero_prices_are_preserved_but_do_not_form_a_selected_source(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['foreign_price'] = 0;
        $product['sale_price_source'] = 0;
        unset(
            $product['price_source_amount'],
            $product['price_source_currency'],
            $product['price_source_kind'],
            $product['final_price']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);

        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertArrayHasKey('foreign_price', $validated);
        self::assertSame('0', $validated['foreign_price']);
        self::assertArrayHasKey('sale_price_source', $validated);
        self::assertSame('0', $validated['sale_price_source']);
        self::assertArrayNotHasKey('price_source_amount', $validated);
        self::assertArrayNotHasKey('final_price', $validated);
    }

    public function test_negative_raw_cny_is_rejected_before_partner_fallback(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['foreign_price'] = -1;
        $product['partner_price_source'] = 100000;
        $product['price_source_amount'] = $product['partner_price_source'];
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'partner_price';
        $product['final_price'] = 13000;
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $result = $validate->invoke($receiver, $product, 0);

        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].foreign_price', $result->get_error_data()['field']);
        self::assertStringContainsString('must not be negative', $result->get_error_data()['reason']);
    }

    public function test_selected_price_source_and_rounding_provenance_are_atomic(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');

        $product = $payload['products'][0];
        unset($product['price_source_currency']);
        $result = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_price_source_shape_invalid', $result->get_error_code());
        self::assertContains('price_source_currency', $result->get_error_data()['missing']);

        $product = $payload['products'][0];
        unset($product['price_rounding_mode']);
        $result = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_rounding_shape_invalid', $result->get_error_code());
        self::assertContains('price_rounding_mode', $result->get_error_data()['missing']);
    }

    public function test_explicit_null_rounding_digits_preserve_source_null_without_mode(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['price_rounding_digits'] = null;
        unset(
            $product['price_source_amount'],
            $product['price_source_currency'],
            $product['price_source_kind'],
            $product['price_rounding_mode'],
            $product['final_price']
        );
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);

        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');
        self::assertArrayNotHasKey('price_source_amount', $validated);
        self::assertArrayNotHasKey('price_source_currency', $validated);
        self::assertArrayNotHasKey('price_source_kind', $validated);
        self::assertArrayHasKey('price_rounding_digits', $validated);
        self::assertNull($validated['price_rounding_digits']);
        self::assertArrayNotHasKey('price_rounding_mode', $validated);
        self::assertArrayNotHasKey('final_price', $validated);

        $product['price_rounding_mode'] = 'nearest_half_up';
        $product['record_hash'] = $hash->invoke($receiver, $product);
        $result = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_field_invalid', $result->get_error_code());
        self::assertSame('products[0].price_rounding_mode', $result->get_error_data()['field']);
    }

    public function test_rounding_digits_use_nearest_half_up_in_canonical_irt(): void {
        $payload = json_decode($this->fixture(), true);
        $receiver = Product_Sync_Receiver::instance();
        $validate = new ReflectionMethod(Product_Sync_Receiver::class, 'validate_product');
        $hash = new ReflectionMethod(Product_Sync_Receiver::class, 'record_hash');
        $product = $payload['products'][0];
        $product['foreign_price'] = 0;
        $product['partner_price_source'] = 1234560;
        $product['price_source_amount'] = 1234560;
        $product['price_source_currency'] = 'IRR';
        $product['price_source_kind'] = 'partner_price';
        $product['markup_percent'] = 0;
        $product['price_rounding_digits'] = 2;
        $product['shipping_method_id'] = 'domestic';
        $product['shipping_price_per_kg'] = 0;
        $product['shipping_price_per_kg_currency'] = 'IRR';
        $product['final_price'] = 123500;
        $product['record_hash'] = $hash->invoke($receiver, $product);

        $validated = $validate->invoke($receiver, $product, 0);
        self::assertFalse(is_wp_error($validated), is_wp_error($validated) ? $validated->get_error_message() : '');

        $product['final_price'] = 123400;
        $product['record_hash'] = $hash->invoke($receiver, $product);
        $result = $validate->invoke($receiver, $product, 0);
        self::assertSame('ashko_product_sync_final_price_mismatch', $result->get_error_code());
        self::assertSame(123500, $result->get_error_data()['expected']);
        self::assertSame(123400, $result->get_error_data()['actual']);
    }

    public function test_unknown_contract_fields_are_rejected_without_fallback(): void {
        $payload = json_decode($this->fixture(), true);
        $payload['unsupported_contract_selector'] = 'alternate';
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_unknown_field', $result->get_error_code());

        unset($payload['unsupported_contract_selector']);
        $payload['products'][0]['freight_amount_without_currency'] = 120;
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_product_shape_invalid', $result->get_error_code());
    }

    public function test_raw_patris_key_is_rejected_before_application(): void {
        $payload = json_decode($this->fixture(), true);
        $payload['products'][0]['ALLANBAR'] = 5;
        $result = Product_Sync_Receiver::instance()->receive($payload);
        self::assertSame('ashko_product_sync_raw_key_forbidden', $result->get_error_code());
    }

    public function test_preview_can_report_currency_mismatch_but_apply_fails_before_lock_or_write(): void {
        $GLOBALS['ashko_test_currency'] = 'IRT';
        $preview = Product_Sync_Receiver::instance()->preview_json($this->fixture());
        self::assertFalse(is_wp_error($preview));
        $result = Product_Sync_Receiver::instance()->receive_json($this->fixture());
        self::assertSame('ashko_product_sync_store_currency_mismatch', $result->get_error_code());
        self::assertSame(array(), $GLOBALS['ashko_test_products']);
    }

    private function fixture(): string {
        return (string) file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json');
    }
}
