<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\JSONTranslationContextProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Artisan command that translates JSON language files with nested directory structure using LLMs
 * with support for multiple locales, reference languages, chunking for large files, and customizable context settings
 */
class TranslateJsonStructured extends Command
{
    protected $signature = 'ai-translator:translate-json-structured
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translates JSON language files with nested directory structure using LLMs with support for multiple locales, reference languages, chunking for large files, and customizable context settings';

    /**
     * Constants for magic numbers
     */
    protected const MAX_LINE_BREAKS = 5;

    protected const SHORT_STRING_LENGTH = 50;

    protected const PRIORITY_RATIO = 0.7;

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
            "Translates JSON language files with nested directory structure using AI technology\n".
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

        // Set chunk size
        if ($nonInteractive || $this->option('chunk')) {
            $this->chunkSize = (int) ($this->option('chunk') ?? $this->defaultChunkSize);
            $this->info($this->colors['green'].'✓ Chunk size: '.
                $this->colors['reset'].$this->colors['bold'].$this->chunkSize.
                $this->colors['reset']);
        } else {
            $this->chunkSize = (int) $this->ask(
                $this->colors['yellow'].'Enter the chunk size for translation. Translate strings in a batch. The higher, the cheaper.'.$this->colors['reset'],
                $this->defaultChunkSize
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

        return 0;
    }

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Laravel AI Translator - JSON Structured Files '.$this->colors['reset']);
        $this->line($this->colors['gray'].'Translating JSON language files with nested directory structure using AI technology'.$this->colors['reset']);
        $this->line(str_repeat('─', 80)."\n");
    }

    /**
     * Language selection helper method
     *
     * @param  string  $question  Question to ask
     * @param  bool  $multiple  Whether to allow multiple selections
     * @param  string|null  $default  Default value
     * @return array|string Selected language(s)
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
        // Get locales specified from command line
        $specifiedLocales = $this->option('locale');

        // Get all available locales
        $availableLocales = $this->getExistingLocales();

        // If locales are specified, validate and use them; otherwise use all locales
        $locales = ! empty($specifiedLocales)
            ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
            : $availableLocales;

        if (empty($locales)) {
            $this->error('No valid locales specified or found for translation.');

            return;
        }

        $fileCount = 0;
        $totalStringCount = 0;
        $totalTranslatedCount = 0;

        foreach ($locales as $locale) {
            // Skip languages that are the same as source or in the skip list
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

            // Get source file list
            $files = $this->getStringFilePaths($this->sourceLocale);

            foreach ($files as $file) {
                // Calculate relative path from source directory
                $sourceBaseDir = $this->sourceDirectory.'/'.$this->sourceLocale;
                $relativePath = str_replace($sourceBaseDir.'/', '', $file);

                // Target file path (maintaining directory structure)
                $outputFile = $this->getOutputDirectoryLocale($locale).'/'.$relativePath;

                // Create output directory if it doesn't exist
                $outputDir = dirname($outputFile);
                if (! is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }

                if (in_array(basename($file), config('ai-translator.skip_files', []))) {
                    $this->warn('Skipping file  '.basename($file).'.');

                    continue;
                }

                $this->displayFileInfo($file, $locale, $outputFile);

                $localeFileCount++;
                $fileCount++;

                // Load source strings
                $transformer = new JSONLangTransformer($file);
                $sourceStringList = $transformer->flatten();

                // Load target strings (or create)
                $targetStringTransformer = new JSONLangTransformer($outputFile);

                // Filter untranslated strings only and skip very long texts
                $sourceStringList = collect($sourceStringList)
                    ->filter(function ($value, $key) use ($targetStringTransformer) {
                        // Skip already translated ones
                        if ($targetStringTransformer->isTranslated($key)) {
                            return false;
                        }

                        // Skip very long texts (5+ line breaks)
                        if ($this->isVeryLongText($value)) {
                            $this->line($this->colors['gray']."    ⏩ Skipping very long text: {$key}".$this->colors['reset']);

                            return false;
                        }

                        return true;
                    })
                    ->toArray();

                // Skip if no items to translate
                if (count($sourceStringList) === 0) {
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
                $totalChunks = ceil(count($sourceStringList) / $this->chunkSize);

                collect($sourceStringList)
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($locale, $file, $targetStringTransformer, $referenceStringList, $maxContextItems, &$localeTranslatedCount, &$totalTranslatedCount, &$chunkCount, $totalChunks) {
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
                            foreach ($translatedItems as $item) {
                                $targetStringTransformer->updateString($item->key, $item->translated);
                            }

                            // Display number of saved items
                            $this->info($this->colors['green'].'  ✓ '.$this->colors['reset']."{$localeTranslatedCount} strings saved.");

                            // Calculate and display cost
                            $this->displayCostEstimation($translator);

                            // Accumulate token usage
                            $usage = $translator->getTokenUsage();
                            $this->updateTokenUsageTotals($usage);

                        } catch (\Exception $e) {
                            $this->error('Translation failed: '.$e->getMessage());
                        }
                    });
            }

            // Display translation summary for each language
            $this->displayTranslationSummary($locale, $localeFileCount, $localeStringCount, $localeTranslatedCount);
        }

        // All translations completed message
        $this->line("\n".$this->colors['green_bg'].$this->colors['white'].$this->colors['bold'].' All translations completed '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'Total files processed: '.$this->colors['reset'].$fileCount);
        $this->line($this->colors['yellow'].'Total strings found: '.$this->colors['reset'].$totalStringCount);
        $this->line($this->colors['yellow'].'Total strings translated: '.$this->colors['reset'].$totalTranslatedCount);
    }

    /**
     * Calculate and display cost
     */
    protected function displayCostEstimation(AIProvider $translator): void
    {
        $usage = $translator->getTokenUsage();
        $printer = new TokenUsagePrinter($translator->getModel());
        $printer->printTokenUsageSummary($this, $usage);
        $printer->printCostEstimation($this, $usage);
    }

