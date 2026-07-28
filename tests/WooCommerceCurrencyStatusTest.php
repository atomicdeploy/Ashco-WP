<?php
use Ashko\Patris\WooCommerce_Currency_Status;
use PHPUnit\Framework\TestCase;

final class WooCommerceCurrencyStatusTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_hooks'] = array();
        $GLOBALS['ashko_test_products'] = array();
        $GLOBALS['ashko_test_queried_object_id'] = 0;
        $GLOBALS['ashko_test_post_types'] = array();
        $GLOBALS['ashko_test_options']['woocommerce_currency'] = 'IRR';
    }

    public function test_registers_after_the_known_priority_100_currency_filters(): void {
        WooCommerce_Currency_Status::register();

        $expected = array(
            'woocommerce_structured_data_product_offer' => 2,
            'rank_math/snippet/rich_snippet_product_entity' => 1,
            'rank_math/opengraph/facebook/product_price_amount' => 1,
            'rank_math/opengraph/facebook/product_price_currency' => 1,
        );
        foreach ($expected as $hook_name => $accepted_args) {
            $hooks = array_values(array_filter(
                $GLOBALS['ashko_test_hooks'],
                static fn(array $hook): bool => $hook_name === $hook['hook']
            ));
            self::assertCount(1, $hooks, $hook_name);
            self::assertSame(200, $hooks[0]['priority'], $hook_name);
            self::assertSame($accepted_args, $hooks[0]['accepted_args'], $hook_name);
        }
    }

    public function test_rank_math_offer_uses_raw_irr_edit_context_prices(): void {
        new Ashko_Test_Product(13507, array(
            'price' => '138000000',
            'regular_price' => '138000000',
            'sale_price' => '',
        ));
        $GLOBALS['ashko_test_queried_object_id'] = 13507;

        $entity = array(
            '@type' => 'Product',
            'offers' => array(
                '@type' => 'Offer',
                'price' => '1380000000',
                'priceCurrency' => 'IRR',
                'availability' => 'http://schema.org/InStock',
            ),
        );
        $normalized = WooCommerce_Currency_Status::normalize_rank_math_product_entity($entity);

        self::assertSame('138000000', $normalized['offers']['price']);
        self::assertSame('IRR', $normalized['offers']['priceCurrency']);
        self::assertSame(
            'http://schema.org/InStock',
            $normalized['offers']['availability']
        );
    }

    public function test_woocommerce_offer_restores_active_sale_and_list_prices(): void {
        $product = new Ashko_Test_Product(200, array(
            'price' => '80000000',
            'regular_price' => '100000000',
            'sale_price' => '80000000',
        ));
        $offer = array(
            '@type' => 'Offer',
            'price' => '800000000',
            'priceCurrency' => 'IRR',
            'priceSpecification' => array(
                array(
                    '@type' => 'UnitPriceSpecification',
                    'price' => '800000000',
                    'priceCurrency' => 'IRR',
                ),
                array(
                    '@type' => 'UnitPriceSpecification',
                    'price' => '1000000000',
                    'priceCurrency' => 'IRR',
                    'priceType' => 'https://schema.org/ListPrice',
                ),
            ),
        );

        $normalized = WooCommerce_Currency_Status::normalize_woocommerce_offer($offer, $product);
        self::assertSame('80000000', $normalized['price']);
        self::assertSame('80000000', $normalized['priceSpecification'][0]['price']);
        self::assertSame('100000000', $normalized['priceSpecification'][1]['price']);
        self::assertSame('IRR', $normalized['priceSpecification'][0]['priceCurrency']);
        self::assertSame('IRR', $normalized['priceSpecification'][1]['priceCurrency']);
    }

    public function test_rank_math_opengraph_amount_uses_raw_product_price(): void {
        new Ashko_Test_Product(201, array(
            'price' => '138000000',
            'regular_price' => '138000000',
        ));
        $GLOBALS['ashko_test_queried_object_id'] = 201;

        self::assertSame(
            '138000000',
            WooCommerce_Currency_Status::normalize_rank_math_opengraph_amount('1380000000')
        );
        self::assertSame(
            'IRR',
            WooCommerce_Currency_Status::normalize_rank_math_opengraph_currency('IRT')
        );
    }

    public function test_zero_is_authoritative_but_empty_price_does_not_create_data(): void {
        $zero = new Ashko_Test_Product(202, array(
            'price' => '0',
            'regular_price' => '0',
        ));
        $zero_offer = WooCommerce_Currency_Status::normalize_woocommerce_offer(array(
            '@type' => 'Offer',
            'price' => '10',
            'priceCurrency' => 'IRT',
        ), $zero);
        self::assertSame('0', $zero_offer['price']);
        self::assertSame('IRR', $zero_offer['priceCurrency']);

        new Ashko_Test_Product(203, array(
            'price' => '',
            'regular_price' => '',
        ));
        $GLOBALS['ashko_test_queried_object_id'] = 203;
        $empty_entity = array(
            '@type' => 'Product',
            'offers' => array(
                '@type' => 'Offer',
                'price' => 'unchanged',
                'priceCurrency' => 'IRT',
            ),
        );
        self::assertSame(
            $empty_entity,
            WooCommerce_Currency_Status::normalize_rank_math_product_entity($empty_entity)
        );
        self::assertSame(
            'unchanged',
            WooCommerce_Currency_Status::normalize_rank_math_opengraph_amount('unchanged')
        );
    }

    public function test_non_irr_store_is_a_complete_noop(): void {
        $GLOBALS['ashko_test_options']['woocommerce_currency'] = 'IRT';
        $product = new Ashko_Test_Product(204, array(
            'price' => '138000000',
            'regular_price' => '138000000',
        ));
        $GLOBALS['ashko_test_queried_object_id'] = 204;
        $offer = array(
            '@type' => 'Offer',
            'price' => '1380000000',
            'priceCurrency' => 'IRR',
        );
        $entity = array('@type' => 'Product', 'offers' => $offer);

        self::assertSame(
            $offer,
            WooCommerce_Currency_Status::normalize_woocommerce_offer($offer, $product)
        );
        self::assertSame(
            $entity,
            WooCommerce_Currency_Status::normalize_rank_math_product_entity($entity)
        );
        self::assertSame(
            '1380000000',
            WooCommerce_Currency_Status::normalize_rank_math_opengraph_amount('1380000000')
        );
        self::assertSame(
            'IRR',
            WooCommerce_Currency_Status::normalize_rank_math_opengraph_currency('IRR')
        );
    }
}
