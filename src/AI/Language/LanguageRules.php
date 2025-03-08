<?php

namespace Kargnas\LaravelAiTranslator\AI\Language;

class LanguageRules
{
    protected static array $additionalRules = [
        'zh' => [
            "- CRITICAL: For ALL Chinese translations, ALWAYS use exactly THREE parts if there is '|': 一 + measure word + noun|两 + measure word + noun|:count + measure word + noun. This is MANDATORY, even if the original only has two parts. NO SPACES in Chinese text except right after numbers in curly braces and square brackets.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} 一X词Y|{2} 两X词Y|[3,*] :countX词Y. Replace X with correct measure word, Y with noun. Ensure NO SPACE between :count and the measure word. If any incorrect spaces are found, remove them and flag for review.",
        ],
        'ko' => [
            "- Don't add a space between the number and the measure word with variables. Example: ':count개' instead of ':count 개'",
        ],
        'ko_kr' => [
            "- 한국의 인터넷 서비스 '토스'의 서비스 말투 처럼, 유저에게 친근하고 직관적인 말투로 설명하고 존댓말로 설명하세요.",
        ],
        'ko_kp' => [
            "# 조선어(문화어) 변환 규칙",
            "## 1. 자모 및 철자 규칙",
            "- **자모 순서 차이**",
            "  - 초성: ㄱ → ㄴ → ㄷ → ㄹ → … 순서, 쌍자음은 '쌍기역' 대신 '된기윽' 등으로 명명",
            "  - 중성: ㅏ → ㅑ → ㅓ → ㅕ … (배열 차이 존재)",
            "- **두음 법칙 배제**",
            "  - 한국어에서 '이, 여, 요'처럼 변하는 단어를 그대로 ㄴ/ㄹ 표기로 유지",
            "  - 예) *이승만* → **리승만**, *양력* → **량력**, *노인* → **로인**",
            "**(참고: 한자어 시작에 ㄴ/ㄹ 그대로 쓰는 관습 유지)**",
            "## 2. 받침 및 된소리 활용",
            "- **된소리 표기**",
            "  - 한국어 '색깔' → 조선어 '색갈', '밟다' → '밟다'(표기는 동일하나 발음은 [밥따])",
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
            "> **주의**: 한국어보다 전반적 '붙여쓰기' 경향이 강함",
            "## 4. 어간·어미 차이",
            "- **'-어, -었' vs '-여, -였'**",
            "  - ㅣ·ㅐ·ㅔ 등 뒤는 '-여, -였'으로 적는 경우 많음",
            "  - 예) *되어*→**되여**, *고쳐*→**고치여**(발음 [고치여/고쳐])",
            "- **형용사·동사 활용**",
            "  - 한국어와 기본 유사하나, 두음법칙 관련 용언 변형에서 'ㄹ/ㄴ' 유지",
            "  - 예) *날라가다* → **날라가다** (표기 동일, 발음도 같거나 [날라가다])",
            "## 5. 부정 표현, 높임·경어",
            "- **부정 표현**",
            "  - '일없다' = '괜찮다', 그 외 *아니하다*·*못하다*도 그대로 씀",
            "  - 예) *괜찮아?* → **일없니?**",
            "- **경어체**",
            "  - '-오, -소, -요' 등을 자주 씀",
            "  - 예) \"배고프오?\" / \"그렇소.\" / \"일없어요.\"",
            "- **하오체, 하십시오체, 해요체** 모두 존재",
            "## 6. 외래어·한자어 표기",
            "1. **영어·러시아어 등 외래어**",
            "   - 영어: *computer* → **콤퓨터**, *apartment* → **아빠트**",
            "   - 일제강점기 유래 외래어도 일부 잔존('삐라', '빠다', '뽀오성(볼링)' 등)",
            "2. **한자어**",
            "   - 두음 ㄹ·ㄴ을 탈락시키지 않음",
            "   - 예) *녹두* → **록두**, *예외* → **례외**",
            "3. **고유어로 대체**",
            "   - *채소*→**남새**, *설탕*→**사탕가루**, 허나 혼용 가능",
            "4. **국제적 용어**",
            "   - '텐트, 택시, 토마토' 등 굳어진 외래어는 그대로 쓰기도 함",
            "## 7. 추가 세부 규칙",
            "- **합성명사 원형 보존**",
            "  - 예) *가을걷이* → **가을걷이**, *별빛* → **별빛** (큰 변화 없음이나 받침 표기 유의)",
            "- **고유명사 표기**",
            "  - 외국 지명: 대체로 현지음 번역 (프랑스→뻐랑스 등), 일부 러시아·독일식 그대로 (독일→도이췰란드)",
            "- **사이시옷**",
            "  - 거의 쓰이지 않음. *뱃사공*→**배사공**, *댓잎*→**대잎**",
            "- **문장부호법**",
            "  - 한국어와 유사하나, 《 》 인용부 많이 사용",
            "  - 예) \"안녕?\" → **《안녕?》** (보도·문헌체에서)",
            "## 8. 예문 3가지 (3열=특이사항/한자)",
            "| **한국어**             | **조선어**                | **특이참고사항**                      |",
            "|------------------------|---------------------------|---------------------------------------|",
            "| 1) \"이사 갈 건데, 괜찮아?\"    | \"리사 갈건데, 일없니?\"         | '이사(移徙)'→'리사' (두음ㄹ 유지)       |",
            "| 2) \"채소를 좀 사 왔어.\"       | \"남새를 좀 사왔소.\"            | '채소'→'남새' / 붙여쓰기(사왔소)         |",
            "| 3) \"그 사람이 영리하긴 하지만, 잘난 척 좀 해.\" | \"그 사람이 령리하긴 하나, 잘난체 좀 하오.\" | '영리(英利)'→'령리' (두음법칙X), 경어 '하오' |",
            "## 9. 변환시 유의사항 정리",
            "1. **두음법칙** 완전 배제 → ㄹ·ㄴ 어두 유지",
            "2. **자주 사용**: '일없다(괜찮다)', '남새(채소)', '아빠트(아파트)' 등",
            "3. **띄여쓰기**는 의미 단위로 확장, 의존명사는 앞말에 붙이기",
            "4. **발음상 된소리** 가능하나 표기는 본형 유지 (색갈, 첫날 등)",
            "5. **고유한 발음·표기**: 《 》 인용, -소·-오·-니 등 종결 어미 활용",
            "6. **외래어·한자어 처리**: 굳어진 말('텔레비죤', '전투') 그대로",
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

    public static function getAdditionalRules(Language|string $language): array
    {
        if (is_string($language)) {
            $language = Language::fromCode($language);
        }

        $rules = [];

        // Get plural rules first
        $pluralRules = PluralRules::getAdditionalRulesPlural($language);
        if (!empty($pluralRules)) {
            $rules = array_merge($rules, $pluralRules);
        }

        // Get config rules (both base and specific)
        $configRules = self::getAdditionalRulesFromConfig($language->code);
        if (!empty($configRules)) {
            $rules = array_merge($rules, $configRules);
        }

        // Get default rules (both base and specific)
        $defaultRules = self::getAdditionalRulesDefault($language->code);
        if (!empty($defaultRules)) {
            $rules = array_merge($rules, $defaultRules);
        }

        return array_unique($rules);
    }

    protected static function getAdditionalRulesFromConfig(string $code): array
    {
        $list = config('ai-translator.additional_rules', []);
        $code = Language::normalizeCode($code);
        $rules = [];

        // Get base language rules first
        $baseCode = substr($code, 0, 2);
        if (isset($list[$baseCode])) {
            $rules = array_merge($rules, $list[$baseCode]);
        }

        // Then get specific language rules
        if (isset($list[$code]) && $code !== $baseCode) {
            $rules = array_merge($rules, $list[$code]);
        }

        // Finally get default rules if no rules found
        if (empty($rules)) {
            $rules = $list['default'] ?? [];
        }

        return $rules;
    }

    protected static function getAdditionalRulesDefault(string $code): array
    {
        $code = Language::normalizeCode($code);
        $rules = [];

        // Get base language rules first
        $baseCode = substr($code, 0, 2);
        if (isset(self::$additionalRules[$baseCode])) {
            $rules = array_merge($rules, self::$additionalRules[$baseCode]);
        }

        // Then get specific language rules
        if (isset(self::$additionalRules[$code]) && $code !== $baseCode) {
            $rules = array_merge($rules, self::$additionalRules[$code]);
        }

        // Finally get default rules if no rules found
        if (empty($rules)) {
            $rules = self::$additionalRules['default'] ?? [];
        }

        return $rules;
    }
}