    /**
     * Display file information
     */
    protected function displayFileInfo(string $sourceFile, string $locale, string $outputFile): void
    {
        // Remove source directory path to display relative path
        $sourceBaseDir = $this->sourceDirectory.'/'.$this->sourceLocale;
        $relativeFile = str_replace($sourceBaseDir.'/', '', $sourceFile);

        $this->line("\n".$this->colors['purple_bg'].$this->colors['white'].$this->colors['bold'].' File Translation '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'  File: '.
            $this->colors['reset'].$this->colors['bold'].$relativeFile.
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

                // Recursively get all JSON files from the locale directory
                $referenceFiles = $this->getAllJsonFiles($referenceLocaleDir);

                if (empty($referenceFiles)) {
                    $this->line($this->colors['gray']."    ℹ Reference file not found: {$referenceLocale}".$this->colors['reset']);

                    return null;
                }

                $this->line($this->colors['blue'].'    ℹ Loading reference: '.
                    $this->colors['reset']."{$referenceLocale} - ".count($referenceFiles).' files');

                // Process similarly named files first to improve context relevance
                usort($referenceFiles, function ($a, $b) use ($currentFileName) {
                    $similarityA = similar_text($currentFileName, basename($a));
                    $similarityB = similar_text($currentFileName, basename($b));

                    return $similarityB <=> $similarityA;
                });

                $allReferenceStrings = [];
                $processedFiles = 0;

                foreach ($referenceFiles as $referenceFile) {
                    try {
                        $referenceTransformer = new JSONLangTransformer($referenceFile);
                        $referenceStringList = $referenceTransformer->flatten();

                        if (empty($referenceStringList)) {
                            continue;
                        }

                        // Apply prioritization if needed
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
     * Apply prioritization to reference strings
     */
    protected function getPrioritizedReferenceStrings(array $strings, int $maxItems): array
    {
        $prioritized = [];

        // 1. Short strings first (UI elements, buttons, etc.)
        foreach ($strings as $key => $value) {
            if (strlen($value) < self::SHORT_STRING_LENGTH && count($prioritized) < $maxItems * self::PRIORITY_RATIO) {
                $prioritized[$key] = $value;
            }
        }

        // 2. Add remaining items
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

        $contextProvider = new JSONTranslationContextProvider;
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $this->sourceLocale,
            $locale,
            $file,
            $maxContextItems
        );

        if (! empty($globalContext)) {
            $contextItemCount = collect($globalContext)->map(fn ($items) => count($items['source'] ?? []))->sum();
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
        \Illuminate\Support\Collection $chunk,
        array $referenceStringList,
        string $locale,
        array $globalContext
    ): AIProvider {
        // Remove file info display (already handled in translate() method)

        // Convert reference info to appropriate format
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
            echo $this->colors['gray'].$thinking.$this->colors['reset'];
        });

        $translator->setOnThinkingStart(function () {
            $this->line($this->colors['gray'].'    '.'🧠 AI Thinking...'.$this->colors['reset']);
        });

        $translator->setOnThinkingEnd(function () {
            $this->line($this->colors['gray'].'    '.'Thinking completed.'.$this->colors['reset']);
        });

        // Set callback for displaying translation progress
        $translator->setOnTranslated(function ($item, $status, $translatedItems) use ($chunk) {
            if ($status === TranslationStatus::COMPLETED) {
                $totalCount = $chunk->count();
                $completedCount = count($translatedItems);

                $this->line($this->colors['cyan'].'  ⟳ '.
                    $this->colors['reset'].$item->key.
                    $this->colors['gray'].' → '.
                    $this->colors['reset'].$item->translated.
                    $this->colors['gray']." ({$completedCount}/{$totalCount})".
                    $this->colors['reset']);
            }
        });

        // Set token usage callback
        $translator->setOnTokenUsage(function ($usage) {
            $isFinal = $usage['final'] ?? false;
            $inputTokens = $usage['input_tokens'] ?? 0;
            $outputTokens = $usage['output_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? 0;

            // Display real-time token usage
            $this->line($this->colors['gray'].'    Tokens: '.
                'Input='.$this->colors['green'].$inputTokens.$this->colors['gray'].', '.
                'Output='.$this->colors['green'].$outputTokens.$this->colors['gray'].', '.
                'Total='.$this->colors['purple'].$totalTokens.$this->colors['gray'].
                $this->colors['reset']);
        });

        // Set prompt logging callback
        if ($this->option('show-prompt')) {
            $translator->setOnPromptGenerated(function ($prompt, PromptType $type) {
                $typeText = match ($type) {
                    PromptType::SYSTEM => '🤖 System Prompt',
                    PromptType::USER => '👤 User Prompt',
                };

                echo "\n    {$typeText}:\n";
                echo $this->colors['gray'].'    '.str_replace("\n", $this->colors['reset']."\n    ".$this->colors['gray'], $prompt).$this->colors['reset']."\n";
            });
        }

        return $translator;
    }

    /**
     * Update token usage totals
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
     * Get list of available locales
     *
     * @return array|string[]
     */
    public function getExistingLocales(): array
    {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // Filter only directories and exclude those starting with _
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root.'/'.$directory) && ! str_starts_with($directory, '_');
        });

        return collect($directories)->values()->toArray();
    }

    /**
     * Get output directory path
     */
    public function getOutputDirectoryLocale(string $locale): string
    {
        return config('ai-translator.source_directory').'/'.$locale;
    }

    /**
     * Get string file path list (recursive JSON search)
     */
    public function getStringFilePaths(string $locale): array
    {
        $root = $this->sourceDirectory.'/'.$locale;

        if (! is_dir($root)) {
            return [];
        }

        return $this->getAllJsonFiles($root);
    }

    /**
     * Recursively find all JSON files in a directory
     */
    protected function getAllJsonFiles(string $directory): array
    {
        $files = [];

        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Validate and filter specified locales
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

    /**
     * Check if text is very long (has too many line breaks)
     *
     * @param  string|null  $text  The text to check
     * @return bool True if the text is considered very long
     */
    protected function isVeryLongText(?string $text): bool
    {
        if (is_null($text)) {
            return false;
        }

        return substr_count($text, "\n") >= self::MAX_LINE_BREAKS;
    }
}
