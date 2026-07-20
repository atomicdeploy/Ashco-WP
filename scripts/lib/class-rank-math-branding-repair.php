<?php
namespace AtomicDeploy\Ashco\Maintenance;

final class Rank_Math_Branding_Repair {
    private const OPTIONS_KEY = 'rank-math-options-titles';

    /**
     * Exact production rows approved for one-time cleanup.
     *
     * Each tuple is: post ID, exact SKU, meta key, exact old value.
     */
    private const PRODUCT_META_REPAIRS = array(
        array(17596, 'MQ2', 'rank_math_title', 'title% | %ماژول MQ2 | خرید سنسور گاز و دود MQ2 با بهترین قیمت'),
        array(17589, 'KO 6-1', 'rank_math_facebook_title', 'title%VOLTMETR AMPERMETR%'),
        array(17625, 'BA33', 'rank_math_title', 'title% | ماژول بلوتوث صوتی mh-m38%'),
        array(17647, 'G016', 'rank_math_title', 'title% |MP3 PLAYER G016%'),
        array(17672, 'BA34', 'rank_math_title', 'title% | Ashcماژول ساعت و دماسنج و  ولتمتر قرمزRX8025BT Electronic%'),
        array(17589, 'KO 6-1', 'rank_math_title', 'title%|VOLTMETR AMPERMETR 100V 100A%'),
        array(17678, 'BA36', 'rank_math_title', 'title% |ماژول پخش صدا DY-SV17F با حافظه داخلی%'),
        array(17684, 'UNO4', 'rank_math_title', 'title% | برد اردوینو Arduino UNO R4 MINIMA%'),
        array(17689, 'MQ135', 'rank_math_title', 'title% | %ماژول MQ135 سنسور تشخیص کیفیت و آلودگی هوا'),
        array(17693, '1602BLUE', 'rank_math_title', 'title% | LCD 2*16 BLUE%'),
        array(17697, '1602GREEN', 'rank_math_title', 'title% |LCD 2*16 GREEN%'),
        array(17700, '1604GREEN', 'rank_math_title', 'title% | LCD 4*16 GREEN%'),
        array(17704, '1604BLUE', 'rank_math_title', 'title% | LCD 4*16 GREEN%'),
        array(17707, '64128BLUE', 'rank_math_title', 'title% | LCD 64*128 BLUE%'),
        array(17720, 'BA35', 'rank_math_title', 'title% | پروگرامر ST-LINK V2%'),
        array(17723, 'BA39', 'rank_math_title', 'title% | LM2596+XL6019 MODULE%'),
        array(17751, 'SOLAR', 'rank_math_title', 'title% | SOLAR PANEL 6V 200MA%'),
        array(17755, 'XL6009', 'rank_math_title', 'title% |XL6009 E1%'),
        array(17774, 'W132', 'rank_math_title', 'title% | ماژول باک بوست HW-132%'),
        array(17591, 'MICRO037', 'rank_math_title', ' ماژول میکروفن KY-038 | %title%  '),
    );

    public static function run(): array {
        $result = array(
            'option_fields_updated' => 0,
            'post_meta_candidates' => count(self::PRODUCT_META_REPAIRS),
            'post_meta_deleted' => 0,
            'post_meta_skipped' => 0,
            'post_meta_failed' => 0,
        );

        $options = get_option(self::OPTIONS_KEY, array());
        if (is_array($options)) {
            $changed = false;
            if (isset($options['pt_product_title']) && 'title% | Ashco Electronic%' === $options['pt_product_title']) {
                $options['pt_product_title'] = '%title% | Ashco Electronic';
                $result['option_fields_updated']++;
                $changed = true;
            }
            if (isset($options['pt_product_description']) && '%exceorpt%' === $options['pt_product_description']) {
                $options['pt_product_description'] = '%excerpt%';
                $result['option_fields_updated']++;
                $changed = true;
            }
            if ($changed) {
                update_option(self::OPTIONS_KEY, $options, false);
            }
        }

        foreach (self::PRODUCT_META_REPAIRS as $repair) {
            [$post_id, $sku, $meta_key, $bad_value] = $repair;
            if ('product' !== get_post_type($post_id)
                || $sku !== (string) get_post_meta($post_id, '_sku', true)
                || $bad_value !== (string) get_post_meta($post_id, $meta_key, true)) {
                $result['post_meta_skipped']++;
                continue;
            }
            if (delete_post_meta($post_id, $meta_key, $bad_value)) {
                $result['post_meta_deleted']++;
            } else {
                $result['post_meta_failed']++;
            }
        }

        return $result;
    }
}
