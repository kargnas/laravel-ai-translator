<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Plugins\AbstractMiddlewarePlugin;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

/**
 * TranslationContextPlugin - Provides global translation context for consistency
 * 
 * This plugin replaces the legacy TranslationContextProvider by collecting
 * existing translations from already translated files to improve consistency
 * across the entire application.
 * 
 * Responsibilities:
 * - Collect existing translations from translated files
 * - Include both source and target language strings for context
 * - Prioritize most relevant translations for context
 * - Manage context size to prevent context window overflows
 */
class TranslationContextPlugin extends AbstractMiddlewarePlugin
{
    protected string $name = 'translation_context_plugin';

    protected int $defaultMaxContextItems = 100;
    protected int $maxPerFile = 20;

    /**
     * Get the pipeline stage where this plugin should run
     */
    protected function getStage(): string
    {
        return 'preparation';
    }

    /**
     * Handle the translation context
     */
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        $request = $context->getRequest();
        $maxContextItems = $request->getOption('max_context_items', $this->defaultMaxContextItems);
        
        $globalContext = $this->getGlobalTranslationContext(
            $request->getSourceLanguage(),
            $request->getTargetLanguage(),
            $request->getMetadata('current_file_path', ''),
            $maxContextItems
        );
        
        $context->setPluginData('global_translation_context', $globalContext);
        $context->setPluginData('context_provider', $this);
        
