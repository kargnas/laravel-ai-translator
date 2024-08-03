<?php

namespace Kargnas\LaravelAiTranslator\Console;


use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Utility;

class TranslateStrings extends Command
{
    // en_us (all capital, underscore)
    protected static $additionalRules = [
        'zh' => [
            "- CRITICAL: For ALL Chinese translations, ALWAYS use exactly THREE parts if there is '|': 一 + measure word + noun|两 + measure word + noun|:count + measure word + noun. This is MANDATORY, even if the original only has two parts. NO SPACES in Chinese text except right after numbers in curly braces and square brackets.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} 一X词Y|{2} 两X词Y|[3,*] :countX词Y. Replace X with correct measure word, Y with noun. Ensure NO SPACE between :count and the measure word. If any incorrect spaces are found, remove them and flag for review.",
        ],
        'ko' => [
            // 1개, 2개 할 때 '1 개', '2 개' 이런식으로 써지는 것 방지
            "- Don't add a space between the number and the measure word with variables. Example: {1} 한 개|{2} 두 개|[3,*] :count개",
        ],
        // For fun -- North Korean style
        'ko_kp' => [
            "- 두음법칙 제거: 단어 첫음절의 'ㄹ'을 'ㄴ'으로 바꾸지 말고 유지하라. 'ㅣ'나 'ㅑ,ㅕ,ㅛ,ㅠ' 앞의 'ㄴ'을 'ㅇ'으로 바꾸지 말라. 예: 이(李) → 리, 여자 → 녀자, 냉면 → 랭면, 노력 → 로력, 뉴스 → 류스, 농업 → 농업(변경 없음), 임무 → 림무, 노동 → 로동",
            "- 한자어로 ㅇ과 ㄹ을 잘 구분해라. 예를들어 한국어는 '역량'이지만, 문화어는 '력량'이다. 추가 예시: 역사 → 력사, 연구 → 연구(변경 없음), 영향 → 영향(변경 없음), 이론 → 리론, 연령 → 년령, 용기 → 용기(변경 없음)",
            "- 종결어미 변경: '-습니다'를 '-ㅂ니다'로 대체하라. 예: 감사합니다 → 감사합니다, 공부합니다 → 공부합니다, 도착했습니다 → 도착하였습니다, 먹었습니다 → 먹었습니다",
            "- 과거시제 표현 변경: '-었-'을 '-였-'으로 대체하라. 이는 모음 조화와 관계없이 적용한다. 예: 되었다 → 되였다, 갔었다 → 갔였다, 먹었다 → 먹였다, 찾았다 → 찾았다(변경 없음)",
            "- 사이시옷 제거: 합성어에서 사이시옷을 사용하지 말라. 예: 핏줄 → 피줄, 곳간 → 고간, 잇몸 → 이몸, 깃발 → 기발, 햇살 → 해살",
            "- 의존명사 붙여쓰기: 의존명사를 앞 단어에 붙여 쓰라. 특히 '것'은 항상 앞말에 붙인다. 예: 먹을 것 → 먹을것, 갈 수 있는 → 갈수있는, 볼 만한 → 볼만한, 할 줄 아는 → 할줄아는",
            "- 북한식 호칭 사용: '동무', '동지' 등의 호칭을 상황에 맞게 사용하라. 직함과 함께 쓸 때는 이름 뒤에 붙인다. 예: 김철수동무, 박영희동지, 리철호선생, 김민국로동자동지",
            "- '되다' 표현 변경: '~게 되다'를 '~로 되다'로 대체하라. 예: 그렇게 되었다 → 그렇게로 되였다, 좋게 되었다 → 좋게로 되였다, 가게 되었다 → 가게로 되였다",
            "- 특수 어미 사용: '~기요', '~자요' 등의 어미를 적절히 사용하라. 이는 친근하고 부드러운 명령이나 제안을 표현할 때 쓴다. 예: 이제 그만하고 밥 좀 먹기요, 우리 집에 가자요, 여기서 기다리자요, 조용히 하기요",
            "- 외래어 북한식 변경: 외래어를 가능한 북한식으로 바꾸되, 없는 경우 그대로 사용한다. 예: 컴퓨터 → 전자계산기, 프린터 → 인쇄기, 마우스 → 마우스(변경 없음), 인터넷 → 콤퓨터망, 케이크 → 다식과자",
            "- IT 용어 변경: IT 관련 용어를 북한식으로 변경하라. 예: 프로그램 → 프로그람, 알고리즘 → 알고리듬, 픽셀 → 영상요소, 데이터베이스 → 자료기지, 소프트웨어 → 쏘프트웨어, 시스템 → 체계, 디지털 → 수자, 제어 → 조종, 휴대전화/핸드폰 → 무선대화기, 터미널 → 말단, 디지털 시계 → 수자시계, 원격 제어 → 원격조종, 스마트폰 → 지능형무선대화기, 컴퓨터 터미널 → 전자계산기말단",
            "- 정치적 표현 사용: 김일성, 김정일, 김정은 언급 시 '위대한 수령', '경애하는 최고령도자' 등의 표현을 사용하라. 예: 김정은 → 경애하는 최고령도자 김정은동지, 김일성 → 위대한 수령 김일성동지, 김정일 → 위대한 령도자 김정일동지",
            "- '하십시오' 변경: '하십시오'를 '하십시요'로 대체하라. 예: 말씀해 주십시오 → 말씀해 주십시요, 들어가십시오 → 들어가십시요",
            "- '있습니다' 변경: 격식있는 상황에서 '있습니다'를 '있사옵니다'로 대체할 수 있다. 예: 감사하고 있습니다 → 감사하고 있사옵니다, 기다리고 있습니다 → 기다리고 있사옵니다",
            "- 한자어 고유어 대체: 일부 한자어를 고유어로 대체하라. 예: 논리 → 론리, 연결 → 련결, 이론 → 리론, 결론 → 결론(변경 없음), 연구 → 연구(변경 없음), 업무 → 로동",
            "- 북한식 철자 변경: 특정 단어들의 철자를 북한식으로 변경하라. 예: 섣부르다 → 서뿌르다, 해돋이 → 해뜨이, 아름답다 → 아름답다(변경 없음), 빨갛다 → 붉다",
            "- 문장 부호 사용: 북한식 인용부호 《 》를 사용하라. 예: \"hello\" → 《hello》, '강조' → 《강조》, \"인용\" → 《인용》",
            "- 높임말 사용: 높임말을 더 자주 사용하며, 특히 정치적 인물 언급 시 철저히 지키라. 예: 김정은이 말했다 → 경애하는 최고령도자 김정은동지께서 말씀하시였다",
            "- 북한식 속담 사용: 가능한 경우 북한식 속담이나 관용구를 사용하라. 예: 소 잃고 외양간 고친다 → 말 잃고 마구간 고친다, 뛰는 놈 위에 나는 놈 있다 → 나는 놈 위에 또 나는 놈 있다",
            "- 한자어 '적(的)' 사용: '~적' 표현을 가능한 많이 사용하라. 예: 과학적, 혁명적 (예: 혁명적인 성과), 전략적, 창조적, 주체적",
            "- 가급적 한자어나 사자성어를 사용하여 북한이나 중국어 느낌이 나도록 해라. 예: 일석이조(一石二鳥), 백척간두(百尺竿頭), 주체사상(主體思想), 선군정치(先軍政治)",
        ],
    ];

