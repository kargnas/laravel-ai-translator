<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\Console\Concerns\WiresTranslatorOutput;
use Kargnas\LaravelAiTranslator\Contracts\Translator;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Translation\ChangeDetector;
use Kargnas\LaravelAiTranslator\Translation\TokenChunker;
use Kargnas\LaravelAiTranslator\Translation\TranslatorFactory;

/**
 * Artisan command that translates PHP language files using LLMs with support for multiple locales,
 * reference languages, chunking for large files, and customizable context settings
 */
class TranslateStrings extends Command
{
    use WiresTranslatorOutput;

    protected $signature = 'ai-translator:translate
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--max-tokens-per-chunk= : Maximum estimated source tokens per translation request (default 1500)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--force-retranslate : Bypass source change detection}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translates PHP language files using LLMs with support for multiple locales, reference languages, chunking for large files, and customizable context settings';

    /**
     * Translation settings
     */
    protected string $sourceLocale;

    protected string $sourceDirectory;

    protected int $maxTokensPerChunk;

    protected array $referenceLocales = [];

    protected int $defaultMaxTokensPerChunk = 1500;

    protected int $defaultMaxContextItems = 1000;

    protected int $warningStringCount = 500;

    // Chunks whose translation threw; a non-zero count must fail the command
    // instead of ending in a green "completed" banner (issue #20).
    protected int $failedChunkCount = 0;

    /**
     * Token usage tracking
     */
    protected array $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
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
        'white_bg' => "\033[47m",
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
            "Translates PHP language files using AI technology\n".
            "  Source Directory: {$sourceDirectory}\n".
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
            $this->info($this->colors['green'].'✓ Selected source locale: '.
                $this->colors['reset'].$this->colors['bold'].$this->sourceLocale.
                $this->colors['reset']);
        } else {
            $this->sourceLocale = $this->choiceLanguages(
                $this->colors['yellow'].'Choose a source language to translate from'.$this->colors['reset'],
                false,
                'en'
            );
        }

        // Select reference languages
        if ($nonInteractive) {
            $this->referenceLocales = $this->option('reference')
                ? explode(',', (string) $this->option('reference'))
                : [];
            if (! empty($this->referenceLocales)) {
                $this->info($this->colors['green'].'✓ Selected reference locales: '.
                    $this->colors['reset'].$this->colors['bold'].implode(', ', $this->referenceLocales).
                    $this->colors['reset']);
            }
        } elseif ($this->option('reference')) {
            $this->referenceLocales = explode(',', $this->option('reference'));
            $this->info($this->colors['green'].'✓ Selected reference locales: '.
                $this->colors['reset'].$this->colors['bold'].implode(', ', $this->referenceLocales).
                $this->colors['reset']);
        } elseif ($this->ask($this->colors['yellow'].'Do you want to add reference languages? (y/n)'.$this->colors['reset'], 'n') === 'y') {
            $this->referenceLocales = $this->choiceLanguages(
                $this->colors['yellow']."Choose reference languages for translation guidance. Select languages with high-quality translations. Multiple selections with comma separator (e.g. '1,2')".$this->colors['reset'],
                true
            );
        }

        // Use a token budget so batches stay within model request limits.
        if ($nonInteractive || $this->option('max-tokens-per-chunk')) {
            $this->maxTokensPerChunk = (int) ($this->option('max-tokens-per-chunk') ?? $this->defaultMaxTokensPerChunk);
            $this->info($this->colors['green'].'✓ Maximum estimated source tokens per request: '.
                $this->colors['reset'].$this->colors['bold'].$this->maxTokensPerChunk.
                $this->colors['reset']);
        } else {
            $this->maxTokensPerChunk = (int) $this->ask(
                $this->colors['yellow'].'Enter the maximum estimated source tokens per translation request. The higher, the cheaper.'.$this->colors['reset'],
                $this->defaultMaxTokensPerChunk
            );
        }

        // Set context items count
        if ($nonInteractive || $this->option('max-context')) {
            $maxContextItems = (int) ($this->option('max-context') ?? $this->defaultMaxContextItems);
            $this->info($this->colors['green'].'✓ Maximum context items: '.
                $this->colors['reset'].$this->colors['bold'].$maxContextItems.
                $this->colors['reset']);
        } else {
            $maxContextItems = (int) $this->ask(
                $this->colors['yellow'].'Maximum number of context items to include for consistency (set 0 to disable)'.$this->colors['reset'],
                $this->defaultMaxContextItems
            );
        }

        // Execute translation
        $this->translate($maxContextItems);

        return $this->failedChunkCount > 0 ? 1 : 0;
    }

    /**
     * 헤더 출력
     */
    protected function displayHeader(): void
    {
        $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Laravel AI Translator '.$this->colors['reset']);
        $this->line($this->colors['gray'].'Translating PHP language files using AI technology'.$this->colors['reset']);
        $this->line(str_repeat('─', 80)."\n");
    }

    /**
     * 언어 선택 헬퍼 메서드
     *
     * @param  string  $question  질문
     * @param  bool  $multiple  다중 선택 여부
     * @param  string|null  $default  기본값
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
            $this->info($this->colors['green'].'✓ Selected locales: '.
                $this->colors['reset'].$this->colors['bold'].implode(', ', $selectedLocales).
                $this->colors['reset']);
        } else {
            $this->info($this->colors['green'].'✓ Selected locale: '.
                $this->colors['reset'].$this->colors['bold'].$selectedLocales.
                $this->colors['reset']);
        }

        return $selectedLocales;
    }

    /**
     * Execute translation
     *
     * @param  int  $maxContextItems  Maximum number of context items
     */
    public function translate(int $maxContextItems = 100): void
    {
        // 커맨드라인에서 지정된 로케일 가져오기
        $specifiedLocales = $this->option('locale');

        // 사용 가능한 모든 로케일 가져오기
        $availableLocales = $this->getExistingLocales();

        // 지정된 로케일이 있으면 검증하고 사용, 없으면 모든 로케일 사용
        $locales = ! empty($specifiedLocales)
            ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
            : $availableLocales;

        if (empty($locales)) {
            $this->error('No valid locales specified or found for translation.');

            return;
        }

        $this->announceConsensusMode();

        $fileCount = 0;
        $totalStringCount = 0;
        $totalTranslatedCount = 0;
        $changeDetector = new ChangeDetector;

        foreach ($locales as $locale) {
            // 소스 언어와 같거나 스킵 목록에 있는 언어는 건너뜀
            if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                $this->warn('Skipping locale '.$locale.'.');

                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($locale);

            if (! $targetLanguageName) {
                $this->error("Language name not found for locale: {$locale}. Please add it to the config file.");

                continue;
            }

            $this->line(str_repeat('─', 80));
            $this->line(str_repeat('─', 80));
            $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold']." Starting {$targetLanguageName} ({$locale}) ".$this->colors['reset']);

            $localeFileCount = 0;
            $localeStringCount = 0;
            $localeTranslatedCount = 0;

            // 소스 파일 목록 가져오기
            $files = $this->getStringFilePaths($this->sourceLocale);

            foreach ($files as $file) {
                $outputFile = $this->getOutputDirectoryLocale($locale).'/'.basename($file);

                if (in_array(basename($file), config('ai-translator.skip_files', []))) {
                    $this->warn('Skipping file  '.basename($file).'.');

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

                $filePrefix = pathinfo($file, PATHINFO_FILENAME);
                $stateStringList = [];
                foreach ($sourceStringList as $key => $value) {
                    $stateStringList["{$filePrefix}.{$key}"] = $value;
                }

                $seedStateKeys = collect($sourceStringList)
                    ->filter(fn ($value, $key) => $targetStringTransformer->isTranslated($key))
                    ->keys()
                    ->map(fn ($key) => "{$filePrefix}.{$key}")
                    ->all();

                $untranslatedStringList = collect($sourceStringList)
                    ->filter(fn ($value, $key) => ! $targetStringTransformer->isTranslated($key))
                    ->toArray();

                if ($this->option('force-retranslate')) {
                    $candidateStringList = $sourceStringList;
                } else {
                    $changedStateStrings = $changeDetector->changedAgainstState(
                        $stateStringList,
                        $this->sourceLocale,
                        $locale
                    );
                    $changedStringList = [];

                    foreach ($changedStateStrings as $key => $value) {
                        $localKey = substr($key, strlen($filePrefix) + 1);
                        $changedStringList[$localKey] = $value;
                    }

                    $candidateStringList = $untranslatedStringList + $changedStringList;
                }

                $sourceStringList = $candidateStringList;

                // Skip if no items to translate
                if (count($sourceStringList) === 0) {
                    $changeDetector->saveState($seedStateKeys, $stateStringList, $this->sourceLocale, $locale);
                    $this->info($this->colors['green'].'  ✓ '.$this->colors['reset'].'All strings are already translated. Skipping.');

                    continue;
                }

                $localeStringCount += count($sourceStringList);
                $totalStringCount += count($sourceStringList);

                // Check if there are many strings to translate
                if (count($sourceStringList) > $this->warningStringCount && ! $this->option('force-big-files')) {
                    if (
                        ! $this->confirm(
                            $this->colors['yellow'].'⚠️ Warning: '.$this->colors['reset'].
                            'File has '.count($sourceStringList).' strings to translate. This could be expensive. Continue?',
                            true
                        )
                    ) {
                        $this->warn('Translation stopped by user.');

                        return;
                    }
                }

                // Load reference translations (from all files)
                $referenceStringList = $this->loadReferenceTranslations($file, $locale, $sourceStringList);

                // Process in chunks
                $chunkCount = 0;
                $chunks = (new TokenChunker)->chunk($sourceStringList, $this->maxTokensPerChunk);
                $totalChunks = count($chunks);

                collect($chunks)
                    ->each(function (array $chunkStrings) use ($locale, $file, $targetStringTransformer, $referenceStringList, $maxContextItems, $changeDetector, $stateStringList, $seedStateKeys, $filePrefix, &$localeTranslatedCount, &$totalTranslatedCount, &$chunkCount, $totalChunks) {
                        $chunk = collect($chunkStrings);
                        $chunkCount++;
                        $this->info($this->colors['yellow'].'  ⏺ Processing chunk '.
                            $this->colors['reset']."{$chunkCount}/{$totalChunks}".
                            $this->colors['gray'].' ('.$chunk->count().' strings)'.
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
                            $translatedKeys = [];
                            foreach ($translatedItems as $item) {
                                $targetStringTransformer->updateString($item->key, $item->translated);
                                $translatedKeys[] = "{$filePrefix}.{$item->key}";
                            }
                            $changeDetector->saveState(
                                array_merge($seedStateKeys, $translatedKeys),
                                $stateStringList,
                                $this->sourceLocale,
                                $locale
                            );

                            // Display number of saved items
                            $this->info($this->colors['green'].'  ✓ '.$this->colors['reset']."{$localeTranslatedCount} strings saved.");

                            // Calculate and display cost
                            $this->displayCostEstimation($translator);

                            // Accumulate token usage
                            $usage = $translator->getTokenUsage();
                            $this->updateTokenUsageTotals($usage);

                        } catch (\Exception $e) {
                            $this->failedChunkCount++;
                            $this->error('Translation failed: '.$e->getMessage());
                        }
                    });
            }

            // Display translation summary for each language
            $this->displayTranslationSummary($locale, $localeFileCount, $localeStringCount, $localeTranslatedCount);
        }

        // Completion banner: red when any chunk failed so a failed run can never
        // end looking green (issue #20).
        if ($this->failedChunkCount > 0) {
            $this->line("\n".$this->colors['red_bg'].$this->colors['white'].$this->colors['bold'].' Translation finished with failures '.$this->colors['reset']);
            $this->line($this->colors['red'].'Failed chunks: '.$this->colors['reset'].$this->failedChunkCount);
        } else {
            $this->line("\n".$this->colors['green_bg'].$this->colors['white'].$this->colors['bold'].' All translations completed '.$this->colors['reset']);
        }
        $this->line($this->colors['yellow'].'Total files processed: '.$this->colors['reset'].$fileCount);
        $this->line($this->colors['yellow'].'Total strings found: '.$this->colors['reset'].$totalStringCount);
        $this->line($this->colors['yellow'].'Total strings translated: '.$this->colors['reset'].$totalTranslatedCount);
    }

    /**
     * 비용 계산 및 표시
     */
    protected function displayCostEstimation(Translator $translator): void
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
        $this->line("\n".$this->colors['purple_bg'].$this->colors['white'].$this->colors['bold'].' File Translation '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'  File: '.
            $this->colors['reset'].$this->colors['bold'].basename($sourceFile).
            $this->colors['reset']);
        $this->line($this->colors['yellow'].'  Language: '.
            $this->colors['reset'].$this->colors['bold'].$locale.
            $this->colors['reset']);
        $this->line($this->colors['gray'].'  Source: '.$sourceFile.$this->colors['reset']);
        $this->line($this->colors['gray'].'  Target: '.$outputFile.$this->colors['reset']);
    }

    /**
     * Display translation completion summary
     */
    protected function displayTranslationSummary(string $locale, int $fileCount, int $stringCount, int $translatedCount): void
    {
        $this->line("\n".str_repeat('─', 80));
        $this->line($this->colors['green_bg'].$this->colors['white'].$this->colors['bold']." Translation Complete: {$locale} ".$this->colors['reset']);
        $this->line($this->colors['yellow'].'Files processed: '.$this->colors['reset'].$fileCount);
        $this->line($this->colors['yellow'].'Strings found: '.$this->colors['reset'].$stringCount);
        $this->line($this->colors['yellow'].'Strings translated: '.$this->colors['reset'].$translatedCount);

        // Display accumulated token usage
        if ($this->tokenUsage['total_tokens'] > 0) {
            $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Total Token Usage '.$this->colors['reset']);
            $this->line($this->colors['yellow'].'Input Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['input_tokens'].$this->colors['reset']);
            $this->line($this->colors['yellow'].'Output Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['output_tokens'].$this->colors['reset']);
            $this->line($this->colors['yellow'].'Total Tokens: '.$this->colors['reset'].$this->colors['bold'].$this->colors['purple'].$this->tokenUsage['total_tokens'].$this->colors['reset']);
        }
    }

    /**
     * Load reference translations (from all files)
     */
    protected function loadReferenceTranslations(string $file, string $targetLocale, array $sourceStringList): array
    {
        // 타겟 언어와 레퍼런스 언어들을 모두 포함
        $allReferenceLocales = array_merge([$targetLocale], $this->referenceLocales);
        $langDirectory = config('ai-translator.source_directory');
        $currentFileName = basename($file);

        return collect($allReferenceLocales)
            ->filter(fn ($referenceLocale) => $referenceLocale !== $this->sourceLocale)
            ->map(function ($referenceLocale) use ($currentFileName) {
                $referenceLocaleDir = $this->getOutputDirectoryLocale($referenceLocale);

                if (! is_dir($referenceLocaleDir)) {
                    $this->line($this->colors['gray']."    ℹ Reference directory not found: {$referenceLocale}".$this->colors['reset']);

                    return null;
                }

                // 해당 로케일 디렉토리의 모든 PHP 파일 가져오기
                $referenceFiles = glob("{$referenceLocaleDir}/*.php");

                if (empty($referenceFiles)) {
                    $this->line($this->colors['gray']."    ℹ Reference file not found: {$referenceLocale}".$this->colors['reset']);

                    return null;
                }

                $this->line($this->colors['blue'].'    ℹ Loading reference: '.
                    $this->colors['reset']."{$referenceLocale} - ".count($referenceFiles).' files');

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
                        $this->line($this->colors['gray'].'    ⚠ Reference file loading failed: '.basename($referenceFile).$this->colors['reset']);

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
            if (! isset($prioritized[$key]) && count($prioritized) < $maxItems) {
                $prioritized[$key] = $value;
            }

            if (count($prioritized) >= $maxItems) {
                break;
            }
        }

        return $prioritized;
    }

    /**
     * Get global translation context
     */
    protected function getGlobalContext(string $file, string $locale, int $maxContextItems): array
    {
        if ($maxContextItems <= 0) {
            return [];
        }

        $contextProvider = new TranslationContextProvider;
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $this->sourceLocale,
            $locale,
            $file,
            $maxContextItems
        );

        if (! empty($globalContext)) {
            $contextItemCount = collect($globalContext)->map(fn ($items) => count($items))->sum();
            $this->info($this->colors['blue'].'    ℹ Using global context: '.
                $this->colors['reset'].count($globalContext).' files, '.
                $contextItemCount.' items');
        } else {
            $this->line($this->colors['gray'].'    ℹ No global context available'.$this->colors['reset']);
        }

        return $globalContext;
    }

    /**
     * Setup translator
     */
    protected function setupTranslator(
        string $file,
        Collection $chunk,
        array $referenceStringList,
        string $locale,
        array $globalContext
    ): Translator {
        // 파일 정보 표시
        $outputFile = $this->getOutputDirectoryLocale($locale).'/'.basename($file);
        $this->displayFileInfo($file, $locale, $outputFile);

        // 레퍼런스 정보를 적절한 형식으로 변환
        $references = [];
        foreach ($referenceStringList as $reference) {
            $referenceLocale = $reference['locale'];
            $referenceStrings = $reference['strings'];
            $references[$referenceLocale] = $referenceStrings;
        }

        $translator = TranslatorFactory::make(
            $file,
            $chunk->toArray(),
            $this->sourceLocale,
            $locale,
            $references,
            [],
            $globalContext,
        );

        return $this->wireTranslatorOutput($translator, $chunk->count());
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
        // 디렉토리만 필터링하고 _로 시작하는 디렉토리 제외
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root.'/'.$directory) && ! str_starts_with($directory, '_');
        });

        return collect($directories)->values()->toArray();
    }

    /**
     * 출력 디렉토리 경로 가져오기
     */
    public function getOutputDirectoryLocale(string $locale): string
    {
        return config('ai-translator.source_directory').'/'.$locale;
    }

    /**
     * 문자열 파일 경로 목록 가져오기
     */
    public function getStringFilePaths(string $locale): array
    {
        $files = [];
        $root = $this->sourceDirectory.'/'.$locale;
        $directories = array_diff(scandir($root), ['.', '..']);
        foreach ($directories as $directory) {
            // PHP 파일만 필터링
            if (pathinfo($directory, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $files[] = $root.'/'.$directory;
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

        if (! empty($invalidLocales)) {
            $this->warn('The following locales are invalid or not available: '.implode(', ', $invalidLocales));
            $this->info('Available locales: '.implode(', ', $availableLocales));
        }

        return $validLocales;
    }
}
