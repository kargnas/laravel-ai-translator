<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Support\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Support\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\TranslationContextPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\PromptPlugin;

/**
 * Artisan command that translates PHP language files using the plugin-based architecture
 * 
 * This command has been refactored to use the new TranslationBuilder and plugin system,
 * removing all legacy AI dependencies while maintaining the same user interface.
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

    protected $description = 'Translates PHP language files using AI technology with plugin-based architecture';

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
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => 0,
    ];

    /**
     * Color codes for console output
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
            if (!empty($this->referenceLocales)) {
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
     * Execute translation using TranslationBuilder
     */
    public function translate(int $maxContextItems = 100): void
    {
        // Get locales to translate
        $specifiedLocales = $this->option('locale');
        $availableLocales = $this->getExistingLocales();
        $locales = !empty($specifiedLocales)
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
            // Skip source locale and configured skip locales
            if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                $this->warn('Skipping locale '.$locale.'.');
                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($locale);
            if (!$targetLanguageName) {
                $this->error("Language name not found for locale: {$locale}. Please add it to the config file.");
                continue;
            }

            $this->line(str_repeat('─', 80));
            $this->line(str_repeat('─', 80));
            $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold']." Starting {$targetLanguageName} ({$locale}) ".$this->colors['reset']);

            $localeFileCount = 0;
            $localeStringCount = 0;
            $localeTranslatedCount = 0;

            // Get source files
            $files = $this->getStringFilePaths($this->sourceLocale);

            foreach ($files as $file) {
                // Get relative file path
                $relativeFilePath = $this->getRelativePath($file);
                
                // Prepare transformer
                $transformer = new PHPLangTransformer($file);
                $strings = $transformer->getTranslatable();

                if (empty($strings)) {
                    continue;
                }

                // Check for large files
                $stringCount = count($strings);
                if ($stringCount > $this->warningStringCount && !$this->option('force-big-files')) {
                    $this->warn("Skipping {$relativeFilePath} with {$stringCount} strings. Use --force-big-files to translate large files.");
                    continue;
                }

                $this->info("\n".$this->colors['cyan']."Translating {$relativeFilePath}".$this->colors['reset']." ({$stringCount} strings)");

                try {
                    // Create TranslationBuilder instance with plugins
                    $builder = TranslationBuilder::make()
                        ->from($this->sourceLocale)
                        ->to($locale)
                        ->trackChanges()
                        ->withPlugin(new TranslationContextPlugin())
                        ->withPlugin(new PromptPlugin());

                    // Configure providers from config
                    $providerConfig = $this->getProviderConfig();
                    if ($providerConfig) {
                        $builder->withProviders(['default' => $providerConfig]);
                    }

                    // Configure token chunking
                    $builder->withTokenChunking($this->chunkSize);

                    // Add metadata for context
                    $builder->withMetadata([
                        'current_file_path' => $file,
                        'filename' => basename($file),
                        'parent_key' => $this->getFilePrefix($file),
                        'max_context_items' => $maxContextItems,
                    ]);

                    // Add references if available for the same relative file
                    if (!empty($this->referenceLocales)) {
                        $references = [];
                        foreach ($this->referenceLocales as $refLocale) {
                            $refFile = str_replace("/{$this->sourceLocale}/", "/{$refLocale}/", $file);
                            if (file_exists($refFile)) {
                                $refTransformer = new PHPLangTransformer($refFile);
                                $references[$refLocale] = $refTransformer->getTranslatable();
                            }
                        }
                        if (!empty($references)) {
                            $builder->withReference($references);
                        }
                    }

                    // Add additional rules from config for the target locale
                    $additionalRules = $this->getAdditionalRules($locale);
                    if (!empty($additionalRules)) {
                        $builder->withStyle('custom', implode("\n", $additionalRules));
                    }

                    // Set progress callback
                    $builder->onProgress(function($output) {
                        if ($output->type === 'thinking' && $this->option('show-prompt')) {
                            $this->line($this->colors['purple']."Thinking: {$output->value}".$this->colors['reset']);
                        } elseif ($output->type === 'translated') {
                            $this->line($this->colors['green']."  ✓ {$output->key}".$this->colors['reset']);
                        } elseif ($output->type === 'progress') {
                            $this->line($this->colors['gray']."  Progress: {$output->value}".$this->colors['reset']);
                        }
                    });

                    // Execute translation
                    $result = $builder->translate($strings);

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

                    // Process results and save to target file
                    $translations = $result->getTranslations();
                    $targetFile = str_replace("/{$this->sourceLocale}/", "/{$locale}/", $file);
                    $targetTransformer = new PHPLangTransformer($targetFile);

                    // Get translations for the specific locale
                    $localeTranslations = $translations[$locale] ?? [];
                    
                    foreach ($localeTranslations as $key => $value) {
                        $targetTransformer->updateString($key, $value);
                        $localeTranslatedCount++;
                        $totalTranslatedCount++;
                    }

                    // Update token usage
                    $tokenUsageData = $result->getTokenUsage();
                    
                    // Debug: Print raw token usage
                    $this->line("\n" . $this->colors['yellow'] . "[DEBUG] Raw Token Usage:" . $this->colors['reset']);
                    $this->line($this->colors['gray'] . json_encode($tokenUsageData, JSON_PRETTY_PRINT) . $this->colors['reset']);
                    
                    $this->tokenUsage['input_tokens'] += $tokenUsageData['input_tokens'] ?? 0;
                    $this->tokenUsage['output_tokens'] += $tokenUsageData['output_tokens'] ?? 0;
                    $this->tokenUsage['cache_creation_input_tokens'] += $tokenUsageData['cache_creation_input_tokens'] ?? 0;
                    $this->tokenUsage['cache_read_input_tokens'] += $tokenUsageData['cache_read_input_tokens'] ?? 0;
                    $this->tokenUsage['total_tokens'] += $tokenUsageData['total_tokens'] ?? 0;

                } catch (\Exception $e) {
                    $this->error("Translation failed for {$relativeFilePath}: " . $e->getMessage());
                    Log::error("Translation failed", [
                        'file' => $relativeFilePath,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }

                $localeFileCount++;
                $localeStringCount += $stringCount;
            }

            $fileCount += $localeFileCount;
            $totalStringCount += $localeStringCount;

            $this->info("\n".$this->colors['green']."✓ Completed {$targetLanguageName} ({$locale}): {$localeFileCount} files, {$localeTranslatedCount} strings translated".$this->colors['reset']);
        }

        $this->info("\n".$this->colors['green'].$this->colors['bold']."Translation complete! Total: {$fileCount} files, {$totalTranslatedCount} strings translated".$this->colors['reset']);
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
     * Get file prefix for namespacing
     */
    protected function getFilePrefix(string $file): string
    {
        $relativePath = str_replace(base_path() . '/', '', $file);
        $relativePath = str_replace($this->sourceDirectory . '/', '', $relativePath);
        $relativePath = str_replace($this->sourceLocale . '/', '', $relativePath);
        $relativePath = str_replace('.php', '', $relativePath);
        
        return str_replace('/', '.', $relativePath);
    }

    /**
     * Get relative path for display
     */
    protected function getRelativePath(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->line("\n".$this->colors['cyan'].'╔═══════════════════════════════════════════════════════╗'.$this->colors['reset']);
        $this->line($this->colors['cyan'].'║'.$this->colors['reset'].$this->colors['bold'].'       Laravel AI Translator - String Translation       '.$this->colors['reset'].$this->colors['cyan'].'║'.$this->colors['reset']);
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

        // Display raw token usage
        if ($this->tokenUsage['total_tokens'] > 0 || $this->tokenUsage['input_tokens'] > 0) {
            $this->line("\n" . $this->colors['yellow'] . "[DEBUG] Total Raw Token Usage:" . $this->colors['reset']);
            $this->line($this->colors['gray'] . json_encode($this->tokenUsage, JSON_PRETTY_PRINT) . $this->colors['reset'] . "\n");
            
            $model = config('ai-translator.ai.model');
            $printer = new TokenUsagePrinter($model);
            $printer->printTokenUsageSummary($this, $this->tokenUsage);
            $printer->printCostEstimation($this, $this->tokenUsage);
        }

        $this->line($this->colors['cyan'].'═══════════════════════════════════════════════════════'.$this->colors['reset']."\n");
    }

    /**
     * Get existing locales
     */
    protected function getExistingLocales(): array
    {
        $locales = [];
        $langPath = base_path($this->sourceDirectory);
        
        if (is_dir($langPath)) {
            $dirs = scandir($langPath);
            foreach ($dirs as $dir) {
                if ($dir !== '.' && $dir !== '..' && is_dir("{$langPath}/{$dir}") && !str_starts_with($dir, 'backup')) {
                    $locales[] = $dir;
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
                // Allow non-existent/custom locales for output; warn and include
                $this->warn("Locale '{$locale}' not found in available locales. It will be created as needed.");
                $validLocales[] = $locale;
            }
        }

        return $validLocales;
    }

    /**
     * Choose languages interactively
     */
    protected function choiceLanguages(string $question, bool $multiple = false, ?string $default = null)
    {
        $locales = $this->getExistingLocales();
        
        if (empty($locales)) {
            $this->error('No language directories found.');
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

    /**
     * Get PHP string file paths
     */
    protected function getStringFilePaths(string $locale): array
    {
        $files = [];
        $langPath = base_path("{$this->sourceDirectory}/{$locale}");
        
        if (is_dir($langPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($langPath)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}