<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

class GeneratePromptCommand extends Command
{
    protected $signature = 'ai-translator:generate-prompt
                          {--source=en : Source language code}
                          {--output-dir=prompts : Directory to save generated prompts}
                          {--frontend-dirs=resources/views,resources/js,resources/ts,public/js,src : Comma-separated frontend directories to scan}
                          {--lang-dir= : Language directory (defaults to config)}';

    protected $description = 'Generate initial prompts for global and each language by scanning frontend files and language files';

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
    ];

    protected string $sourceLocale;
    protected string $languageDirectory;
    protected string $outputDirectory;
    protected array $frontendDirectories = [];
    protected array $scannedData = [];

    public function handle(): int
    {
        $this->initializeConfiguration();
        $this->displayHeader();

        // Scan frontend files for context
        $this->scanFrontendFiles();
        
        // Scan language files
        $this->scanLanguageFiles();
        
        // Generate prompts
        $this->generateGlobalPrompt();
        $this->generateLanguageSpecificPrompts();

        $this->displaySuccess();
        return self::SUCCESS;
    }

    protected function initializeConfiguration(): void
    {
        $this->sourceLocale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
        $this->languageDirectory = $this->option('lang-dir') ?? config('ai-translator.source_directory', 'lang');
        $this->outputDirectory = $this->option('output-dir');
        
        $frontendDirsOption = $this->option('frontend-dirs');
        $this->frontendDirectories = array_filter(array_map('trim', explode(',', $frontendDirsOption)));
        
        // Create output directory if it doesn't exist
        if (!File::exists($this->outputDirectory)) {
            File::makeDirectory($this->outputDirectory, 0755, true);
        }
    }

    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line($this->colors['blue'] . str_repeat('─', 60) . $this->colors['reset']);
        $this->line($this->colors['blue'] . '│' . $this->colors['reset'] . 
                   str_pad($this->colors['bold'] . ' Laravel AI Translator - Prompt Generator ' . $this->colors['reset'], 68, ' ', STR_PAD_BOTH) . 
                   $this->colors['blue'] . '│' . $this->colors['reset']);
        $this->line($this->colors['blue'] . str_repeat('─', 60) . $this->colors['reset']);
        $this->newLine();
    }

    protected function scanFrontendFiles(): void
    {
        $this->info("🔍 Scanning frontend files...");
        
        $frontendPatterns = [];
        $techStack = [];
        $uiComponents = [];
        $businessTerms = [];

        foreach ($this->frontendDirectories as $directory) {
            if (!File::exists($directory)) {
                continue;
            }

            $files = $this->getFilesRecursively($directory, ['php', 'blade.php', 'js', 'ts', 'vue', 'jsx', 'tsx']);
            
            foreach ($files as $file) {
                $content = File::get($file);
                
                // Detect technology stack
                $this->detectTechnologyStack($content, $techStack);
                
                // Extract UI components and patterns
                $this->extractUIPatterns($content, $uiComponents);
                
                // Extract business-specific terms
                $this->extractBusinessTerms($content, $businessTerms);
            }
        }

        $this->scannedData['frontend'] = [
            'tech_stack' => array_unique($techStack),
            'ui_components' => array_unique($uiComponents),
            'business_terms' => array_unique($businessTerms),
            'file_count' => count($files ?? []),
        ];

        $this->line("  ✓ Scanned {$this->scannedData['frontend']['file_count']} frontend files");
        $this->line("  ✓ Detected " . count($this->scannedData['frontend']['tech_stack']) . " technologies");
        $this->line("  ✓ Found " . count($this->scannedData['frontend']['ui_components']) . " UI patterns");
        $this->line("  ✓ Extracted " . count($this->scannedData['frontend']['business_terms']) . " business terms");
    }

    protected function scanLanguageFiles(): void
    {
        $this->info("📚 Scanning language files...");
        
        $this->scannedData['languages'] = [];
        
        // Get available locales
        $locales = $this->getAvailableLocales();
        
        foreach ($locales as $locale) {
            $languageData = $this->scanLocaleFiles($locale);
            if (!empty($languageData)) {
                $this->scannedData['languages'][$locale] = $languageData;
                $this->line("  ✓ {$locale}: " . count($languageData['keys']) . " translation keys");
            }
        }
    }

    protected function scanLocaleFiles(string $locale): array
    {
        $phpTransformer = new PHPLangTransformer();
        $jsonTransformer = new JSONLangTransformer();
        
        $data = [
            'keys' => [],
            'categories' => [],
            'patterns' => [],
            'file_count' => 0,
        ];
        
        // Scan PHP language files
        $phpFiles = glob("{$this->languageDirectory}/{$locale}/*.php");
        foreach ($phpFiles ?? [] as $file) {
            if (File::exists($file)) {
                $strings = $phpTransformer->parse($file);
                $flatStrings = $phpTransformer->flatten($strings);
                
                $data['keys'] = array_merge($data['keys'], array_keys($flatStrings));
                $data['categories'][] = basename($file, '.php');
                $data['file_count']++;
                
                // Analyze patterns in translations
                $this->analyzeTranslationPatterns(array_values($flatStrings), $data['patterns']);
            }
        }
        
        // Scan JSON language files
        $jsonFile = "{$this->languageDirectory}/{$locale}.json";
        if (File::exists($jsonFile)) {
            $strings = $jsonTransformer->parse($jsonFile);
            $flatStrings = $jsonTransformer->flatten($strings);
            
            $data['keys'] = array_merge($data['keys'], array_keys($flatStrings));
            $data['file_count']++;
            
            $this->analyzeTranslationPatterns(array_values($flatStrings), $data['patterns']);
        }
        
        $data['keys'] = array_unique($data['keys']);
        $data['categories'] = array_unique($data['categories']);
        
        return $data;
    }

    protected function generateGlobalPrompt(): void
    {
        $this->info("📝 Generating global prompt...");
        
        $globalPrompt = $this->buildGlobalPrompt();
        
        $outputFile = "{$this->outputDirectory}/global-prompt.md";
        File::put($outputFile, $globalPrompt);
        
        $this->line("  ✓ Global prompt saved to: {$outputFile}");
    }

    protected function generateLanguageSpecificPrompts(): void
    {
        $this->info("🌍 Generating language-specific prompts...");
        
        foreach ($this->scannedData['languages'] as $locale => $languageData) {
            if ($locale === $this->sourceLocale) {
                continue;
            }
            
            $languagePrompt = $this->buildLanguageSpecificPrompt($locale, $languageData);
            
            $outputFile = "{$this->outputDirectory}/{$locale}-prompt.md";
            File::put($outputFile, $languagePrompt);
            
            $this->line("  ✓ {$locale} prompt saved to: {$outputFile}");
        }
    }

    protected function buildGlobalPrompt(): string
    {
        $techStack = implode(', ', $this->scannedData['frontend']['tech_stack']);
        $totalKeys = array_sum(array_column($this->scannedData['languages'], function($data) {
            return count($data['keys']);
        }));
        
        $businessTerms = implode(', ', array_slice($this->scannedData['frontend']['business_terms'], 0, 20));
        
        return <<<PROMPT
# Global Translation Context

This document provides context for AI-powered translation of this application.

## Application Overview

### Technology Stack
{$techStack}

### Translation Scope
- Total translation keys across all languages: {$totalKeys}
- Source language: {$this->sourceLocale}
- Available target languages: ` . implode(', ', array_keys($this->scannedData['languages'])) . `

### Business Domain
Key business terms identified: {$businessTerms}

## Translation Guidelines

### General Principles
1. Maintain consistency across all translations
2. Preserve variables (e.g., :name, {variable}) exactly as they appear
3. Keep HTML tags and formatting intact
4. Consider cultural context and local conventions
5. Use appropriate formality level for the target audience

### UI Component Patterns
` . implode("\n", array_map(fn($pattern) => "- {$pattern}", $this->scannedData['frontend']['ui_components'])) . `

### Common Translation Categories
` . $this->getCommonCategories() . `

## Context for AI Translation

When translating strings for this application:
1. Consider the technical context (web application using {$techStack})
2. Maintain consistency with established terminology
3. Pay attention to UI/UX implications of translation length
4. Consider the business domain and industry-specific terms
5. Preserve all placeholders and variables

PROMPT;
    }

    protected function buildLanguageSpecificPrompt(string $locale, array $languageData): string
    {
        $languageName = LanguageConfig::getLanguageName($locale);
        $categories = implode(', ', $languageData['categories']);
        $keyCount = count($languageData['keys']);
        
        // Get language-specific rules
        $languageRules = $this->getLanguageSpecificRules($locale);
        
        return <<<PROMPT
# {$languageName} ({$locale}) Translation Prompt

## Target Language Information
- **Language**: {$languageName}
- **Code**: {$locale}
- **Total Keys**: {$keyCount}
- **Categories**: {$categories}

## Language-Specific Guidelines

{$languageRules}

## Translation Patterns Observed
` . implode("\n", array_map(fn($pattern) => "- {$pattern}", $languageData['patterns'])) . `

## Context for AI Translation

When translating to {$languageName}:
1. Follow {$languageName} grammar and syntax rules
2. Use culturally appropriate expressions
3. Consider regional variations if applicable
4. Maintain professional tone suitable for web applications
5. Preserve technical terminology where appropriate

## Sample Translation Keys
` . $this->getSampleKeys($languageData['keys'], 10) . `

PROMPT;
    }

    protected function getFilesRecursively(string $directory, array $extensions): array
    {
        $files = [];
        
        if (!File::exists($directory)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = $file->getExtension();
                if (in_array($extension, $extensions) || 
                    (str_ends_with($file->getFilename(), '.blade.php'))) {
                    $files[] = $file->getPathname();
                }
            }
        }
        
        return $files;
    }

    protected function detectTechnologyStack(string $content, array &$techStack): void
    {
        $patterns = [
            'Laravel' => ['@extends', '@section', '@yield', 'Route::', 'Blade'],
            'Vue.js' => ['Vue.', 'v-if', 'v-for', '@click', 'Vue.component'],
            'React' => ['React.', 'useState', 'useEffect', 'JSX', 'className'],
            'jQuery' => ['$(', 'jQuery', '.ready(', '.click('],
            'Bootstrap' => ['bootstrap', 'btn btn-', 'container', 'row'],
            'Tailwind' => ['tailwind', 'bg-', 'text-', 'flex', 'grid'],
            'Alpine.js' => ['x-data', 'x-show', 'x-if', '@click.prevent'],
        ];
        
        foreach ($patterns as $tech => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    $techStack[] = $tech;
                    break;
                }
            }
        }
    }

    protected function extractUIPatterns(string $content, array &$uiComponents): void
    {
        // Extract common UI patterns
        $patterns = [
            'Forms' => ['<form', 'input', 'textarea', 'select', 'button[type="submit"]'],
            'Navigation' => ['<nav', 'navbar', 'menu', 'breadcrumb'],
            'Modals' => ['modal', 'popup', 'dialog'],
            'Tables' => ['<table', 'thead', 'tbody', 'tr', 'td'],
            'Cards' => ['card', 'panel', 'widget'],
            'Alerts' => ['alert', 'notification', 'flash', 'success', 'error'],
        ];
        
        foreach ($patterns as $component => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    $uiComponents[] = $component;
                    break;
                }
            }
        }
    }

    protected function extractBusinessTerms(string $content, array &$businessTerms): void
    {
        // Extract potential business domain terms using regex
        preg_match_all('/\b[A-Z][a-z]+(?:[A-Z][a-z]+)*\b/', $content, $matches);
        
        $potentialTerms = array_unique($matches[0]);
        
        // Filter out common tech terms
        $excludeTerms = ['String', 'Array', 'Object', 'Function', 'Class', 'Method', 'Property', 'Component', 'Service'];
        
        foreach ($potentialTerms as $term) {
            if (!in_array($term, $excludeTerms) && strlen($term) > 3) {
                $businessTerms[] = $term;
            }
        }
    }

    protected function analyzeTranslationPatterns(array $translations, array &$patterns): void
    {
        foreach ($translations as $translation) {
            // Check for placeholders
            if (preg_match('/:(\w+)/', $translation)) {
                $patterns[] = 'Uses Laravel-style placeholders (:variable)';
            }
            
            if (preg_match('/\{(\w+)\}/', $translation)) {
                $patterns[] = 'Uses curly brace placeholders ({variable})';
            }
            
            // Check for HTML content
            if (strip_tags($translation) !== $translation) {
                $patterns[] = 'Contains HTML markup';
            }
            
            // Check for punctuation patterns
            if (str_ends_with($translation, '.')) {
                $patterns[] = 'Sentences ending with periods';
            }
            
            if (str_ends_with($translation, '!')) {
                $patterns[] = 'Exclamatory sentences';
            }
            
            if (str_ends_with($translation, '?')) {
                $patterns[] = 'Questions';
            }
        }
        
        // Remove duplicates
        $patterns = array_unique($patterns);
    }

    protected function getAvailableLocales(): array
    {
        $locales = [$this->sourceLocale];
        
        // Get directories
        $directories = glob("{$this->languageDirectory}/*", GLOB_ONLYDIR);
        foreach ($directories as $dir) {
            $locale = basename($dir);
            if (!in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }
        
        // Get JSON files
        $jsonFiles = glob("{$this->languageDirectory}/*.json");
        foreach ($jsonFiles as $file) {
            $locale = basename($file, '.json');
            if (!in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }
        
        return $locales;
    }

    protected function getLanguageSpecificRules(string $locale): string
    {
        // This would ideally come from LanguageRules class, but for now provide basic rules
        $rules = [
            'ko' => "- Use appropriate honorifics and formality levels\n- Maintain Korean sentence structure\n- Use proper Korean punctuation",
            'ja' => "- Use appropriate keigo (honorific language)\n- Consider hiragana vs katakana usage\n- Maintain Japanese sentence structure",
            'zh' => "- Use simplified or traditional characters as appropriate\n- Consider cultural context for expressions\n- Maintain proper Chinese grammar",
            'de' => "- Use proper German capitalization rules\n- Consider formal vs informal address\n- Handle compound words appropriately",
            'fr' => "- Use proper French grammar and gender agreements\n- Consider formal vs informal address\n- Handle accents and special characters",
            'es' => "- Use proper Spanish grammar and gender agreements\n- Consider regional variations\n- Use appropriate accents",
            'pt' => "- Distinguish between Brazilian and European Portuguese if needed\n- Use proper Portuguese grammar\n- Handle accents correctly",
            'ru' => "- Use proper Cyrillic script\n- Consider case declensions\n- Handle formal vs informal address",
        ];
        
        return $rules[$locale] ?? "- Follow standard grammar and cultural conventions for {$locale}\n- Maintain appropriate formality level\n- Consider regional variations if applicable";
    }

    protected function getCommonCategories(): string
    {
        $allCategories = [];
        
        foreach ($this->scannedData['languages'] as $languageData) {
            $allCategories = array_merge($allCategories, $languageData['categories']);
        }
        
        $commonCategories = array_unique($allCategories);
        
        return implode("\n", array_map(fn($cat) => "- {$cat}", $commonCategories));
    }

    protected function getSampleKeys(array $keys, int $limit): string
    {
        $sampleKeys = array_slice($keys, 0, $limit);
        return implode("\n", array_map(fn($key) => "- {$key}", $sampleKeys));
    }

    protected function displaySuccess(): void
    {
        $this->newLine();
        $this->line($this->colors['green'] . '✓ Prompt generation completed successfully!' . $this->colors['reset']);
        $this->line($this->colors['cyan'] . "Generated prompts saved to: {$this->outputDirectory}/" . $this->colors['reset']);
        $this->newLine();
    }
}