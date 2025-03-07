<?php

namespace Kargnas\LaravelAiTranslator\Console;


use Carbon\Carbon;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\Project;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\AI\AIProvider;

class TranslateCrowdin extends Command
{
    protected $signature = 'ai-translator:translate-crowdin';

    protected $description = 'Translate all strings in the selected Crowdin project';

    protected $chunkSize;

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

    protected Crowdin $crowdin;
    protected array $selectedProject;
    protected array $referenceLanguages = [];
    protected string $targetLanguage;

    public function handle() {
        if (!env('CROWDIN_API_KEY')) {
            $this->error('CROWDIN_API_KEY is not set');
            exit(1);
        }

        $this->crowdin = new Crowdin([
            'access_token' => env('CROWDIN_API_KEY'),
        ]);

        $this->chunkSize = $this->ask('How many strings to translate at once?', 30);

        $this->choiceProjects();
        $this->targetLanguage = $this->choiceLanguages("Choose a language to translate", false);


        if ($this->ask('Do you want to choose reference languages? (y/n)', 'n') === 'y') {
            $this->referenceLanguages = $this->choiceLanguages("Choose a language to reference when translating, preferably one that has already been vetted and translated to a high quality. You can select multiple languages via ',' (e.g. '1, 2')", true);
        }

        $this->info("Target language: {$this->targetLanguage}");
        if ($this->referenceLanguages) {
            $this->info("Reference languages: " . implode(", ", $this->referenceLanguages));
        }

        $this->translate();
    }

