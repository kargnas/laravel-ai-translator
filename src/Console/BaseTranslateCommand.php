<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Transformers\TransformerInterface;

abstract class BaseTranslateCommand extends Command
{
    /**
     * @var array<string, string>
     */
    protected array $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'bg_green' => "\033[42m",
        'bg_blue' => "\033[44m",
    ];

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
     * Get the transformer instance for this command
     */
    abstract protected function getTransformer(): TransformerInterface;

    /**
     * Get language files for the given locale
     */
    abstract protected function getLanguageFiles(string $locale): array;

    /**
     * Check if source language files exist
     */
    abstract protected function hasSourceFiles(): bool;

    /**
     * Get the source file path for a given file and locale
     */
    abstract protected function getSourceFilePath(string $file, string $locale): string;

    /**
     * Get the target file path for a given file and locale
     */
    abstract protected function getTargetFilePath(string $file, string $locale): string;

    public function handle(): int
    {
        $this->displayHeader();
        $this->initializeConfiguration();

        if (!$this->hasSourceFiles()) {
            $this->error("No source language files found in the '{$this->sourceLocale}' directory.");
            return self::FAILURE;
        }

        $targetLocales = $this->getTargetLocales();
        $referenceLocales = $this->getReferenceLocales($targetLocales);
        
        $this->referenceLocales = $referenceLocales;
        $maxContextItems = (int) ($this->option('max-context') ?? $this->defaultMaxContextItems);

        foreach ($targetLocales as $locale) {
            $this->translateLocale($locale, $maxContextItems);
        }

        $this->displaySummary();
        return self::SUCCESS;
    }

    protected function initializeConfiguration(): void
    {
        $this->sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $this->sourceLocale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
        $this->chunkSize = (int) ($this->option('chunk') ?? config('ai-translator.chunk_size', $this->defaultChunkSize));
    }

    protected function getTargetLocales(): array
    {
        if ($this->option('locale')) {
            return is_array($this->option('locale')) 
                ? $this->option('locale') 
                : explode(',', $this->option('locale'));
        }

        if ($this->option('non-interactive')) {
            $this->error('No target locales specified. Use --locale option or remove --non-interactive flag.');
            exit(1);
        }

        return $this->askForTargetLocales();
    }

    protected function askForTargetLocales(): array
    {
        $availableLocales = $this->getAvailableLocales();
        
        if (empty($availableLocales)) {
            $this->error('No target locales available for translation.');
            exit(1);
        }

        $choices = [];
        foreach ($availableLocales as $locale) {
            $name = LanguageConfig::getLanguageName($locale);
            $choices[] = sprintf('%s%s%s (%s)', $this->colors['cyan'], $locale, $this->colors['reset'], $name);
        }

        $selected = $this->choice(
            'Select target locales to translate (comma-separated numbers for multiple)',
            $choices,
            null,
            null,
            true
        );

        return array_map(function ($choice) {
            preg_match('/^([a-z_]+)/i', strip_tags($choice), $matches);
            return $matches[1] ?? '';
        }, $selected);
    }

    protected function getAvailableLocales(): array
    {
        // Initialize source directory and locale if not already set
        if (!isset($this->sourceDirectory)) {
            $this->sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        }
        if (!isset($this->sourceLocale)) {
            $this->sourceLocale = config('ai-translator.source_locale', 'en');
        }
        
        $locales = [];
        $directories = glob("{$this->sourceDirectory}/*", GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $locale = basename($dir);
            if ($locale !== $this->sourceLocale) {
                $locales[] = $locale;
            }
        }

        $jsonFiles = glob("{$this->sourceDirectory}/*.json");
        foreach ($jsonFiles as $file) {
            $locale = basename($file, '.json');
            if ($locale !== $this->sourceLocale && !in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    protected function getReferenceLocales(array $targetLocales): array
    {
        if ($this->option('reference')) {
            $refs = is_array($this->option('reference'))
                ? $this->option('reference')
                : explode(',', $this->option('reference'));
            return array_diff($refs, $targetLocales);
        }

        if ($this->option('non-interactive')) {
            return [];
        }

        return $this->askForReferenceLocales($targetLocales);
    }

    protected function askForReferenceLocales(array $excludeLocales): array
    {
        $availableLocales = array_diff($this->getAvailableLocales(), $excludeLocales);
        
        if (empty($availableLocales)) {
            return [];
        }

        if (!$this->confirm('Do you want to use reference languages for better translation quality?', true)) {
            return [];
        }

        $choices = ['none' => 'No reference languages'];
        foreach ($availableLocales as $locale) {
            $name = LanguageConfig::getLanguageName($locale);
            $choices[$locale] = sprintf('%s%s%s (%s)', $this->colors['cyan'], $locale, $this->colors['reset'], $name);
        }

        $selected = $this->choice(
            'Select reference languages (high-quality translations to guide the AI)',
            array_values($choices),
            0,
            null,
            true
        );

        if (in_array($choices['none'], $selected)) {
            return [];
        }

        return array_map(function ($choice) use ($choices) {
            $locale = array_search($choice, $choices);
            return $locale !== 'none' ? $locale : null;
        }, array_filter($selected, fn($s) => $s !== $choices['none']));
    }

    protected function translateLocale(string $locale, int $maxContextItems): void
    {
        $this->newLine();
        $this->displayLocaleHeader($locale);

        $files = $this->getLanguageFiles($locale);
        
        if (empty($files)) {
            $this->info("No files to translate for locale: {$locale}");
            return;
        }

        foreach ($files as $file) {
            $this->translateFile($file, $locale, $maxContextItems);
        }
    }

    protected function translateFile(string $file, string $locale, int $maxContextItems): void
    {
        $sourceFile = $this->getSourceFilePath($file, $this->sourceLocale);
        $targetFile = $this->getTargetFilePath($file, $locale);

        $this->displayFileHeader($file, $locale, $sourceFile, $targetFile);

        $transformer = $this->getTransformer();
        $sourceStrings = $transformer->parse($sourceFile);
        $targetStrings = $transformer->parse($targetFile);

        $missingKeys = $this->getMissingKeys($sourceStrings, $targetStrings);
        
        if (empty($missingKeys)) {
            $this->displaySuccess("✓ All strings are already translated");
            return;
        }

        $this->processTranslation($missingKeys, $sourceStrings, $targetStrings, $locale, $targetFile, $maxContextItems);
    }

    protected function getMissingKeys(array $sourceStrings, array $targetStrings): array
    {
        $transformer = $this->getTransformer();
        $flatSource = $transformer->flatten($sourceStrings);
        $flatTarget = $transformer->flatten($targetStrings);

        return array_filter(
            array_keys($flatSource),
            fn($key) => !isset($flatTarget[$key]) || trim($flatTarget[$key]) === ''
        );
    }

    protected function processTranslation(
        array $missingKeys,
        array $sourceStrings,
        array $targetStrings,
        string $locale,
        string $targetFile,
        int $maxContextItems
    ): void {
        $totalCount = count($missingKeys);
        $this->displayInfo("ℹ Found {$totalCount} strings to translate");

        if ($totalCount > $this->warningStringCount && !$this->confirmLargeTranslation($totalCount)) {
            $this->displayWarning("⚠ Translation cancelled");
            return;
        }

        $transformer = $this->getTransformer();
        $contextProvider = new TranslationContextProvider($this->sourceDirectory, $transformer);
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $locale,
            $this->referenceLocales,
            $maxContextItems
        );

        $this->displayContext($globalContext);

        $chunks = collect($missingKeys)->chunk($this->chunkSize);
        $chunkNumber = 0;
        $totalChunks = $chunks->count();

        foreach ($chunks as $chunk) {
            $chunkNumber++;
            $this->processChunk(
                $chunk,
                $chunkNumber,
                $totalChunks,
                $sourceStrings,
                $targetStrings,
                $locale,
                $targetFile,
                $globalContext,
                $transformer
            );
        }

        $this->info("✓ Translation completed for {$targetFile}");
    }

    protected function processChunk(
        Collection $chunk,
        int $chunkNumber,
        int $totalChunks,
        array &$sourceStrings,
        array &$targetStrings,
        string $locale,
        string $targetFile,
        array $globalContext,
        TransformerInterface $transformer
    ): void {
        $this->displayChunkProgress($chunkNumber, $totalChunks, $chunk->count());

        $flatSource = $transformer->flatten($sourceStrings);
        $chunkData = [];
        
        foreach ($chunk as $key) {
            if (isset($flatSource[$key])) {
                $chunkData[$key] = $flatSource[$key];
            }
        }

        if (empty($chunkData)) {
            return;
        }

        $this->displayInfo("  ℹ Using context: {$globalContext['file_count']} files, {$globalContext['item_count']} items");

        if ($this->option('show-prompt')) {
            $this->displayPrompt($chunkData, $locale, $globalContext);
        }

        try {
            $translations = $this->performTranslation($chunkData, $locale, $globalContext);
            
            if (!empty($translations['translations'])) {
                $this->applyTranslations($translations['translations'], $targetStrings, $transformer, $targetFile);
                $this->updateTokenUsage($translations);
            }
        } catch (\Exception $e) {
            $this->displayError("  ✗ Translation failed: " . $e->getMessage());
        }
    }

    protected function performTranslation(array $strings, string $locale, array $globalContext): array
    {
        $aiProvider = AIProvider::make();
        
        if (!$aiProvider->hasExtendedThinking()) {
            $this->displayInfo("  🧠 AI Translating...");
        }

        return $aiProvider->translate($strings, $this->sourceLocale, $locale, $globalContext, PromptType::DEFAULT);
    }

    protected function applyTranslations(
        array $translations,
        array &$targetStrings,
        TransformerInterface $transformer,
        string $targetFile
    ): void {
        foreach ($translations as $item) {
            if ($item['status'] === TranslationStatus::SUCCESS) {
                $targetStrings = $transformer->unflatten($targetStrings, $item['key'], $item['translated']);
                $this->displayTranslation($item['key'], $item['translated']);
            } elseif ($item['status'] === TranslationStatus::SKIPPED) {
                $this->displaySkipped($item['key'], $item['reason'] ?? 'Unknown reason');
            } else {
                $this->displayError("  ✗ {$item['key']} → Translation failed");
            }
        }

        $transformer->save($targetFile, $targetStrings);
    }

    protected function updateTokenUsage(array $response): void
    {
        if (isset($response['usage'])) {
            $usage = $response['usage'];
            $this->tokenUsage['input_tokens'] += $usage['input_tokens'] ?? 0;
            $this->tokenUsage['output_tokens'] += $usage['output_tokens'] ?? 0;
            $this->tokenUsage['total_tokens'] += $usage['total_tokens'] ?? 0;
            
            $this->displayTokenUsage($usage);
        }
    }

    protected function confirmLargeTranslation(int $count): bool
    {
        if ($this->option('force-big-files')) {
            return true;
        }

        if ($this->option('non-interactive')) {
            $this->error("File has {$count} strings. Use --force-big-files to translate large files.");
            return false;
        }

        return $this->confirm(
            "This file has {$count} strings to translate. This may take a while and cost more. Continue?",
            false
        );
    }

    // Display methods
    protected function displayHeader(): void
    {
        $this->newLine();
        $title = ' Laravel AI Translator ';
        $line = str_repeat('─', 50);
        
        $this->line($this->colors['blue'] . $line . $this->colors['reset']);
        $this->line($this->colors['blue'] . '│' . $this->colors['reset'] . 
                   str_pad($this->colors['bold'] . $title . $this->colors['reset'], 58, ' ', STR_PAD_BOTH) . 
                   $this->colors['blue'] . '│' . $this->colors['reset']);
        $this->line($this->colors['blue'] . $line . $this->colors['reset']);
    }

    protected function displayLocaleHeader(string $locale): void
    {
        $localeName = LanguageConfig::getLanguageName($locale);
        $this->line($this->colors['bg_blue'] . $this->colors['white'] . 
                   " Translating to: {$locale} ({$localeName}) " . 
                   $this->colors['reset']);
    }

    protected function displayFileHeader(string $file, string $locale, string $sourceFile, string $targetFile): void
    {
        $this->newLine();
        $this->line($this->colors['bg_green'] . $this->colors['white'] . ' File Translation ' . $this->colors['reset']);
        $this->line('  File: ' . $this->colors['cyan'] . $file . $this->colors['reset']);
        $this->line('  Language: ' . $this->colors['cyan'] . $locale . $this->colors['reset']);
        $this->line('  Source: ' . $this->colors['dim'] . $sourceFile . $this->colors['reset']);
        $this->line('  Target: ' . $this->colors['dim'] . $targetFile . $this->colors['reset']);
        $this->newLine();
    }

    protected function displayContext(array $context): void
    {
        if (!empty($context['references'])) {
            foreach ($context['references'] as $ref) {
                $this->displayInfo("  ℹ Loading reference: {$ref['locale']} - {$ref['file_count']} files");
            }
        }
    }

    protected function displayChunkProgress(int $current, int $total, int $count): void
    {
        $this->newLine();
        $this->line("  {$this->colors['yellow']}⏺{$this->colors['reset']} Processing chunk {$current}/{$total} ({$count} strings)");
    }

    protected function displayPrompt(array $strings, string $locale, array $context): void
    {
        $this->newLine();
        $this->line($this->colors['bg_blue'] . ' Prompt Preview ' . $this->colors['reset']);
        $this->line("Translating " . count($strings) . " strings to {$locale}");
        $this->line("Context files: {$context['file_count']}, items: {$context['item_count']}");
        $this->newLine();
    }

    protected function displayTranslation(string $key, string $translation): void
    {
        $display = mb_strlen($translation) > 50 
            ? mb_substr($translation, 0, 47) . '...' 
            : $translation;
        $this->line("  {$this->colors['green']}⟳{$this->colors['reset']} {$key} → {$display}");
    }

    protected function displaySkipped(string $key, string $reason): void
    {
        $this->line("  {$this->colors['yellow']}⊘{$this->colors['reset']} {$key} → Skipped: {$reason}");
    }

    protected function displayTokenUsage(array $usage): void
    {
        $this->line($this->colors['dim'] . 
            "    Tokens: Input={$usage['input_tokens']}, Output={$usage['output_tokens']}, Total={$usage['total_tokens']}" . 
            $this->colors['reset']
        );
    }

    protected function displaySuccess(string $message): void
    {
        $this->line($this->colors['green'] . $message . $this->colors['reset']);
    }

    protected function displayInfo(string $message): void
    {
        $this->line($this->colors['cyan'] . $message . $this->colors['reset']);
    }

    protected function displayWarning(string $message): void
    {
        $this->line($this->colors['yellow'] . $message . $this->colors['reset']);
    }

    protected function displayError(string $message): void
    {
        $this->line($this->colors['red'] . $message . $this->colors['reset']);
    }

    protected function displaySummary(): void
    {
        $this->newLine(2);
        $this->line($this->colors['bg_green'] . $this->colors['white'] . ' Translation Summary ' . $this->colors['reset']);
        $this->newLine();
        
        (new TokenUsagePrinter())->print($this->tokenUsage);
        
        $this->newLine();
        $this->displaySuccess('✓ All translations completed successfully!');
        $this->newLine();
    }
}