<?php

namespace Kargnas\LaravelAiTranslator;

use Kargnas\LaravelAiTranslator\AI\Language\Language;


class Utility
{
    /**
     * Get the plural forms for a given locale.
     *
     * The plural rules are derived from code of the Zend Framework (2010-09-25), which
     * is subject to the new BSD license (https://framework.zend.com/license)
     * Copyright (c) 2005-2010 - Zend Technologies USA Inc. (http://www.zend.com)
     *
     * @param string $locale
     * @param int $number
     * @return int
     */
    public static function getPluralForms($locale): ?int
    {
        $compare = function ($locale) {
            switch ($locale) {
                case 'az':
                case 'az_az':
                case 'bo':
                case 'bo_cn':
                case 'bo_in':
                case 'dz':
                case 'dz_bt':
                case 'id':
                case 'id_id':
                case 'ja':
                case 'ja_jp':
                case 'jv':
                case 'ka':
                case 'ka_ge':
                case 'km':
                case 'km_kh':
                case 'kn':
                case 'kn_in':
                case 'ko':
                case 'ko_kr':
                case 'ms':
                case 'ms_my':
                case 'th':
                case 'th_th':
                case 'tr':
                case 'tr_cy':
                case 'tr_tr':
                case 'vi':
                case 'vi_vn':
                case 'zh':
                case 'zh_cn':
                case 'zh_hk':
                case 'zh_sg':
                case 'zh_tw':
                    return 1;
                case 'af':
                case 'af_za':
                case 'bn':
                case 'bn_bd':
                case 'bn_in':
                case 'bg':
                case 'bg_bg':
                case 'ca':
                case 'ca_ad':
                case 'ca_es':
                case 'ca_fr':
                case 'ca_it':
                case 'da':
                case 'da_dk':
                case 'de':
                case 'de_at':
                case 'de_be':
                case 'de_ch':
                case 'de_de':
                case 'de_li':
                case 'de_lu':
                case 'el':
                case 'el_cy':
                case 'el_gr':
                case 'en':
                case 'en_ag':
                case 'en_au':
                case 'en_bw':
                case 'en_ca':
                case 'en_dk':
                case 'en_gb':
                case 'en_hk':
                case 'en_ie':
                case 'en_in':
                case 'en_ng':
                case 'en_nz':
                case 'en_ph':
                case 'en_sg':
                case 'en_us':
                case 'en_za':
                case 'en_zm':
                case 'en_zw':
                case 'eo':
                case 'eo_us':
                case 'es':
                case 'es_ar':
                case 'es_bo':
                case 'es_cl':
                case 'es_co':
                case 'es_cr':
                case 'es_cu':
                case 'es_do':
                case 'es_ec':
                case 'es_es':
                case 'es_gt':
                case 'es_hn':
                case 'es_mx':
                case 'es_ni':
                case 'es_pa':
                case 'es_pe':
                case 'es_pr':
                case 'es_py':
                case 'es_sv':
                case 'es_us':
                case 'es_uy':
                case 'es_ve':
                case 'et':
                case 'et_ee':
                case 'eu':
                case 'eu_es':
                case 'eu_fr':
                case 'fa':
                case 'fa_ir':
                case 'fi':
                case 'fi_fi':
                case 'fo':
                case 'fo_fo':
                case 'fur':
                case 'fur_it':
                case 'fy':
                case 'fy_de':
                case 'fy_nl':
                case 'gl':
                case 'gl_es':
                case 'gu':
                case 'gu_in':
                case 'ha':
                case 'ha_ng':
                case 'he':
                case 'he_il':
                case 'hu':
                case 'hu_hu':
                case 'is':
                case 'is_is':
                case 'it':
                case 'it_ch':
                case 'it_it':
                case 'ku':
                case 'ku_tr':
                case 'lb':
                case 'lb_lu':
                case 'ml':
                case 'ml_in':
                case 'mn':
                case 'mn_mn':
                case 'mr':
                case 'mr_in':
                case 'nah':
                case 'nb':
                case 'nb_no':
                case 'ne':
                case 'ne_np':
                case 'nl':
                case 'nl_aw':
                case 'nl_be':
                case 'nl_nl':
                case 'nn':
                case 'nn_no':
                case 'no':
                case 'om':
                case 'om_et':
                case 'om_ke':
                case 'or':
                case 'or_in':
                case 'pa':
                case 'pa_in':
                case 'pa_pk':
                case 'pap':
                case 'pap_an':
                case 'pap_aw':
                case 'pap_cw':
                case 'ps':
                case 'ps_af':
                case 'pt':
                case 'pt_br':
                case 'pt_pt':
                case 'so':
                case 'so_dj':
                case 'so_et':
                case 'so_ke':
                case 'so_so':
                case 'sq':
                case 'sq_al':
                case 'sq_mk':
                case 'sv':
                case 'sv_fi':
                case 'sv_se':
                case 'sw':
                case 'sw_ke':
                case 'sw_tz':
                case 'ta':
                case 'ta_in':
                case 'ta_lk':
                case 'te':
                case 'te_in':
                case 'tk':
                case 'tk_tm':
                case 'ur':
                case 'ur_in':
                case 'ur_pk':
                case 'zu':
                case 'zu_za':
                    return 2;
                case 'am':
                case 'am_et':
                case 'bh':
                case 'fil':
                case 'fil_ph':
                case 'fr':
                case 'fr_be':
                case 'fr_ca':
                case 'fr_ch':
                case 'fr_fr':
                case 'fr_lu':
                case 'gun':
                case 'hi':
                case 'hi_in':
                case 'hy':
                case 'hy_am':
                case 'ln':
                case 'ln_cd':
                case 'mg':
                case 'mg_mg':
                case 'nso':
                case 'nso_za':
                case 'ti':
                case 'ti_er':
                case 'ti_et':
                case 'wa':
                case 'wa_be':
                case 'xbr':
                    return 2;
                case 'be':
                case 'be_by':
                case 'bs':
                case 'bs_ba':
                case 'hr':
                case 'hr_hr':
                case 'ru':
                case 'ru_ru':
                case 'ru_ua':
                case 'sr':
                case 'sr_me':
                case 'sr_rs':
                case 'uk':
                case 'uk_ua':
                    return 3;
                case 'cs':
                case 'cs_cz':
                case 'sk':
                case 'sk_sk':
                    return 3;
                case 'ga':
                case 'ga_ie':
                    return 3;
                case 'lt':
                case 'lt_lt':
                    return 3;
                case 'sl':
                case 'sl_si':
                    return 4;
                case 'mk':
                case 'mk_mk':
                    return 2;
                case 'mt':
                case 'mt_mt':
                    return 4;
                case 'lv':
                case 'lv_lv':
                    return 3;
                case 'pl':
                case 'pl_pl':
                    return 3;
                case 'cy':
                case 'cy_gb':
                    return 4;
                case 'ro':
                case 'ro_ro':
                    return 3;
                case 'ar':
                case 'ar_ae':
                case 'ar_bh':
                case 'ar_dz':
                case 'ar_eg':
                case 'ar_in':
                case 'ar_iq':
                case 'ar_jo':
                case 'ar_kw':
                case 'ar_lb':
                case 'ar_ly':
                case 'ar_ma':
                case 'ar_om':
                case 'ar_qa':
                case 'ar_sa':
                case 'ar_sd':
                case 'ar_ss':
                case 'ar_sy':
                case 'ar_tn':
                case 'ar_ye':
                    return 6;
                default:
                    return null;
            }
        };

        $locale = Language::normalizeCode($locale);

        if ($result = $compare($locale)) {
            return $result;
        } else if ($result = $compare(substr($locale, 0, 2))) {
            return $result;
        } else if ($result = $compare(substr($locale, 0, 3))) {
            return $result;
        } else {
            return null;
        }
    }
}
