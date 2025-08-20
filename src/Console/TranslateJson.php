<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

class TranslateJson extends Command
{
    protected $signature = 'ai-translator:translate-json
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translate root JSON language files such as lang/en.json';

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
            "Translates JSON language files using AI technology\n".
            "  Source Directory: {$sourceDirectory}\n".
            "  Default Source Locale: {$sourceLocale}"
        );
    }

    public function handle()
    {
        // Display header
        $this->displayHeader();

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
        $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Laravel AI Translator - JSON Files '.$this->colors['reset']);
        $this->line($this->colors['gray'].'Translating JSON language files using AI technology'.$this->colors['reset']);
        $this->line(str_repeat('─', 80)."\n");
    }

    /**
     * Language selection helper method
     *
     * @param  string  $question  Question
     * @param  bool  $multiple  Multiple selection
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
     * @param  int  $maxContextItems  Maximum context items
     */
    public function translate(int $maxContextItems = 100): void
    {
        // Get specified locales from command line
        $specifiedLocales = $this->option('locale');

        // Get all available locales
        $availableLocales = $this->getExistingLocales();

        // Use specified locales if provided, otherwise use all locales
        // For JSON translation, we allow non-existing target locales
        $locales = ! empty($specifiedLocales)
            ? $specifiedLocales
            : $availableLocales;

        if (empty($locales)) {
            $this->error('No valid locales specified or found for translation.');

            return;
        }

        $totalStringCount = 0;
        $totalTranslatedCount = 0;

        foreach ($locales as $locale) {
            // Skip source language and skip list
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

            $result = $this->translateLocale($locale, $maxContextItems);
            $totalStringCount += $result['stringCount'];
            $totalTranslatedCount += $result['translatedCount'];
        }

        // Display total completion message
        $this->line("\n".$this->colors['green_bg'].$this->colors['white'].$this->colors['bold'].' All translations completed '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'Total strings found: '.$this->colors['reset'].$totalStringCount);
        $this->line($this->colors['yellow'].'Total strings translated: '.$this->colors['reset'].$totalTranslatedCount);

        // Display accumulated token usage
        if ($this->tokenUsage['total_tokens'] > 0) {
            $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Total Token Usage '.$this->colors['reset']);
            $this->line($this->colors['yellow'].'Input Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['input_tokens'].$this->colors['reset']);
            $this->line($this->colors['yellow'].'Output Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['output_tokens'].$this->colors['reset']);
            $this->line($this->colors['yellow'].'Total Tokens: '.$this->colors['reset'].$this->colors['bold'].$this->colors['purple'].$this->tokenUsage['total_tokens'].$this->colors['reset']);
        }
    }

    /**
     * Translate single locale
     *
     * @param  string  $locale  Target locale
     * @param  int  $maxContextItems  Maximum context items
     * @return array Translation result
     */
    protected function translateLocale(string $locale, int $maxContextItems): array
    {
        $sourceFile = "{$this->sourceDirectory}/{$this->sourceLocale}.json";
        if (! file_exists($sourceFile)) {
            $this->error("Source file not found: {$sourceFile}");

            return ['stringCount' => 0, 'translatedCount' => 0];
        }

        $targetFile = "{$this->sourceDirectory}/{$locale}.json";

        $this->displayFileInfo($sourceFile, $locale, $targetFile);

        $sourceTransformer = new JSONLangTransformer($sourceFile);
        $targetTransformer = new JSONLangTransformer($targetFile);

        $sourceStrings = $sourceTransformer->flatten();
        $stringsToTranslate = collect($sourceStrings)
            ->filter(fn ($v, $k) => ! $targetTransformer->isTranslated($k))
            ->toArray();

        if (count($stringsToTranslate) === 0) {
            $this->info($this->colors['green'].'  ✓ '.$this->colors['reset'].'All strings are already translated. Skipping.');

            return ['stringCount' => 0, 'translatedCount' => 0];
        }

        $stringCount = count($stringsToTranslate);
        $translatedCount = 0;

        // Check if there are many strings to translate
        if ($stringCount > $this->warningStringCount && ! $this->option('force-big-files')) {
            if (
                ! $this->confirm(
                    $this->colors['yellow'].'⚠️ Warning: '.$this->colors['reset'].
                    "File has {$stringCount} strings to translate. This could be expensive. Continue?",
                    true
                )
            ) {
                $this->warn('Translation stopped by user.');

                return ['stringCount' => 0, 'translatedCount' => 0];
            }
        }

        // Load reference translations
        $referenceStringList = $this->loadReferenceTranslations($sourceFile, $locale);

        // Get global context
        $globalContext = $this->getGlobalContext($sourceFile, $locale, $maxContextItems);

        // Process in chunks
        $chunkCount = 0;
        $totalChunks = ceil($stringCount / $this->chunkSize);

        collect($stringsToTranslate)
            ->chunk($this->chunkSize)
            ->each(function ($chunk) use ($locale, $sourceFile, $targetTransformer, $referenceStringList, $globalContext, &$translatedCount, &$chunkCount, $totalChunks) {
                $chunkCount++;
                $this->info($this->colors['yellow'].'  ⏺ Processing chunk '.
                    $this->colors['reset']."{$chunkCount}/{$totalChunks}".
                    $this->colors['gray'].' ('.$chunk->count().' strings)'.
                    $this->colors['reset']);

                // Configure translator
                $translator = $this->setupTranslator(
                    $sourceFile,
                    $chunk,
                    $referenceStringList,
                    $locale,
                    $globalContext
                );

                try {
                    // Execute translation
                    $translatedItems = $translator->translate();
                    $translatedCount += count($translatedItems);

                    // Save translation results
                    foreach ($translatedItems as $item) {
                        $targetTransformer->updateString($item->key, $item->translated);
                    }

                    // Display number of saved items
                    $this->info($this->colors['green'].'  ✓ '.$this->colors['reset']."{$translatedCount} strings saved.");

                    // Calculate and display cost
                    $this->displayCostEstimation($translator);

                    // Accumulate token usage
                    $usage = $translator->getTokenUsage();
                    $this->updateTokenUsageTotals($usage);

                } catch (\Exception $e) {
                    $this->error('Translation failed: '.$e->getMessage());
                }
            });

        // Display translation summary
        $this->displayTranslationSummary($locale, $stringCount, $translatedCount);

        return ['stringCount' => $stringCount, 'translatedCount' => $translatedCount];
    }

    /**
     * Display file info
     */
    protected function displayFileInfo(string $sourceFile, string $locale, string $outputFile): void
    {
        $this->line("\n".$this->colors['purple_bg'].$this->colors['white'].$this->colors['bold'].' JSON File Translation '.$this->colors['reset']);
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
     * Display translation summary
     */
    protected function displayTranslationSummary(string $locale, int $stringCount, int $translatedCount): void
    {
        $this->line("\n".str_repeat('─', 80));
        $this->line($this->colors['green_bg'].$this->colors['white'].$this->colors['bold']." Translation Complete: {$locale} ".$this->colors['reset']);
        $this->line($this->colors['yellow'].'Strings found: '.$this->colors['reset'].$stringCount);
        $this->line($this->colors['yellow'].'Strings translated: '.$this->colors['reset'].$translatedCount);
    }

    /**
     * Load reference translations
     */
    protected function loadReferenceTranslations(string $sourceFile, string $targetLocale): array
    {
        // Include target language and reference languages
        $allReferenceLocales = array_merge([$targetLocale], $this->referenceLocales);

        return collect($allReferenceLocales)
            ->filter(fn ($referenceLocale) => $referenceLocale !== $this->sourceLocale)
            ->map(function ($referenceLocale) {
                $referenceFile = "{$this->sourceDirectory}/{$referenceLocale}.json";

                if (! file_exists($referenceFile)) {
                    $this->line($this->colors['gray']."    ℹ Reference file not found: {$referenceLocale}.json".$this->colors['reset']);

                    return null;
                }

                try {
                    $referenceTransformer = new JSONLangTransformer($referenceFile);
                    $referenceStrings = $referenceTransformer->flatten();

                    if (empty($referenceStrings)) {
                        return null;
                    }

                    $this->line($this->colors['blue'].'    ℹ Loading reference: '.
                        $this->colors['reset']."{$referenceLocale} - ".count($referenceStrings).' strings');

                    return [
                        'locale' => $referenceLocale,
                        'strings' => $referenceStrings,
                    ];
                } catch (\Exception $e) {
                    $this->line($this->colors['gray']."    ⚠ Reference file loading failed: {$referenceLocale}.json".$this->colors['reset']);

                    return null;
                }
            })
            ->filter()
            ->values()
            ->toArray();
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
        \Illuminate\Support\Collection $chunk,
        array $referenceStringList,
        string $locale,
        array $globalContext
    ): AIProvider {
        // Convert reference info to proper format
        $references = [];
        foreach ($referenceStringList as $reference) {
            $referenceLocale = $reference['locale'];
            $referenceStrings = $reference['strings'];
            $references[$referenceLocale] = $referenceStrings;
        }

        // Create AIProvider instance
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

        // Set translation progress callback
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
     * Display cost estimation
     */
    protected function displayCostEstimation(AIProvider $translator): void
    {
        $usage = $translator->getTokenUsage();
        $printer = new TokenUsagePrinter($translator->getModel());
        $printer->printTokenUsageSummary($this, $usage);
        $printer->printCostEstimation($this, $usage);
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
     * Validate and filter locales
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

    public function getExistingLocales(): array
    {
        $files = glob("{$this->sourceDirectory}/*.json");

        return collect($files)
            ->map(fn ($file) => pathinfo($file, PATHINFO_FILENAME))
            ->filter(fn ($filename) => !str_starts_with($filename, '_'))
            ->values()
            ->toArray();
    }
}