        return $next($context);
    }

    /**
     * Get global translation context for improving consistency
     *
     * @param  string  $sourceLocale  Source language locale code
     * @param  string  $targetLocale  Target language locale code
     * @param  string  $currentFilePath  Current file being translated
     * @param  int  $maxContextItems  Maximum number of context items to include
     * @return array Context data organized by file with both source and target strings
     */
    public function getGlobalTranslationContext(
        string $sourceLocale,
        string $targetLocale,
        string $currentFilePath,
        int $maxContextItems = 100
    ): array {
        // Base directory path for language files
        $langDirectory = config('ai-translator.source_directory');

        // Configure source and target language directory paths
        $sourceLocaleDir = $this->getLanguageDirectory($langDirectory, $sourceLocale);
        $targetLocaleDir = $this->getLanguageDirectory($langDirectory, $targetLocale);

        // Return empty array if source directory doesn't exist
        if (!is_dir($sourceLocaleDir)) {
            return [];
        }

        $currentFileName = basename($currentFilePath);
        $context = [];
        $totalContextItems = 0;
        $processedFiles = 0;

        // Get all PHP files from source directory
        $sourceFiles = glob("{$sourceLocaleDir}/*.php");

        // Return empty array if no files exist
        if (empty($sourceFiles)) {
            return [];
        }

        // Process similar named files first to improve context relevance
        usort($sourceFiles, function ($a, $b) use ($currentFileName) {
            $similarityA = similar_text($currentFileName, basename($a));
            $similarityB = similar_text($currentFileName, basename($b));

            return $similarityB <=> $similarityA;
        });

        foreach ($sourceFiles as $sourceFile) {
            // Stop if maximum context items are reached
            if ($totalContextItems >= $maxContextItems) {
                break;
            }

            try {
                // Confirm target file path
                $targetFile = $targetLocaleDir.'/'.basename($sourceFile);
                $hasTargetFile = file_exists($targetFile);

                // Get original strings from source file
                $sourceTransformer = new PHPLangTransformer($sourceFile);
                $sourceStrings = $sourceTransformer->flatten();

                // Skip empty files
                if (empty($sourceStrings)) {
                    continue;
                }

                // Get target strings if target file exists
                $targetStrings = [];
                if ($hasTargetFile) {
                    $targetTransformer = new PHPLangTransformer($targetFile);
                    $targetStrings = $targetTransformer->flatten();
                }

                // Limit maximum items per file
                $maxPerFile = min($this->maxPerFile, intval($maxContextItems / count($sourceFiles) / 2) + 1);

                // Prioritize high-priority items from longer files
                if (count($sourceStrings) > $maxPerFile) {
                    if ($hasTargetFile && !empty($targetStrings)) {
                        // If target exists, apply both source and target prioritization
                        $prioritizedItems = $this->getPrioritizedStrings($sourceStrings, $targetStrings, $maxPerFile);
                        $sourceStrings = $prioritizedItems['source'];
                        $targetStrings = $prioritizedItems['target'];
                    } else {
                        // If target doesn't exist, apply source prioritization only
                        $sourceStrings = $this->getPrioritizedSourceOnly($sourceStrings, $maxPerFile);
                    }
                }

                // Construct translation context - include both source and target strings
                $fileContext = [];
                foreach ($sourceStrings as $key => $sourceValue) {
                    if ($hasTargetFile && !empty($targetStrings)) {
                        // If target file exists, include both source and target
                        $targetValue = $targetStrings[$key] ?? null;
                        if ($targetValue !== null) {
                            $fileContext[$key] = [
                                'source' => $sourceValue,
                                'target' => $targetValue,
                            ];
                        }
                    } else {
                        // If target file doesn't exist, include source only
                        $fileContext[$key] = [
                            'source' => $sourceValue,
                            'target' => null,
                        ];
                    }
                }

                if (!empty($fileContext)) {
                    // Remove extension from filename and save as root key
                    $rootKey = pathinfo(basename($sourceFile), PATHINFO_FILENAME);
                    $context[$rootKey] = $fileContext;
                    $totalContextItems += count($fileContext);
                    $processedFiles++;
                }
            } catch (\Exception $e) {
                // Skip problematic files
                continue;
            }
        }

        return $context;
    }

    /**
     * Determines the directory path for a specified language.
     *
     * @param  string  $langDirectory  Base directory path for language files
     * @param  string  $locale  Language locale code
     * @return string Language-specific directory path
     */
    protected function getLanguageDirectory(string $langDirectory, string $locale): string
    {
        // Remove trailing slash if exists
        $langDirectory = rtrim($langDirectory, '/');

        // 1. If /locale pattern is already included (e.g. /lang/en)
        if (preg_match('#/[a-z]{2}(_[A-Z]{2})?$#', $langDirectory)) {
            return preg_replace('#/[a-z]{2}(_[A-Z]{2})?$#', "/{$locale}", $langDirectory);
        }

        // 2. Add language code to base path
        return "{$langDirectory}/{$locale}";
    }

    /**
     * Selects high-priority items from source and target strings.
     *
     * @param  array  $sourceStrings  Source string array
     * @param  array  $targetStrings  Target string array
     * @param  int  $maxItems  Maximum number of items
     * @return array High-priority source and target strings
     */
    protected function getPrioritizedStrings(array $sourceStrings, array $targetStrings, int $maxItems): array
    {
        $prioritizedSource = [];
        $prioritizedTarget = [];
        $commonKeys = array_intersect(array_keys($sourceStrings), array_keys($targetStrings));

        // 1. Short strings first (UI elements, buttons, etc.)
        foreach ($commonKeys as $key) {
            if (strlen($sourceStrings[$key]) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $sourceStrings[$key];
                $prioritizedTarget[$key] = $targetStrings[$key];
            }
        }

        // 2. Add remaining items
        foreach ($commonKeys as $key) {
            if (!isset($prioritizedSource[$key]) && count($prioritizedSource) < $maxItems) {
                $prioritizedSource[$key] = $sourceStrings[$key];
                $prioritizedTarget[$key] = $targetStrings[$key];
            }

            if (count($prioritizedSource) >= $maxItems) {
                break;
            }
        }

        return [
            'source' => $prioritizedSource,
            'target' => $prioritizedTarget,
        ];
    }

    /**
     * Selects high-priority items from source strings only.
     */
    protected function getPrioritizedSourceOnly(array $sourceStrings, int $maxItems): array
    {
        $prioritizedSource = [];

        // 1. Short strings first (UI elements, buttons, etc.)
        foreach ($sourceStrings as $key => $value) {
            if (strlen($value) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $value;
            }
        }

        // 2. Add remaining items
        foreach ($sourceStrings as $key => $value) {
            if (!isset($prioritizedSource[$key]) && count($prioritizedSource) < $maxItems) {
                $prioritizedSource[$key] = $value;
            }

            if (count($prioritizedSource) >= $maxItems) {
                break;
            }
        }

        return $prioritizedSource;
    }
}