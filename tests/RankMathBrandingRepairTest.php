<?php
use AtomicDeploy\Ashco\Maintenance\Rank_Math_Branding_Repair;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/scripts/lib/class-rank-math-branding-repair.php';

final class RankMathBrandingRepairTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ashko_test_products'] = array();
        $GLOBALS['ashko_test_options']['rank-math-options-titles'] = array(
            'pt_product_title' => 'title% | Ashco Electronic%',
            'pt_product_description' => '%exceorpt%',
            'website_name' => 'ASHCO ELECTRONIC',
        );
    }

    public function test_repairs_only_strictly_matched_options_and_product_meta(): void {
        $mq2 = new Ashko_Test_Product(17596, array(), array(
            '_sku' => 'MQ2',
            'rank_math_title' => 'title% | %ماژول MQ2 | خرید سنسور گاز و دود MQ2 با بهترین قیمت',
        ));
        $microphone = new Ashko_Test_Product(17591, array(), array(
            '_sku' => 'MICRO037',
            'rank_math_title' => ' ماژول میکروفن KY-038 | %title%  ',
        ));
        $wrong_sku = new Ashko_Test_Product(17625, array(), array(
            '_sku' => 'NOT-BA33',
            'rank_math_title' => 'title% | ماژول بلوتوث صوتی mh-m38%',
        ));
        $clean = new Ashko_Test_Product(17668, array(), array(
            '_sku' => 'OLED',
            'rank_math_title' => '%title% | نمایشگر OLED',
        ));

        $result = Rank_Math_Branding_Repair::run();
        $options = $GLOBALS['ashko_test_options']['rank-math-options-titles'];

        self::assertSame('%title% | Ashco Electronic', $options['pt_product_title']);
        self::assertSame('%excerpt%', $options['pt_product_description']);
        self::assertSame('ASHCO ELECTRONIC', $options['website_name']);
        self::assertSame('', $mq2->get_meta('rank_math_title'));
        self::assertSame('', $microphone->get_meta('rank_math_title'));
        self::assertSame('title% | ماژول بلوتوث صوتی mh-m38%', $wrong_sku->get_meta('rank_math_title'));
        self::assertSame('%title% | نمایشگر OLED', $clean->get_meta('rank_math_title'));
        self::assertSame(2, $result['option_fields_updated']);
        self::assertSame(20, $result['post_meta_candidates']);
        self::assertSame(2, $result['post_meta_deleted']);
        self::assertSame(18, $result['post_meta_skipped']);
        self::assertSame(0, $result['post_meta_failed']);
    }

    public function test_repair_is_idempotent(): void {
        Rank_Math_Branding_Repair::run();
        $result = Rank_Math_Branding_Repair::run();

        self::assertSame(0, $result['option_fields_updated']);
        self::assertSame(0, $result['post_meta_deleted']);
        self::assertSame(20, $result['post_meta_skipped']);
        self::assertSame(0, $result['post_meta_failed']);
    }
}
