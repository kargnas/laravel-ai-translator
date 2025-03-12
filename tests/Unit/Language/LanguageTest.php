<?php

use Kargnas\LaravelAiTranslator\AI\Language\Language;

test('can create language instance', function () {
    $language = new Language('ko', 'Korean');

    expect($language->code)->toBe('ko');
    expect($language->name)->toBe('Korean');
});

test('can get plural forms', function () {
    $language = new Language('en', 'English', 2);
    expect($language->pluralForms)->toBe(2);

    $language = new Language('ja', 'Japanese', 1);
    expect($language->pluralForms)->toBe(1);
});

test('can create language with default plural forms', function () {
    $language = new Language('ko', 'Korean');
    expect($language->pluralForms)->toBe(2);
});

test('supports arabic complex plural forms', function () {
    $language = Language::fromCode('ar');
    expect($language->pluralForms)->toBe(6);

    // Test different Arabic locales
    $arLocales = ['ar_ae', 'ar_sa', 'ar_eg', 'ar_kw', 'ar_ma'];
    foreach ($arLocales as $locale) {
        $language = Language::fromCode($locale);
        expect($language->pluralForms)->toBe(6);
    }
});

test('distinguishes chinese variants', function () {
    // Simplified Chinese (Mainland China)
    $zhCN = Language::fromCode('zh_cn');
    expect($zhCN->code)->toBe('zh_cn');
    expect($zhCN->pluralForms)->toBe(1);

    // Test hyphenated format
    $zhCNHyphen = Language::fromCode('zh-cn');
    expect($zhCNHyphen->code)->toBe('zh_cn');
    expect($zhCNHyphen->pluralForms)->toBe(1);

    // Traditional Chinese (Hong Kong)
    $zhHK = Language::fromCode('zh_hk');
    expect($zhHK->code)->toBe('zh_hk');
    expect($zhHK->pluralForms)->toBe(1);

    // Test hyphenated format
    $zhHKHyphen = Language::fromCode('zh-hk');
    expect($zhHKHyphen->code)->toBe('zh_hk');
    expect($zhHKHyphen->pluralForms)->toBe(1);

    // Traditional Chinese (Taiwan)
    $zhTW = Language::fromCode('zh_tw');
    expect($zhTW->code)->toBe('zh_tw');
    expect($zhTW->pluralForms)->toBe(1);

    // Test hyphenated format
    $zhTWHyphen = Language::fromCode('zh-tw');
    expect($zhTWHyphen->code)->toBe('zh_tw');
    expect($zhTWHyphen->pluralForms)->toBe(1);

    // Base Chinese code should default to Simplified Chinese
    $zh = Language::fromCode('zh');
    expect($zh->code)->toBe('zh');
    expect($zh->pluralForms)->toBe(1);
});

test('normalizes language codes', function () {
    $testCases = [
        'zh-tw' => 'zh_tw',
        'zh_tw' => 'zh_tw',
        'ZH-TW' => 'zh_tw',
        'ZH_TW' => 'zh_tw',
        'zh-CN' => 'zh_cn',
        'zh_CN' => 'zh_cn',
        'ar-SA' => 'ar_sa',
        'ar_SA' => 'ar_sa',
    ];

    foreach ($testCases as $input => $expected) {
        $language = Language::fromCode($input);
        expect($language->code)->toBe($expected);
    }
});
