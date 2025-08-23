<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Support\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Support\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\PromptPlugin;

/**
 * Command to translate root JSON language files using the new plugin-based architecture
 */
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
            $this->info($this->colors['green'].'✓ Set chunk size: '.
                $this->colors['reset'].$this->colors['bold'].$this->chunkSize.
                $this->colors['reset']);
        } else {
            $this->chunkSize = (int) $this->ask(
                $this->colors['yellow'].'Enter chunk size (default: '.$this->defaultChunkSize.')'.$this->colors['reset'],
                $this->defaultChunkSize
            );
        }

        // Set max context items
        if ($nonInteractive || $this->option('max-context')) {
            $maxContextItems = (int) ($this->option('max-context') ?? $this->defaultMaxContextItems);
        } else {
            $maxContextItems = (int) $this->ask(
                $this->colors['yellow'].'Enter maximum context items (default: '.$this->defaultMaxContextItems.')'.$this->colors['reset'],
                $this->defaultMaxContextItems
            );
        }

        // Execute translation
        $this->translate($maxContextItems);

        // Display summary
        $this->displaySummary();
    }

    /**
     * Execute translation using the new TranslationBuilder
     */
    public function translate(int $maxContextItems = 100): void
    {
        // Get locales to translate
        $specifiedLocales = $this->option('locale');
        $availableLocales = $this->getExistingJsonLocales();
        $locales = ! empty($specifiedLocales)
            ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
            : $availableLocales;

        if (empty($locales)) {
            $this->error('No valid locales specified or found for translation.');
            return;
        }

        $totalTranslatedCount = 0;

        foreach ($locales as $locale) {
            // Skip source locale and configured skip locales
            if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                $this->warn("Skipping locale {$locale}.");
                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($locale);
            if (! $targetLanguageName) {
                $this->error("Language name not found for locale: {$locale}. Please add it to the config file.");
                continue;
            }

            $this->line(str_repeat('─', 80));
            $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold']." Starting {$targetLanguageName} ({$locale}) ".$this->colors['reset']);

            // Get source file path
            $sourceFile = base_path("{$this->sourceDirectory}/{$this->sourceLocale}.json");
            if (!file_exists($sourceFile)) {
                $this->error("Source file not found: {$sourceFile}");
                continue;
            }

            // Load source strings
            $transformer = new JSONLangTransformer($sourceFile);
            $strings = $transformer->getTranslatable();

            if (empty($strings)) {
                $this->warn("No strings found in {$sourceFile}");
                continue;
            }

            $stringCount = count($strings);
            if ($stringCount > $this->warningStringCount && !$this->option('force-big-files')) {
                $this->warn("Skipping {$locale}.json with {$stringCount} strings. Use --force-big-files to translate large files.");
                continue;
            }

            $this->info("\n".$this->colors['cyan']."Translating {$locale}.json".$this->colors['reset']." ({$stringCount} strings)");

            // Prepare references
            $references = [];
            foreach ($this->referenceLocales as $refLocale) {
                $refFile = base_path("{$this->sourceDirectory}/{$refLocale}.json");
                if (file_exists($refFile)) {
                    $refTransformer = new JSONLangTransformer($refFile);
                    $references[$refLocale] = $refTransformer->getTranslatable();
                }
            }

            // Prepare global context - for JSON files, use the source file itself as context
            $globalContext = $strings;

            // Chunk the strings
            $chunks = collect($strings)->chunk($this->chunkSize);
            $localeTranslatedCount = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkNumber = $chunkIndex + 1;
                $totalChunks = $chunks->count();
                $chunkCount = $chunk->count();
                
                $this->info($this->colors['gray']."  Chunk {$chunkNumber}/{$totalChunks} ({$chunkCount} strings)".$this->colors['reset']);

                try {
                    // Create TranslationBuilder instance
                    $builder = TranslationBuilder::make()
                        ->from($this->sourceLocale)
                        ->to($locale)
                        ->withPlugin(new PromptPlugin())
                        ->trackChanges(); // Enable diff tracking for efficiency

                    // Configure providers from config
                    $providerConfig = $this->getProviderConfig();
                    if ($providerConfig) {
                        $builder->withProviders(['default' => $providerConfig]);
                    }

                    // Add references if available
                    if (!empty($references)) {
                        $builder->withReference($references);
                    }

                    // Configure chunking
                    $builder->withTokenChunking($this->chunkSize * 100);

                    // Add additional rules from config
                    $additionalRules = $this->getAdditionalRules($locale);
                    if (!empty($additionalRules)) {
                        $builder->withStyle('custom', implode("\n", $additionalRules));
                    }

                    // Set progress callback
                    $builder->onProgress(function($output) {
                        if ($output->type === 'thinking' && $this->option('show-prompt')) {
                            $this->line($this->colors['purple']."Thinking: {$output->value}".$this->colors['reset']);
                        } elseif ($output->type === 'translated') {
                            $this->line($this->colors['green']."  ✓ Translated".$this->colors['reset']);
                        }
                    });

                    // Execute translation
                    $result = $builder->translate($chunk->toArray());
                    
                    // Show prompts if requested
                    if ($this->option('show-prompt')) {
                        $pluginData = $result->getMetadata('plugin_data');
                        if ($pluginData) {
                            $systemPrompt = $pluginData['system_prompt'] ?? null;
                            $userPrompt = $pluginData['user_prompt'] ?? null;
                            
                            if ($systemPrompt || $userPrompt) {
                                $this->line("\n" . str_repeat('═', 80));
                                $this->line($this->colors['purple'] . "AI PROMPTS" . $this->colors['reset']);
                                $this->line(str_repeat('═', 80));
                                
                                if ($systemPrompt) {
                                    $this->line($this->colors['cyan'] . "System Prompt:" . $this->colors['reset']);
                                    $this->line($this->colors['gray'] . $systemPrompt . $this->colors['reset']);
                                    $this->line("");
                                }
                                
                                if ($userPrompt) {
                                    $this->line($this->colors['cyan'] . "User Prompt:" . $this->colors['reset']);
                                    $this->line($this->colors['gray'] . $userPrompt . $this->colors['reset']);
                                }
                                
                                $this->line(str_repeat('═', 80) . "\n");
                            }
                        }
                    }

                    // Process results
                    $translations = $result->getTranslations();
                    $targetFile = base_path("{$this->sourceDirectory}/{$locale}.json");
                    $targetTransformer = new JSONLangTransformer($targetFile);

                    foreach ($translations as $key => $value) {
                        $targetTransformer->updateString($key, $value);
                        $localeTranslatedCount++;
                        $totalTranslatedCount++;
                    }

                    // Update token usage
                    $tokenUsageData = $result->getTokenUsage();
                    $this->tokenUsage['input_tokens'] += $tokenUsageData['input_tokens'] ?? 0;
                    $this->tokenUsage['output_tokens'] += $tokenUsageData['output_tokens'] ?? 0;
                    $this->tokenUsage['cache_creation_input_tokens'] += $tokenUsageData['cache_creation_input_tokens'] ?? 0;
                    $this->tokenUsage['cache_read_input_tokens'] += $tokenUsageData['cache_read_input_tokens'] ?? 0;
                    $this->tokenUsage['total_tokens'] += $tokenUsageData['total_tokens'] ?? 0;

                } catch (\Exception $e) {
                    $this->error("Translation failed for chunk {$chunkNumber}: " . $e->getMessage());
                    Log::error("Translation failed", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    continue;
                }
            }

            $this->info("\n".$this->colors['green']."✓ Completed {$targetLanguageName} ({$locale}): {$localeTranslatedCount} strings translated".$this->colors['reset']);
        }

        $this->info("\n".$this->colors['green'].$this->colors['bold']."Translation complete! Total: {$totalTranslatedCount} strings translated".$this->colors['reset']);
    }

    /**
     * Get provider configuration from config file
     */
    protected function getProviderConfig(): array
    {
        $provider = config('ai-translator.ai.provider');
        $model = config('ai-translator.ai.model');
        $apiKey = config('ai-translator.ai.api_key');
        
        if (!$provider || !$model || !$apiKey) {
            throw new \Exception('AI provider configuration is incomplete. Please check your config/ai-translator.php file.');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => $apiKey,
            'temperature' => config('ai-translator.ai.temperature', 0.3),
            'thinking' => config('ai-translator.ai.use_extended_thinking', false),
            'retries' => config('ai-translator.ai.retries', 1),
            'max_tokens' => config('ai-translator.ai.max_tokens', 4096),
        ];
    }

    /**
     * Get additional rules for target language
     */
    protected function getAdditionalRules(string $locale): array
    {
        $rules = [];
        
        // Get default rules
        $defaultRules = config('ai-translator.additional_rules.default', []);
        if (!empty($defaultRules)) {
            $rules = array_merge($rules, $defaultRules);
        }

        // Get language-specific rules
        $localeRules = config("ai-translator.additional_rules.{$locale}", []);
        if (!empty($localeRules)) {
            $rules = array_merge($rules, $localeRules);
        }

        // Also check for language code without region (e.g., 'en' for 'en_US')
        $langCode = explode('_', $locale)[0];
        if ($langCode !== $locale) {
            $langRules = config("ai-translator.additional_rules.{$langCode}", []);
            if (!empty($langRules)) {
                $rules = array_merge($rules, $langRules);
            }
        }

        return $rules;
    }

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->line("\n".$this->colors['cyan'].'╔═══════════════════════════════════════════════════════╗'.$this->colors['reset']);
        $this->line($this->colors['cyan'].'║'.$this->colors['reset'].$this->colors['bold'].'       Laravel AI Translator - JSON Translation         '.$this->colors['reset'].$this->colors['cyan'].'║'.$this->colors['reset']);
        $this->line($this->colors['cyan'].'╚═══════════════════════════════════════════════════════╝'.$this->colors['reset']."\n");
    }

    /**
     * Display summary
     */
    protected function displaySummary(): void
    {
        $this->line("\n".$this->colors['cyan'].'═══════════════════════════════════════════════════════'.$this->colors['reset']);
        $this->line($this->colors['bold'].'Translation Summary'.$this->colors['reset']);
        $this->line($this->colors['cyan'].'═══════════════════════════════════════════════════════'.$this->colors['reset']);

        // Display token usage
        if ($this->tokenUsage['total_tokens'] > 0) {
            $model = config('ai-translator.ai.model');
            $printer = new TokenUsagePrinter($model);
            $printer->printTokenUsageSummary($this, $this->tokenUsage);
        }

        $this->line($this->colors['cyan'].'═══════════════════════════════════════════════════════'.$this->colors['reset']."\n");
    }

    /**
     * Get existing JSON locales
     */
    protected function getExistingJsonLocales(): array
    {
        $locales = [];
        $langPath = base_path($this->sourceDirectory);
        
        if (is_dir($langPath)) {
            $files = scandir($langPath);
            foreach ($files as $file) {
                if (preg_match('/^([a-z]{2}(?:_[A-Z]{2})?)\.json$/', $file, $matches)) {
                    $locales[] = $matches[1];
                }
            }
        }

        return $locales;
    }

    /**
     * Validate and filter locales
     */
    protected function validateAndFilterLocales(array $specifiedLocales, array $availableLocales): array
    {
        $validLocales = [];
        
        foreach ($specifiedLocales as $locale) {
            if (in_array($locale, $availableLocales)) {
                $validLocales[] = $locale;
            } else {
                $this->warn("Locale '{$locale}' not found in available locales.");
            }
        }

        return $validLocales;
    }

    /**
     * Choose languages interactively
     */
    protected function choiceLanguages(string $question, bool $multiple = false, ?string $default = null)
    {
        $locales = $this->getExistingJsonLocales();
        
        if (empty($locales)) {
            $this->error('No JSON language files found.');
            return $multiple ? [] : null;
        }

        // Prepare choices with language names
        $choices = [];
        foreach ($locales as $locale) {
            $name = LanguageConfig::getLanguageName($locale);
            $choices[] = $name ? "{$locale} ({$name})" : $locale;
        }

        if ($multiple) {
            $selected = $this->choice($question, $choices, null, null, true);
            $result = [];
            foreach ($selected as $choice) {
                $locale = explode(' ', $choice)[0];
                $result[] = $locale;
            }
            return $result;
        } else {
            // Convert locale default to array index
            $defaultIndex = null;
            if ($default) {
                foreach ($choices as $index => $choice) {
                    if (str_starts_with($choice, $default . ' ')) {
                        $defaultIndex = $index;
                        break;
                    }
                }
            }
            
            $selected = $this->choice($question, $choices, $defaultIndex);
            return explode(' ', $selected)[0];
        }
    }
}