<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Utility;
use Kargnas\LaravelAiTranslator\Enums\PromptType;

/**
 * Artisan command that translates PHP language files using LLMs with support for multiple locales,
 * reference languages, chunking for large files, and customizable context settings
 */
class TranslateStrings extends Command
{
    protected $signature = 'ai-translator:translate
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translates PHP language files using LLMs with support for multiple locales, reference languages, chunking for large files, and customizable context settings';

    /**
     * Translation settings
     */
    protected string $sourceLocale;
    protected string $sourceDirectory;
    protected int $chunkSize;
    protected array $referenceLocales = [];

    protected int $defaultChunkSize = 100;
    protected int $defaultMaxContextItems = 1000;
    protected int $warningStringCount = 500;

    /**
     * Token usage tracking
     */
    protected array $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0
    ];

    /**
     * Color codes
     */
    protected array $colors = [
        'reset' => "\033[0m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'purple' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
        'bold' => "\033[1m",
        'underline' => "\033[4m",
        'red_bg' => "\033[41m",
        'green_bg' => "\033[42m",
        'yellow_bg' => "\033[43m",
        'blue_bg' => "\033[44m",
        'purple_bg' => "\033[45m",
        'cyan_bg' => "\033[46m",
        'white_bg' => "\033[47m"
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $sourceDirectory = config('ai-translator.source_directory');
        $sourceLocale = config('ai-translator.source_locale');

        $this->setDescription(
            "Translates PHP language files using AI technology\n" .
            "  Source Directory: {$sourceDirectory}\n" .
            "  Default Source Locale: {$sourceLocale}"
        );
    }

    /**
     * Main command execution method
     */
    public function handle()
    {
        // Display header
        $this->displayHeader();

        // Set source directory
        $this->sourceDirectory = config('ai-translator.source_directory');

        // Check if running in non-interactive mode
        $nonInteractive = $this->option('non-interactive');

        // Select source language
        if ($nonInteractive || $this->option('source')) {
            $this->sourceLocale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
            $this->info($this->colors['green'] . "✓ Selected source locale: " .
                $this->colors['reset'] . $this->colors['bold'] . $this->sourceLocale .
                $this->colors['reset']);
        } else {
            $this->sourceLocale = $this->choiceLanguages(
                $this->colors['yellow'] . "Choose a source language to translate from" . $this->colors['reset'],
                false,
                'en'
            );
        }

        // Select reference languages
        if ($nonInteractive) {
            $this->referenceLocales = $this->option('reference')
                ? explode(',', (string) $this->option('reference'))
                : [];
            if (!empty($this->referenceLocales)) {
                $this->info($this->colors['green'] . "✓ Selected reference locales: " .
                    $this->colors['reset'] . $this->colors['bold'] . implode(', ', $this->referenceLocales) .
                    $this->colors['reset']);
            }
        } else if ($this->option('reference')) {
            $this->referenceLocales = explode(',', $this->option('reference'));
            $this->info($this->colors['green'] . "✓ Selected reference locales: " .
                $this->colors['reset'] . $this->colors['bold'] . implode(', ', $this->referenceLocales) .
                $this->colors['reset']);
        } else if ($this->ask($this->colors['yellow'] . 'Do you want to add reference languages? (y/n)' . $this->colors['reset'], 'n') === 'y') {
            $this->referenceLocales = $this->choiceLanguages(
                $this->colors['yellow'] . "Choose reference languages for translation guidance. Select languages with high-quality translations. Multiple selections with comma separator (e.g. '1,2')" . $this->colors['reset'],
                true
            );
        }

        // Set chunk size
        if ($nonInteractive || $this->option('chunk')) {
            $this->chunkSize = (int) ($this->option('chunk') ?? $this->defaultChunkSize);
            $this->info($this->colors['green'] . "✓ Chunk size: " .
                $this->colors['reset'] . $this->colors['bold'] . $this->chunkSize .
                $this->colors['reset']);
        } else {
            $this->chunkSize = (int) $this->ask(
                $this->colors['yellow'] . "Enter the chunk size for translation. Translate strings in a batch. The higher, the cheaper." . $this->colors['reset'],
                $this->defaultChunkSize
            );
        }

        // Set context items count
        if ($nonInteractive || $this->option('max-context')) {
            $maxContextItems = (int) ($this->option('max-context') ?? $this->defaultMaxContextItems);
            $this->info($this->colors['green'] . "✓ Maximum context items: " .
                $this->colors['reset'] . $this->colors['bold'] . $maxContextItems .
                $this->colors['reset']);
        } else {
            $maxContextItems = (int) $this->ask(
                $this->colors['yellow'] . "Maximum number of context items to include for consistency (set 0 to disable)" . $this->colors['reset'],
                $this->defaultMaxContextItems
            );
        }

        // Execute translation
        $this->translate($maxContextItems);

        return 0;
    }

    /**
     * 헤더 출력
     */
    protected function displayHeader(): void
    {
        $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Laravel AI Translator " . $this->colors['reset']);
        $this->line($this->colors['gray'] . "Translating PHP language files using AI technology" . $this->colors['reset']);
        $this->line(str_repeat('─', 80) . "\n");
    }

    /**
     * 언어 선택 헬퍼 메서드
     *
     * @param string $question 질문
     * @param bool $multiple 다중 선택 여부
     * @param string|null $default 기본값
     * @return array|string 선택된 언어(들)
     */
    public function choiceLanguages(string $question, bool $multiple, ?string $default = null): array|string
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
            $this->info($this->colors['green'] . "✓ Selected locales: " .
                $this->colors['reset'] . $this->colors['bold'] . implode(', ', $selectedLocales) .
                $this->colors['reset']);
        } else {
            $this->info($this->colors['green'] . "✓ Selected locale: " .
                $this->colors['reset'] . $this->colors['bold'] . $selectedLocales .
                $this->colors['reset']);
        }

        return $selectedLocales;
    }

    /**
     * 번역 실행
     *
     * @param int $maxContextItems 최대 컨텍스트 항목 수
     */
    public function translate(int $maxContextItems = 100): void
    {
        // 커맨드라인에서 지정된 로케일 가져오기
        $specifiedLocales = $this->option('locale');

        // 사용 가능한 모든 로케일 가져오기
        $availableLocales = $this->getExistingLocales();

        // 지정된 로케일이 있으면 검증하고 사용, 없으면 모든 로케일 사용
        $locales = !empty($specifiedLocales)
            ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
            : $availableLocales;

        if (empty($locales)) {
            $this->error("No valid locales specified or found for translation.");
            return;
        }

        $fileCount = 0;
        $totalStringCount = 0;
        $totalTranslatedCount = 0;

        foreach ($locales as $locale) {
            // 소스 언어와 같거나 스킵 목록에 있는 언어는 건너뜀
           if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                $this->warn('Skipping locale ' . $locale . '.');
                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($locale);

            if (!$targetLanguageName) {
                $this->error("Language name not found for locale: {$locale}. Please add it to the config file.");
                continue;
            }

            $this->line(str_repeat('─', 80));
            $this->line(str_repeat('─', 80));
            $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Starting {$targetLanguageName} ({$locale}) " . $this->colors['reset']);

            $localeFileCount = 0;
            $localeStringCount = 0;
            $localeTranslatedCount = 0;

            // 소스 파일 목록 가져오기
            $files = $this->getStringFilePaths($this->sourceLocale);

            foreach ($files as $file) {
                $outputFile = $this->getOutputDirectoryLocale($locale) . '/' . basename($file);

                if (in_array(basename($file), config('ai-translator.skip_files', []))) {
                    $this->warn('Skipping file  ' . basename($file) .'.');
                    continue;
                }

                $this->displayFileInfo($file, $locale, $outputFile);

                $localeFileCount++;
                $fileCount++;

                // Load source strings
                $transformer = new PHPLangTransformer($file);
                $sourceStringList = $transformer->flatten();

                // Load target strings (or create)
                $targetStringTransformer = new PHPLangTransformer($outputFile);

                // Filter untranslated strings only
                $sourceStringList = collect($sourceStringList)
                    ->filter(function ($value, $key) use ($targetStringTransformer) {
                        // Skip already translated ones
                        return !$targetStringTransformer->isTranslated($key);
                    })
                    ->toArray();

                // Skip if no items to translate
                if (count($sourceStringList) === 0) {
                    $this->info($this->colors['green'] . "  ✓ " . $this->colors['reset'] . "All strings are already translated. Skipping.");
                    continue;
                }

                $localeStringCount += count($sourceStringList);
                $totalStringCount += count($sourceStringList);

                // Check if there are many strings to translate
                if (count($sourceStringList) > $this->warningStringCount && !$this->option('force-big-files')) {
                    if (
                        !$this->confirm(
                            $this->colors['yellow'] . "⚠️ Warning: " . $this->colors['reset'] .
                            "File has " . count($sourceStringList) . " strings to translate. This could be expensive. Continue?",
                            true
                        )
                    ) {
                        $this->warn("Translation stopped by user.");
                        return;
                    }
                }

                // Load reference translations (from all files)
                $referenceStringList = $this->loadReferenceTranslations($file, $locale, $sourceStringList);

                // Process in chunks
                $chunkCount = 0;
                $totalChunks = ceil(count($sourceStringList) / $this->chunkSize);

                collect($sourceStringList)
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($locale, $file, $targetStringTransformer, $referenceStringList, $maxContextItems, &$localeTranslatedCount, &$totalTranslatedCount, &$chunkCount, $totalChunks) {
                        $chunkCount++;
                        $this->info($this->colors['yellow'] . "  ⏺ Processing chunk " .
                            $this->colors['reset'] . "{$chunkCount}/{$totalChunks}" .
                            $this->colors['gray'] . " (" . $chunk->count() . " strings)" .
                            $this->colors['reset']);

                        // Get global translation context
                        $globalContext = $this->getGlobalContext($file, $locale, $maxContextItems);

                        // Configure translator
                        $translator = $this->setupTranslator(
                            $file,
                            $chunk,
                            $referenceStringList,
                            $locale,
                            $globalContext
                        );

                        try {
                            // Execute translation
                            $translatedItems = $translator->translate();
                            $localeTranslatedCount += count($translatedItems);
                            $totalTranslatedCount += count($translatedItems);

                            // Save translation results - display is handled by onTranslated
                            foreach ($translatedItems as $item) {
                                $targetStringTransformer->updateString($item->key, $item->translated);
                            }

                            // Display number of saved items
                            $this->info($this->colors['green'] . "  ✓ " . $this->colors['reset'] . "{$localeTranslatedCount} strings saved.");

                            // Calculate and display cost
                            $this->displayCostEstimation($translator);

                            // Accumulate token usage
                            $usage = $translator->getTokenUsage();
                            $this->updateTokenUsageTotals($usage);

                        } catch (\Exception $e) {
                            $this->error("Translation failed: " . $e->getMessage());
                        }
                    });
            }

            // Display translation summary for each language
            $this->displayTranslationSummary($locale, $localeFileCount, $localeStringCount, $localeTranslatedCount);
        }

        // 전체 번역 완료 메시지
        $this->line("\n" . $this->colors['green_bg'] . $this->colors['white'] . $this->colors['bold'] . " All translations completed " . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Total files processed: " . $this->colors['reset'] . $fileCount);
        $this->line($this->colors['yellow'] . "Total strings found: " . $this->colors['reset'] . $totalStringCount);
        $this->line($this->colors['yellow'] . "Total strings translated: " . $this->colors['reset'] . $totalTranslatedCount);
    }

    /**
     * 비용 계산 및 표시
     */
    protected function displayCostEstimation(AIProvider $translator): void
    {
        $usage = $translator->getTokenUsage();
        $printer = new TokenUsagePrinter($translator->getModel());
        $printer->printTokenUsageSummary($this, $usage);
        $printer->printCostEstimation($this, $usage);
    }

    /**
     * 파일 정보 표시
     */
    protected function displayFileInfo(string $sourceFile, string $locale, string $outputFile): void
    {
        $this->line("\n" . $this->colors['purple_bg'] . $this->colors['white'] . $this->colors['bold'] . " File Translation " . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "  File: " .
            $this->colors['reset'] . $this->colors['bold'] . basename($sourceFile) .
            $this->colors['reset']);
        $this->line($this->colors['yellow'] . "  Language: " .
            $this->colors['reset'] . $this->colors['bold'] . $locale .
            $this->colors['reset']);
        $this->line($this->colors['gray'] . "  Source: " . $sourceFile . $this->colors['reset']);
        $this->line($this->colors['gray'] . "  Target: " . $outputFile . $this->colors['reset']);
    }

    /**
     * 번역 완료 요약 표시
     */
    protected function displayTranslationSummary(string $locale, int $fileCount, int $stringCount, int $translatedCount): void
    {
        $this->line("\n" . str_repeat('─', 80));
        $this->line($this->colors['green_bg'] . $this->colors['white'] . $this->colors['bold'] . " Translation Complete: {$locale} " . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Files processed: " . $this->colors['reset'] . $fileCount);
        $this->line($this->colors['yellow'] . "Strings found: " . $this->colors['reset'] . $stringCount);
        $this->line($this->colors['yellow'] . "Strings translated: " . $this->colors['reset'] . $translatedCount);

        // Display accumulated token usage
        if ($this->tokenUsage['total_tokens'] > 0) {
            $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Total Token Usage " . $this->colors['reset']);
            $this->line($this->colors['yellow'] . "Input Tokens: " . $this->colors['reset'] . $this->colors['green'] . $this->tokenUsage['input_tokens'] . $this->colors['reset']);
            $this->line($this->colors['yellow'] . "Output Tokens: " . $this->colors['reset'] . $this->colors['green'] . $this->tokenUsage['output_tokens'] . $this->colors['reset']);
            $this->line($this->colors['yellow'] . "Total Tokens: " . $this->colors['reset'] . $this->colors['bold'] . $this->colors['purple'] . $this->tokenUsage['total_tokens'] . $this->colors['reset']);
        }
    }

    /**
     * 레퍼런스 번역 로드 (모든 파일에서)
     */
    protected function loadReferenceTranslations(string $file, string $targetLocale, array $sourceStringList): array
    {
        // 타겟 언어와 레퍼런스 언어들을 모두 포함
        $allReferenceLocales = array_merge([$targetLocale], $this->referenceLocales);
        $langDirectory = config('ai-translator.source_directory');
        $currentFileName = basename($file);

        return collect($allReferenceLocales)
            ->filter(fn($referenceLocale) => $referenceLocale !== $this->sourceLocale)
            ->map(function ($referenceLocale) use ($langDirectory, $file, $currentFileName) {
                $referenceLocaleDir = $this->getOutputDirectoryLocale($referenceLocale);

                if (!is_dir($referenceLocaleDir)) {
                    $this->line($this->colors['gray'] . "    ℹ Reference directory not found: {$referenceLocale}" . $this->colors['reset']);
                    return null;
                }

                // 해당 로케일 디렉토리의 모든 PHP 파일 가져오기
                $referenceFiles = glob("{$referenceLocaleDir}/*.php");

                if (empty($referenceFiles)) {
                    $this->line($this->colors['gray'] . "    ℹ Reference file not found: {$referenceLocale}" . $this->colors['reset']);
                    return null;
                }

                $this->line($this->colors['blue'] . "    ℹ Loading reference: " .
                    $this->colors['reset'] . "{$referenceLocale} - " . count($referenceFiles) . " files");

                // 유사한 이름의 파일을 먼저 처리하여 컨텍스트 관련성 향상
                usort($referenceFiles, function ($a, $b) use ($currentFileName) {
                    $similarityA = similar_text($currentFileName, basename($a));
                    $similarityB = similar_text($currentFileName, basename($b));
                    return $similarityB <=> $similarityA;
                });

                $allReferenceStrings = [];
                $processedFiles = 0;

                foreach ($referenceFiles as $referenceFile) {
                    try {
                        $referenceTransformer = new PHPLangTransformer($referenceFile);
                        $referenceStringList = $referenceTransformer->flatten();

                        if (empty($referenceStringList)) {
                            continue;
                        }

                        // 우선순위 적용 (필요한 경우)
                        if (count($referenceStringList) > 50) {
                            $referenceStringList = $this->getPrioritizedReferenceStrings($referenceStringList, 50);
                        }

                        $allReferenceStrings = array_merge($allReferenceStrings, $referenceStringList);
                        $processedFiles++;
                    } catch (\Exception $e) {
                        $this->line($this->colors['gray'] . "    ⚠ Reference file loading failed: " . basename($referenceFile) . $this->colors['reset']);
                        continue;
                    }
                }

                if (empty($allReferenceStrings)) {
                    return null;
                }

                return [
                    'locale' => $referenceLocale,
                    'strings' => $allReferenceStrings,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * 레퍼런스 문자열에 우선순위 적용
     */
    protected function getPrioritizedReferenceStrings(array $strings, int $maxItems): array
    {
        $prioritized = [];

        // 1. 짧은 문자열 우선 (UI 요소, 버튼 등)
        foreach ($strings as $key => $value) {
            if (strlen($value) < 50 && count($prioritized) < $maxItems * 0.7) {
                $prioritized[$key] = $value;
            }
        }

        // 2. 나머지 항목 추가
        foreach ($strings as $key => $value) {
            if (!isset($prioritized[$key]) && count($prioritized) < $maxItems) {
                $prioritized[$key] = $value;
            }

            if (count($prioritized) >= $maxItems) {
                break;
            }
        }

        return $prioritized;
    }

    /**
     * 전역 번역 컨텍스트 가져오기
     */
    protected function getGlobalContext(string $file, string $locale, int $maxContextItems): array
    {
        if ($maxContextItems <= 0) {
            return [];
        }

        $contextProvider = new TranslationContextProvider();
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $this->sourceLocale,
            $locale,
            $file,
            $maxContextItems
        );

        if (!empty($globalContext)) {
            $contextItemCount = collect($globalContext)->map(fn($items) => count($items))->sum();
            $this->info($this->colors['blue'] . "    ℹ Using global context: " .
                $this->colors['reset'] . count($globalContext) . " files, " .
                $contextItemCount . " items");
        } else {
            $this->line($this->colors['gray'] . "    ℹ No global context available" . $this->colors['reset']);
        }

        return $globalContext;
    }

    /**
     * 번역기 설정
     */
    protected function setupTranslator(
        string $file,
        \Illuminate\Support\Collection $chunk,
        array $referenceStringList,
        string $locale,
        array $globalContext
    ): AIProvider {
        // 파일 정보 표시
        $outputFile = $this->getOutputDirectoryLocale($locale) . '/' . basename($file);
        $this->displayFileInfo($file, $locale, $outputFile);

        // 레퍼런스 정보를 적절한 형식으로 변환
        $references = [];
        foreach ($referenceStringList as $reference) {
            $referenceLocale = $reference['locale'];
            $referenceStrings = $reference['strings'];
            $references[$referenceLocale] = $referenceStrings;
        }

        // AIProvider 인스턴스 생성
        $translator = new AIProvider(
            $file,
            $chunk->toArray(),
            $this->sourceLocale,
            $locale,
            $references,
            [],  // additionalRules
            $globalContext  // globalTranslationContext
        );

        $translator->setOnThinking(function ($thinking) {
            echo $this->colors['gray'] . $thinking . $this->colors['reset'];
        });

        $translator->setOnThinkingStart(function () {
            $this->line($this->colors['gray'] . "    " . "🧠 AI Thinking..." . $this->colors['reset']);
        });

        $translator->setOnThinkingEnd(function () {
            $this->line($this->colors['gray'] . "    " . "Thinking completed." . $this->colors['reset']);
        });

        // 번역 진행 상황 표시를 위한 콜백 설정
        $translator->setOnTranslated(function ($item, $status, $translatedItems) use ($chunk) {
            if ($status === TranslationStatus::COMPLETED) {
                $totalCount = $chunk->count();
                $completedCount = count($translatedItems);

                $this->line($this->colors['cyan'] . "  ⟳ " .
                    $this->colors['reset'] . $item->key .
                    $this->colors['gray'] . " → " .
                    $this->colors['reset'] . $item->translated .
                    $this->colors['gray'] . " ({$completedCount}/{$totalCount})" .
                    $this->colors['reset']);
            }
        });

        // 토큰 사용량 콜백 설정
        $translator->setOnTokenUsage(function ($usage) {
            $isFinal = $usage['final'] ?? false;
            $inputTokens = $usage['input_tokens'] ?? 0;
            $outputTokens = $usage['output_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? 0;

            // 실시간 토큰 사용량 표시
            $this->line($this->colors['gray'] . "    Tokens: " .
                "Input=" . $this->colors['green'] . $inputTokens . $this->colors['gray'] . ", " .
                "Output=" . $this->colors['green'] . $outputTokens . $this->colors['gray'] . ", " .
                "Total=" . $this->colors['purple'] . $totalTokens . $this->colors['gray'] .
                $this->colors['reset']);
        });

        // 프롬프트 로깅 콜백 설정
        if ($this->option('show-prompt')) {
            $translator->setOnPromptGenerated(function ($prompt, PromptType $type) {
                $typeText = match ($type) {
                    PromptType::SYSTEM => '🤖 System Prompt',
                    PromptType::USER => '👤 User Prompt',
                };

                print ("\n    {$typeText}:\n");
                print ($this->colors['gray'] . "    " . str_replace("\n", $this->colors['reset'] . "\n    " . $this->colors['gray'], $prompt) . $this->colors['reset'] . "\n");
            });
        }

        return $translator;
    }

    /**
     * 토큰 사용량 총계 업데이트
     */
    protected function updateTokenUsageTotals(array $usage): void
    {
        $this->tokenUsage['input_tokens'] += ($usage['input_tokens'] ?? 0);
        $this->tokenUsage['output_tokens'] += ($usage['output_tokens'] ?? 0);
        $this->tokenUsage['total_tokens'] =
            $this->tokenUsage['input_tokens'] +
            $this->tokenUsage['output_tokens'];
    }

    /**
     * 사용 가능한 로케일 목록 가져오기
     *
     * @return array|string[]
     */
    public function getExistingLocales(): array
    {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // 디렉토리만 필터링
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root . '/' . $directory);
        });
        return collect($directories)->values()->toArray();
    }

    /**
     * 출력 디렉토리 경로 가져오기
     */
    public function getOutputDirectoryLocale(string $locale): string
    {
        return config('ai-translator.source_directory') . '/' . $locale;
    }

    /**
     * 문자열 파일 경로 목록 가져오기
     */
    public function getStringFilePaths(string $locale): array
    {
        $files = [];
        $root = $this->sourceDirectory . '/' . $locale;
        $directories = array_diff(scandir($root), ['.', '..']);
        foreach ($directories as $directory) {
            // PHP 파일만 필터링
            if (pathinfo($directory, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $files[] = $root . '/' . $directory;
        }
        return $files;
    }

    /**
     * 지정된 로케일 검증 및 필터링
     */
    protected function validateAndFilterLocales(array $specifiedLocales, array $availableLocales): array
    {
        $validLocales = [];
        $invalidLocales = [];

        foreach ($specifiedLocales as $locale) {
            if (in_array($locale, $availableLocales)) {
                $validLocales[] = $locale;
            } else {
                $invalidLocales[] = $locale;
            }
        }

        if (!empty($invalidLocales)) {
            $this->warn("The following locales are invalid or not available: " . implode(', ', $invalidLocales));
            $this->info("Available locales: " . implode(', ', $availableLocales));
        }

        return $validLocales;
    }
}
