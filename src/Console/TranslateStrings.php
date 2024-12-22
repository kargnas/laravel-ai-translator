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
            "# 조선어(문화어) 변환 규칙",
            "## 1. 자모 및 철자 규칙",
            "- **자모 순서 차이**",
            "  - 초성: ㄱ → ㄴ → ㄷ → ㄹ → … 순서, 쌍자음은 ‘쌍기역’ 대신 ‘된기윽’ 등으로 명명",
            "  - 중성: ㅏ → ㅑ → ㅓ → ㅕ … (배열 차이 존재)",
            "- **두음 법칙 배제**",
            "  - 한국어에서 ‘이, 여, 요’처럼 변하는 단어를 그대로 ㄴ/ㄹ 표기로 유지",
            "  - 예) *이승만* → **리승만**, *양력* → **량력**, *노인* → **로인**",
            "**(참고: 한자어 시작에 ㄴ/ㄹ 그대로 쓰는 관습 유지)**",
            "## 2. 받침 및 된소리 활용",
            "- **된소리 표기**",
            "  - 한국어 ‘색깔’ → 조선어 ‘색갈’, ‘밟다’ → ‘밟다’(표기는 동일하나 발음은 [밥따])",
            "- **합성어 받침**",
            "  - 합성 시 원형 유지, 발음에서만 된소리화 허용",
            "  - 예) *손뼉* → **손벽**, 실제 발음 [손뼉/손뼉] 가능",
            "- **부정 표기된 자음**",
            "  - ㅅ, ㅆ, ㄷ, ㅈ 등은 받침 뒤 결합 시 된소리로 취급",
            "  - 예) *웃으며* → [우드며/웃으며] (표기는 그대로)",
            "## 3. 띄여쓰기(띄어쓰기) 상세",
            "1. **의존 명사(단위 포함)는 앞말에 붙임**",
            "   - 예) *5 개월 동안* → **5개월동안**",
            "2. **합성 동사·보조 동사**",
            "   - 독립 의미가 약하면 붙여 씀",
            "   - 예) *안아 주다* → **안아주다**, *먹어 보다* → **먹어보다**",
            "3. **고유명사 결합**",
            "   - 예) *조선로동당 중앙위원회 평양시위원회* → **조선로동당 중앙위원회 평양시위원회** (단위마다 띄우기)",
            "> **주의**: 한국어보다 전반적 ‘붙여쓰기’ 경향이 강함",
            "## 4. 어간·어미 차이",
            "- **‘-어, -었’ vs ‘-여, -였’**",
            "  - ㅣ·ㅐ·ㅔ 등 뒤는 ‘-여, -였’으로 적는 경우 많음",
            "  - 예) *되어*→**되여**, *고쳐*→**고치여**(발음 [고치여/고쳐])",
            "- **형용사·동사 활용**",
            "  - 한국어와 기본 유사하나, 두음법칙 관련 용언 변형에서 ‘ㄹ/ㄴ’ 유지",
            "  - 예) *날라가다* → **날라가다** (표기 동일, 발음도 같거나 [날라가다])",
            "## 5. 부정 표현, 높임·경어",
            "- **부정 표현**",
            "  - ‘일없다’ = ‘괜찮다’, 그 외 *아니하다*·*못하다*도 그대로 씀",
            "  - 예) *괜찮아?* → **일없니?**",
            "- **경어체**",
            "  - ‘-오, -소, -요’ 등을 자주 씀",
            "  - 예) “배고프오?” / “그렇소.” / “일없어요.”",
            "- **하오체, 하십시오체, 해요체** 모두 존재",
            "## 6. 외래어·한자어 표기",
            "1. **영어·러시아어 등 외래어**",
            "   - 영어: *computer* → **콤퓨터**, *apartment* → **아빠트**",
            "   - 일제강점기 유래 외래어도 일부 잔존(‘삐라’, ‘빠다’, ‘뽀오성(볼링)’ 등)",
            "2. **한자어**",
            "   - 두음 ㄹ·ㄴ을 탈락시키지 않음",
            "   - 예) *녹두* → **록두**, *예외* → **례외**",
            "3. **고유어로 대체**",
            "   - *채소*→**남새**, *설탕*→**사탕가루**, 허나 혼용 가능",
            "4. **국제적 용어**",
            "   - ‘텐트, 택시, 토마토’ 등 굳어진 외래어는 그대로 쓰기도 함",
            "## 7. 추가 세부 규칙",
            "- **합성명사 원형 보존**",
            "  - 예) *가을걷이* → **가을걷이**, *별빛* → **별빛** (큰 변화 없음이나 받침 표기 유의)",
            "- **고유명사 표기**",
            "  - 외국 지명: 대체로 현지음 번역 (프랑스→뻐랑스 등), 일부 러시아·독일식 그대로 (독일→도이췰란드)",
            "- **사이시옷**",
            "  - 거의 쓰이지 않음. *뱃사공*→**배사공**, *댓잎*→**대잎**",
            "- **문장부호법**",
            "  - 한국어와 유사하나, 《 》 인용부 많이 사용",
            "  - 예) “안녕?” → **《안녕?》** (보도·문헌체에서)",
            "## 8. 예문 3가지 (3열=특이사항/한자)",
            "| **한국어**             | **조선어**                | **특이참고사항**                      |",
            "|------------------------|---------------------------|---------------------------------------|",
            "| 1) “이사 갈 건데, 괜찮아?”    | “리사 갈건데, 일없니?”         | ‘이사(移徙)’→‘리사’ (두음ㄹ 유지)       |",
            "| 2) “채소를 좀 사 왔어.”       | “남새를 좀 사왔소.”            | ‘채소’→‘남새’ / 붙여쓰기(사왔소)         |",
            "| 3) “그 사람이 영리하긴 하지만, 잘난 척 좀 해.” | “그 사람이 령리하긴 하나, 잘난체 좀 하오.” | ‘영리(英利)’→‘령리’ (두음법칙X), 경어 ‘하오’ |",
            "## 9. 변환시 유의사항 정리",
            "1. **두음법칙** 완전 배제 → ㄹ·ㄴ 어두 유지",
            "2. **자주 사용**: ‘일없다(괜찮다)’, ‘남새(채소)’, ‘아빠트(아파트)’ 등",
            "3. **띄여쓰기**는 의미 단위로 확장, 의존명사는 앞말에 붙이기",
            "4. **발음상 된소리** 가능하나 표기는 본형 유지 (색갈, 첫날 등)",
            "5. **고유한 발음·표기**: 《 》 인용, -소·-오·-니 등 종결 어미 활용",
            "6. **외래어·한자어 처리**: 굳어진 말(‘텔레비죤’, ‘전투’) 그대로",
            "# 추가 특별 규칙들",
            "- 혁명적이고 전투적인 말투로 설명하세요. '혁명적인'이라는 표현을 자주 쓰면 조선어처럼 보입니다.",
            "- 과거시제 표현 변경: '-었-'을 '-였-'으로 대체하라. 이는 모음 조화와 관계없이 적용한다. 예: 되었다 → 되였다, 갔었다 → 갔였다, 먹었다 → 먹였다, 찾았다 → 찾았다(변경 없음)",
            "- 사이시옷 제거: 합성어에서 사이시옷을 사용하지 말라. 예: 핏줄 → 피줄, 곳간 → 고간, 잇몸 → 이몸, 깃발 → 기발, 햇살 → 해살",
            "- 북한식 호칭 사용: '동무', '동지' 등의 호칭을 상황에 맞게 사용하라. 직함과 함께 쓸 때는 이름 뒤에 붙인다. 예: 김철수동무, 박영희동지, 리철호선생, 김민국로동자동지",
            "- 정치적 표현 사용: 김일성, 김정일, 김정은 언급 시 '위대한 수령', '경애하는 최고령도자' 등의 표현을 사용하라. 예: 김정은 → 경애하는 최고령도자 김정은동지, 김일성 → 위대한 수령 김일성동지, 김정일 → 위대한 령도자 김정일동지",
            "- 한자어 '적(的)' 사용: '~적' 표현을 가능한 많이 사용하라. 예: 과학적, 혁명적 (예: 혁명적인 성과), 전략적, 창조적, 주체적",
            "- IT 용어 예시, 괄호로 한국어 설명을 참고로 제공한다: 찰칵(클릭), 주체년도, 우리 식, 예/아니(버튼), 통과암호, 짧은 이름(닉네임), 망(네트워크), 콤퓨터, 봉사기(서버), 날자(날짜), X분 정도(약 X분), ~적으로(~으로), 웃부분(윗부분), 프로그람(프로그램), 차림표(메뉴), 동태(현황), 오유(오류), 페지(페이지), 소프트웨어(쏘프트웨어), 례외(예외), 등록가입(가입), 알고리듬, 자료가지(데이터베이스), 체계(시스템), 조종(제어)",
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

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(
            "Translates all PHP language files in this directory: " . config('ai-translator.source_directory') .
            "\n  Source Locale: " . config('ai-translator.source_locale'),
        );
    }

    public function handle()
    {
        $this->sourceDirectory = config('ai-translator.source_directory');

        $this->sourceLocale = $this->choiceLanguages("Choose a source language to translate from", false, 'en');

        if ($this->ask('Do you want to add reference languages? (y/n)', 'n') === 'y') {
            $this->referenceLocales = $this->choiceLanguages("Choose a language to reference when translating, preferably one that has already been vetted and translated to a high quality. You can select multiple languages via ',' (e.g. '1, 2')", true);
        }

        $this->chunkSize = $this->ask("Enter the chunk size for translation. Translate strings in a batch. The higher, the cheaper. (default: 30)", 30);
        $this->translate();
    }

    protected static function getLanguageName($originalLocale): ?string
    {
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

    private static function getAdditionalRulesFromConfig($originalLocale): array
    {
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

    private static function getAdditionalRulesDefault($locale): array
    {
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

    private static function getAdditionalRulesPlural($locale)
    {
        $plural = Utility::getPluralForms($locale);
        if (!$plural)
            return [];

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

    protected static function getAdditionalRules($locale): array
    {
        return array_merge(static::getAdditionalRulesFromConfig($locale), static::getAdditionalRulesDefault($locale), static::getAdditionalRulesPlural($locale));
    }

    public function choiceLanguages($question, $multiple, $default = null)
    {
        $locales = $this->getExistingLocales();

        $selectedLocales = $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple
        );

        if (is_array($selectedLocales)) {
            $this->info("Selected locales: " . implode(', ', $selectedLocales));
        } else {
            $this->info("Selected locale: " . $selectedLocales);
        }

        return $selectedLocales;
    }

    public function translate()
    {
        $locales = $this->getExistingLocales();
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale) {
                continue;
            }

            if (in_array($locale, config('ai-translator.skip_locales', []))) {
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
    public function getExistingLocales(): array
    {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // only directories
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root . '/' . $directory);
        });
        return collect($directories)->values()->toArray();
    }

    public function getOutputDirectoryLocale($locale)
    {
        return config('ai-translator.source_directory') . '/' . $locale;
    }

    public function getStringFilePaths($locale)
    {
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