    protected static function getAdditionalRulesFromConfig($originalLocale): array {
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

    protected static function getAdditionalRules($locale): array {
        return array_merge(static::getAdditionalRulesFromConfig($locale), static::getAdditionalRulesDefault($locale));
    }

    private function getAllProjects() {
        $projects = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->project->list([
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $projects = $projects->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $projects;
    }

    private function getAllDirectories($projectId) {
        $directories = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->directory->list($projectId, [
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $directories = $directories->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $directories;
    }

    private function getAllFiles($projectId, $directoryId) {
        $files = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->file->list($projectId, [
                'directoryId' => $directoryId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $files = $files->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $files;
    }

    /**
     * @param $projectId
     * @param $fileId
     * @param $languageId
     * @return \Illuminate\Support\Collection|LanguageTranslation[]
     */
    private function getAllLanguageTranslations($projectId, $fileId, $languageId) {
        $translations = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->stringTranslation->listLanguageTranslations($projectId, $languageId, [
                'fileId' => $fileId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $translations = $translations->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $translations;
    }

    private function getAllSourceString($projectId, $fileId) {
        $sourceStrings = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->sourceString->list($projectId, [
                'fileId' => $fileId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $sourceStrings = $sourceStrings->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $sourceStrings;
    }

    private function getApprovals($projectId, $fileId = null, $languageId = null) {
        $approvals = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->stringTranslation->listApprovals($projectId, [
                'fileId' => $fileId,
                'languageId' => $languageId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $approvals = $approvals->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $approvals;
    }

    private function addTranslation($projectId, $stringId, $languageId, $text, $context = null) {
        $response = $this->crowdin->stringTranslation->create($projectId, [
            'stringId' => $stringId,
            'languageId' => $languageId,
            'text' => $text,
        ]);

        return $response;
    }

    private function delTranslation($projectId, $translationId) {
        $response = $this->crowdin->stringTranslation->delete($projectId, $translationId);

        return $response;
    }

    private function getAllTranslations($projectId, $stringId, $languageId) {
        $translations = collect([]);
        $page = 1;
        do {
            $response = $this->crowdin->stringTranslation->list($projectId, [
                'stringId' => $stringId,
                'languageId' => $languageId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $translations = $translations->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $translations;
    }

    public function choiceProjects() {
        $projects = collect($this->getAllProjects())->map(function (Project $project) {
            return $project->getData();
        });

        $selectedProject = $projects->where('name', $this->choice('Select project', $projects->pluck('name')->toArray()))->first();
        if (!$selectedProject) {
            $this->error('Project not found');
            return;
        }

        $this->selectedProject = $selectedProject;
        $this->info("Selected project: {$this->selectedProject['name']} ({$this->selectedProject['id']})");
    }

    public function choiceLanguages($question, $multiple, $default = null) {
        $locales = collect($this->selectedProject['targetLanguages'])->sortBy('id')->pluck('id')->values()->toArray();

        $selectedLocales = $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple);

        return $selectedLocales;
    }

    public function translate() {
        $targetLanguage = collect($this->selectedProject['targetLanguages'])->where('id', $this->targetLanguage)->first();

        $locale = $targetLanguage['locale'];
        $pluralRules = $targetLanguage['pluralRules'];
        $pluralExamples = $targetLanguage['pluralExamples'];

        $skipUntil = 'commingSoon.tft.btn';
        $skip = false;

        $this->info("Source Language: {$this->selectedProject['sourceLanguage']['name']} ({$this->selectedProject['sourceLanguage']['id']})");
        $this->info("Translating to {$targetLanguage['name']} ({$targetLanguage['id']})");
        $this->info("  Locale: {$locale}");
        $this->info("  Plural Rules: {$pluralRules}");
        $this->info("  Plural Examples: " . implode(", ", array_keys($pluralExamples)));

        foreach ($this->getAllDirectories($this->selectedProject['id']) as $directory) {
            $directory = $directory->getData();
            $files = collect($this->getAllFiles($this->selectedProject['id'], $directory['id']))->map(function ($file) {
                return $file->getData();
            });
            if ($files->count() === 0) continue;

            $this->info("  Directory: {$directory['path']} ({$files->count()} files)");

            foreach ($files as $file) {
                $this->info("    File: {$file['name']} ({$file['id']})");

                $this->line("      Retrieving strings...");
                $allStrings = $this->getAllSourceString($this->selectedProject['id'], $file['id']);

                $this->line("      Retrieving translations...");
                $allTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                $this->line("      Retrieving approvals...");
                $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                $referenceApprovals = collect($this->referenceLanguages)->mapWithKeys(function ($refLocale) use ($allStrings, $file) {
                    $this->line("      Retrieving approvals for reference language...: {$refLocale}");
                    $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $refLocale);

                    $this->line("      Retrieving translations for reference language...: {$refLocale}");
                    $allTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $refLocale);

                    return [
                        $refLocale => collect($allStrings)->mapWithKeys(function (SourceString $sourceString) use ($approvals, $allTranslations) {
                            $approved = $approvals->map(fn(StringTranslationApproval $ap) => $ap->getData())->where('stringId', $sourceString->getId())->first();
                            if (!$approved) return [];

                            $approvedTranslation = $allTranslations->map(fn(LanguageTranslation $t) => $t->getData())->where('translationId', $approved['translationId'])->first();
                            if (!$approvedTranslation) return [];

                            return [
                                $sourceString->getIdentifier() => $approvedTranslation['text'],
                            ];
                        }),
                    ];
                });

                $untranslatedStrings = $allStrings
                    ->filter(function (SourceString $sourceString) use ($approvals, $allTranslations, $skipUntil, &$skip) {
                        if (!$sourceString->getIdentifier()) return false;
                        if ($skipUntil && $sourceString->getIdentifier() === $skipUntil) $skip = false;
                        if ($skip) return false;

                        if ($sourceString->isHidden()) {
//                            $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (hidden)");
                            return false;
                        }

                        $approved = $approvals->filter(fn(StringTranslationApproval $ap) => $ap->getStringId() == $sourceString->getId());
                        if ($approved->count() > 0) {
                            $translation = $allTranslations->filter(fn(LanguageTranslation $t) => $t->getTranslationId() == $approved->first()->getTranslationId())->first();
                            if ($translation) {
                                $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (approved)");
                                return false;
                            }
                        }

                        return true;
                    })
                    ->map(function (SourceString $sourceString) use ($targetLanguage) {
                        return $sourceString->getData();
                    });

                $this->info("      Total: {$allStrings->count()} strings");
                $this->info("      Untranslated: {$untranslatedStrings->count()} strings");

                $untranslatedStrings
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($file, $targetLanguage, $untranslatedStrings, $referenceApprovals) {
                        $translator = new AIProvider(
                            filename: $file['name'],
                            strings: $chunk->mapWithKeys(function ($string) use ($referenceApprovals) {
                                $context = $string['context'] ?? null;
                                $context = preg_replace("/[\.\s\->]/", "", $context);
                                if (preg_replace("/[\.\s\->]/", "", $string['identifier']) === $context) {
                                    $context = null;
                                }

                                /** @var Collection $references */
                                $references = $referenceApprovals->map(function ($items) use ($string) {
                                    return $items[$string['identifier']] ?? "";
                                })->filter(function ($value) {
                                    return strlen($value) > 0;
                                });

                                return [
                                    $string['identifier'] => [
                                        // source text 를 그대로 쓰면 안되고 Translation 에서 따로 가져와서 써야한다.
                                        'text' => $references->only($this->selectedProject['sourceLanguage']['id'])->first() ?? $string['text'],
                                        'context' => $context,
                                        'references' => $references->except($this->selectedProject['sourceLanguage']['id'])->toArray(),
                                    ],
                                ];
                            })->toArray(),
                            sourceLanguage: $this->selectedProject['sourceLanguage']['name'],
                            targetLanguage: $targetLanguage['name'],
                            additionalRules: static::getAdditionalRules($targetLanguage['locale']),
                        );

                        $translated = $translator->translate();

                        foreach ($translated as $item) {
                            $targetString = $untranslatedStrings->where('identifier', $item->key)->first();
                            if (!$targetString) {
                                $this->info("Skipping translation: {$item->key} (Not found)");
                                continue;
                            }

                            $existsTranslations = $this->getAllTranslations($this->selectedProject['id'], $targetString['id'], $targetLanguage['id']);
                            $existsTranslations = $existsTranslations->sortByDesc(fn(StringTranslation $t) => Carbon::make($t->getDataProperty('created_at')))->values();

                            // 같은 번역이 있다면 패스
                            if ($existsTranslations->filter(fn(StringTranslation $t) => $t->getText() === $item->translated)->isNotEmpty()) {
                                $this->info("Skipping translation: {$item->key} [{$targetString['id']}]: {$item->translated} (Duplicated)");
                                continue;
                            }

                            $this->info("Adding translation: {$item->key} [{$targetString['id']}]: {$item->translated}");
                            $myTransitions = $existsTranslations->filter(fn(StringTranslation $t) => $t->getUser()['id'] === 16501205);
                            if ($myTransitions->count() > 0) {
                                $this->delTranslation($this->selectedProject['id'], $myTransitions->first()->getId());
                            }
                            $this->addTranslation($this->selectedProject['id'], $targetString['id'], $targetLanguage['id'], $item->translated);
                        }
                    });

                // dd($sourceStrings);
            }
        }
    }
}