    protected static $localeNames = [
        'aa' => 'Afar',
        'ab' => 'Abkhazian',
        'af' => 'Afrikaans',
        'am' => 'Amharic',
        'ar' => 'Arabic',
        'ar_ae' => 'Arabic (U.A.E.)',
        'ar_bh' => 'Arabic (Bahrain)',
        'ar_dz' => 'Arabic (Algeria)',
        'ar_eg' => 'Arabic (Egypt)',
        'ar_iq' => 'Arabic (Iraq)',
        'ar_jo' => 'Arabic (Jordan)',
        'ar_kw' => 'Arabic (Kuwait)',
        'ar_lb' => 'Arabic (Lebanon)',
        'ar_ly' => 'Arabic (Libya)',
        'ar_ma' => 'Arabic (Morocco)',
        'ar_om' => 'Arabic (Oman)',
        'ar_qa' => 'Arabic (Qatar)',
        'ar_sa' => 'Arabic (Saudi Arabia)',
        'ar_sy' => 'Arabic (Syria)',
        'ar_tn' => 'Arabic (Tunisia)',
        'ar_ye' => 'Arabic (Yemen)',
        'as' => 'Assamese',
        'ay' => 'Aymara',
        'az' => 'Azerí',
        'ba' => 'Bashkir',
        'be' => 'Belarusian',
        'bg' => 'Bulgarian',
        'bh' => 'Bihari',
        'bi' => 'Bislama',
        'bn' => 'Bengali',
        'bo' => 'Tibetan',
        'br' => 'Breton',
        'ca' => 'Catalan',
        'co' => 'Corsican',
        'cs' => 'Czech',
        'cy' => 'Welsh',
        'da' => 'Danish',
        'de' => 'German',
        'de_at' => 'German (Austria)',
        'de_ch' => 'German (Switzerland)',
        'de_li' => 'German (Liechtenstein)',
        'de_lu' => 'German (Luxembourg)',
        'div' => 'Divehi',
        'dz' => 'Bhutani',
        'el' => 'Greek',
        'en' => 'English',
        'en_au' => 'English (Australia)',
        'en_bz' => 'English (Belize)',
        'en_ca' => 'English (Canada)',
        'en_gb' => 'English (United Kingdom)',
        'en_ie' => 'English (Ireland)',
        'en_jm' => 'English (Jamaica)',
        'en_nz' => 'English (New Zealand)',
        'en_ph' => 'English (Philippines)',
        'en_tt' => 'English (Trinidad)',
        'en_us' => 'English (United States)',
        'en_za' => 'English (South Africa)',
        'en_zw' => 'English (Zimbabwe)',
        'eo' => 'Esperanto',
        'es' => 'Spanish',
        'es_ar' => 'Spanish (Argentina)',
        'es_bo' => 'Spanish (Bolivia)',
        'es_cl' => 'Spanish (Chile)',
        'es_co' => 'Spanish (Colombia)',
        'es_cr' => 'Spanish (Costa Rica)',
        'es_do' => 'Spanish (Dominican Republic)',
        'es_ec' => 'Spanish (Ecuador)',
        'es_es' => 'Spanish (España)',
        'es_gt' => 'Spanish (Guatemala)',
        'es_hn' => 'Spanish (Honduras)',
        'es_mx' => 'Spanish (Mexico)',
        'es_ni' => 'Spanish (Nicaragua)',
        'es_pa' => 'Spanish (Panama)',
        'es_pe' => 'Spanish (Peru)',
        'es_pr' => 'Spanish (Puerto Rico)',
        'es_py' => 'Spanish (Paraguay)',
        'es_sv' => 'Spanish (El Salvador)',
        'es_us' => 'Spanish (United States)',
        'es_uy' => 'Spanish (Uruguay)',
        'es_ve' => 'Spanish (Venezuela)',
        'et' => 'Estonian',
        'eu' => 'Basque',
        'fa' => 'Farsi',
        'fi' => 'Finnish',
        'fj' => 'Fiji',
        'fo' => 'Faeroese',
        'fr' => 'French',
        'fr_be' => 'French (Belgium)',
        'fr_ca' => 'French (Canada)',
        'fr_ch' => 'French (Switzerland)',
        'fr_lu' => 'French (Luxembourg)',
        'fr_mc' => 'French (Monaco)',
        'fy' => 'Frisian',
        'ga' => 'Irish',
        'gd' => 'Gaelic',
        'gl' => 'Galician',
        'gn' => 'Guarani',
        'gu' => 'Gujarati',
        'ha' => 'Hausa',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'hr' => 'Croatian',
        'hu' => 'Hungarian',
        'hy' => 'Armenian',
        'ia' => 'Interlingua',
        'id' => 'Indonesian',
        'ie' => 'Interlingue',
        'ik' => 'Inupiak',
        'in' => 'Indonesian',
        'is' => 'Icelandic',
        'it' => 'Italian',
        'it_ch' => 'Italian (Switzerland)',
        'iw' => 'Hebrew',
        'ja' => 'Japanese',
        'ji' => 'Yiddish',
        'jw' => 'Javanese',
        'ka' => 'Georgian',
        'kk' => 'Kazakh',
        'kl' => 'Greenlandic',
        'km' => 'Cambodian',
        'kn' => 'Kannada',
        'ko' => 'Korean',
        'ko_kr' => 'Korean (South Korea)',
        'ko_kp' => 'Korean (North Korea)',
        'kok' => 'Konkani',
        'ks' => 'Kashmiri',
        'ku' => 'Kurdish',
        'ky' => 'Kirghiz',
        'kz' => 'Kyrgyz',
        'la' => 'Latin',
        'ln' => 'Lingala',
        'lo' => 'Laothian',
        'ls' => 'Slovenian',
        'lt' => 'Lithuanian',
        'lv' => 'Latvian',
        'mg' => 'Malagasy',
        'mi' => 'Maori',
        'mk' => 'FYRO Macedonian',
        'ml' => 'Malayalam',
        'mn' => 'Mongolian',
        'mo' => 'Moldavian',
        'mr' => 'Marathi',
        'ms' => 'Malay',
        'mt' => 'Maltese',
        'my' => 'Burmese',
        'na' => 'Nauru',
        'nb_no' => 'Norwegian (Bokmal)',
        'ne' => 'Nepali (India)',
        'nl' => 'Dutch',
        'nl_be' => 'Dutch (Belgium)',
        'nn_no' => 'Norwegian',
        'no' => 'Norwegian (Bokmal)',
        'oc' => 'Occitan',
        'om' => '(Afan)/Oromoor/Oriya',
        'or' => 'Oriya',
        'pa' => 'Punjabi',
        'pl' => 'Polish',
        'ps' => 'Pashto/Pushto',
        'pt' => 'Portuguese',
        'pt_br' => 'Portuguese (Brazil)',
        'qu' => 'Quechua',
        'rm' => 'Rhaeto_Romanic',
        'rn' => 'Kirundi',
        'ro' => 'Romanian',
        'ro_md' => 'Romanian (Moldova)',
        'ru' => 'Russian',
        'ru_md' => 'Russian (Moldova)',
        'rw' => 'Kinyarwanda',
        'sa' => 'Sanskrit',
        'sb' => 'Sorbian',
        'sd' => 'Sindhi',
        'sg' => 'Sangro',
        'sh' => 'Serbo_Croatian',
        'si' => 'Singhalese',
        'sk' => 'Slovak',
        'sl' => 'Slovenian',
        'sm' => 'Samoan',
        'sn' => 'Shona',
        'so' => 'Somali',
        'sq' => 'Albanian',
        'sr' => 'Serbian',
        'ss' => 'Siswati',
        'st' => 'Sesotho',
        'su' => 'Sundanese',
        'sv' => 'Swedish',
        'sv_fi' => 'Swedish (Finland)',
        'sw' => 'Swahili',
        'sx' => 'Sutu',
        'syr' => 'Syriac',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'tg' => 'Tajik',
        'th' => 'Thai',
        'ti' => 'Tigrinya',
        'tk' => 'Turkmen',
        'tl' => 'Tagalog',
        'tn' => 'Tswana',
        'to' => 'Tonga',
        'tr' => 'Turkish',
        'ts' => 'Tsonga',
        'tt' => 'Tatar',
        'tw' => 'Twi',
        'uk' => 'Ukrainian',
        'ur' => 'Urdu',
        'us' => 'English',
        'uz' => 'Uzbek',
        'vi' => 'Vietnamese',
        'vo' => 'Volapuk',
        'wo' => 'Wolof',
        'xh' => 'Xhosa',
        'yi' => 'Yiddish',
        'yo' => 'Yoruba',
        'zh' => 'Chinese',
        'zh_cn' => 'Chinese (China Mainland)',
        'zh_hk' => 'Chinese (Hong Kong SAR)',
        'zh_mo' => 'Chinese (Macau SAR)',
        'zh_sg' => 'Chinese (Singapore)',
        'zh_tw' => 'Chinese (Taiwan)',
        'zu' => 'Zulu',
    ];

    protected $signature = 'ai-translator:translate';

    protected $sourceLocale;
    protected $sourceDirectory;
    protected $chunkSize;
    protected array $referenceLocales = [];

    public function __construct() {
        parent::__construct();
        $this->setDescription(
            "Translates all PHP language files in this directory: " . config('ai-translator.source_directory') .
            "\n  Source Locale: " . config('ai-translator.source_locale'),
        );
    }

    public function handle() {
        $this->sourceDirectory = config('ai-translator.source_directory');

        $this->sourceLocale = $this->choiceLanguages("Choose a source language to translate from", false, 'en');

        if ($this->ask('Do you want to add reference languages? (y/n)', 'n') === 'y') {
            $this->referenceLocales = $this->choiceLanguages("Choose a language to reference when translating, preferably one that has already been vetted and translated to a high quality. You can select multiple languages via ',' (e.g. '1, 2')", true);
        }

        $this->chunkSize = $this->ask("Enter the chunk size for translation. Translate strings in a batch. The higher, the cheaper. (default: 30)", 30);
        $this->translate();
    }

    protected static function getLanguageName($originalLocale): ?string {
        $list = array_merge(self::$localeNames, config('ai-translator.locale_names'));

        $locale = strtolower(str_replace('-', '_', $originalLocale));

        if (key_exists($originalLocale, $list)) {
            return $list[$originalLocale];
        } else if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            \Log::warning("Language name not found for locale: {$locale}. Please add it to the config file.");
            return null;
        }
    }

    private static function getAdditionalRulesFromConfig($originalLocale): array {
        $list = config('ai-translator.additional_rules');
        $locale = strtolower(str_replace('-', '_', $originalLocale));

        if (key_exists($originalLocale, $list)) {
            return $list[$originalLocale];
        } else if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return $list['default'] ?? [];
        }
    }

    private static function getAdditionalRulesDefault($locale): array {
        $list = static::$additionalRules;
        $locale = strtolower(str_replace('-', '_', $locale));

        if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return $list['default'] ?? [];
        }
    }

    private static function getAdditionalRulesPlural($locale) {
        $plural = Utility::getPluralForms($locale);
        if (!$plural) return [];

        return match ($plural) {
            1 => [
                "- Pluralization Rules",
                "  - Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "  - For plurals, always use the format: {1} singular|[2,*] plural.",
                "  - Example structure (DO NOT COPY WORDS, only structure): {1} singular|[2,*] plural",
                "  - Consider language-specific features like gender, case, and measure words when applicable.",
            ],
            2 => [
                "- Pluralization Rules",
                "  - Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "  - Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.",
            ],
            3 => [
                "- Pluralization Rules",
                "  - Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "  - Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                "    - Always use: singular|few|many",
                "    - Apply this to ALL nouns, regular or irregular",
                "  - Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.",
            ],
            4 => [
                "- Pluralization Rules",
                "  - Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "  - Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                "    - Always use: singular|dual|few|many",
                "    - Apply this to ALL nouns, regardless of their original plural formation",
                "  - Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.",
            ],
            6 => [
                "- Pluralization Rules",
                "  - Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "  - Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                "    - Always use: zero|one|two|few|many|other",
                "    - Apply this to ALL nouns, regardless of their original plural formation",
                "  - Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.",
            ],
            default => [],
        };
    }

    protected static function getAdditionalRules($locale): array {
        return array_merge(static::getAdditionalRulesFromConfig($locale), static::getAdditionalRulesDefault($locale), static::getAdditionalRulesPlural($locale));
    }

    public function choiceLanguages($question, $multiple, $default = null) {
        $locales = $this->getExistingLocales();

        $selectedLocales = $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple);

        if (is_array($selectedLocales)) {
            $this->info("Selected locales: " . implode(', ', $selectedLocales));
        } else {
            $this->info("Selected locale: " . $selectedLocales);
        }

        return $selectedLocales;
    }

    public function translate() {
        $locales = $this->getExistingLocales();
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale) {
                continue;
            }

            $targetLanguageName = static::getLanguageName($locale);

            if ($targetLanguageName) {
                $this->info("Starting {$targetLanguageName} ({$locale})");
            } else {
                throw new \Exception("Language name not found for locale: {$locale}. Please add it to the config file.");
            }

            $files = $this->getStringFilePaths($this->sourceLocale);
            foreach ($files as $file) {
                $outputFile = $this->getOutputDirectoryLocale($locale) . '/' . basename($file);
                $this->info("> Translating {$file} to {$locale} => {$outputFile}");
                $transformer = new PHPLangTransformer($file);
                $sourceStringList = $transformer->flatten();
                $targetStringTransformer = new PHPLangTransformer($outputFile);

                // Filter for untranslated strings
                $sourceStringList = collect($sourceStringList)
                    ->filter(function ($value, $key) use ($targetStringTransformer) {
                        // Skip if already translated
                        return !$targetStringTransformer->isTranslated($key);
                    })
                    ->toArray();

                $referenceStringList = collect($this->referenceLocales)
                    ->filter(fn($referenceLocale) => !in_array($referenceLocale, [$locale, $this->sourceLocale]))
                    ->mapWithKeys(function ($referenceLocale) use ($file, $targetStringTransformer) {
                        $referenceFile = $this->getOutputDirectoryLocale($referenceLocale) . '/' . basename($file);
                        $referenceTransformer = new PHPLangTransformer($referenceFile);
                        return [
                            $referenceLocale => $referenceTransformer->flatten(),
                        ];
                    })
                    ->toArray();

                if (sizeof($sourceStringList) > 100) {
                    if (!$this->confirm("{$outputFile}, Strings: " . sizeof($sourceStringList) . " -> Many strings to translate. Could be expensive. Continue?")) {
                        $this->warn("Stopped translating!");
                        exit;
                    }
                }

                // Chunk the strings because of the pricing
                // But also this will increase the speed of the translation, and quality of continuous translation
                collect($sourceStringList)
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($locale, $file, $targetStringTransformer, $referenceStringList) {
                        $translator = new AIProvider(
                            filename: $file,
                            strings: $chunk->mapWithKeys(function ($item, $key) use ($referenceStringList) {
                                return [
                                    $key => [
                                        'text' => $item,
                                        'references' => collect($referenceStringList)->map(function ($items) use ($key) {
                                            return $items[$key] ?? "";
                                        })->filter(function ($value) {
                                            return strlen($value) > 0;
                                        }),
                                    ],
                                ];
                            })->toArray(),
                            sourceLanguage: static::getLanguageName($this->sourceLocale) ?? $this->sourceLocale,
                            targetLanguage: static::getLanguageName($locale) ?? $locale,
                            additionalRules: static::getAdditionalRules($locale),
                        );

                        $items = $translator->translate();

                        foreach ($items as $item) {
                            \Log::debug('Saving: ' . $item->key . ' => ' . $item->translated);
                            $targetStringTransformer->updateString($item->key, $item->translated);
                        }
                    });
            }

            $this->info("Finished translating $locale");
        }
    }

    /**
     * @return array|string[]
     */
    public function getExistingLocales(): array {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // only directories
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root . '/' . $directory);
        });
        return collect($directories)->values()->toArray();
    }

    public function getOutputDirectoryLocale($locale) {
        return config('ai-translator.source_directory') . '/' . $locale;
    }

    public function getStringFilePaths($locale) {
        $files = [];
        $root = $this->sourceDirectory . '/' . $locale;
        $directories = array_diff(scandir($root), ['.', '..']);
        foreach ($directories as $directory) {
            // only .php
            if (pathinfo($directory, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $files[] = $root . '/' . $directory;
        }
        return $files;
    }
}
