<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Purpose: Provides global translation context for JSON files with nested directory structure
 * Objectives:
 * - Collect existing translations from already translated JSON files
 * - Support recursive directory traversal for nested JSON structures
 * - Include both source and target language strings for context
 * - Prioritize most relevant translations for context
 * - Manage context size to prevent context window overflows
 */
class JSONTranslationContextProvider
{
    /**
     * Constants for magic numbers
     */
    protected const MAX_LINE_BREAKS = 5;
    protected const SHORT_STRING_LENGTH = 50;
    protected const PRIORITY_RATIO = 0.7;

    /**
     * Get global translation context for improving consistency
     *
     * @param  string  $sourceLocale  Source language locale code
     * @param  string  $targetLocale  Target language locale code
     * @param  string  $currentFilePath  Current file being translated
     * @param  int  $maxContextItems  Maximum number of context items to include (to prevent context overflow)
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
        if (! is_dir($sourceLocaleDir)) {
            return [];
        }

        $currentFileName = basename($currentFilePath);
        $context = [];
        $totalContextItems = 0;
        $processedFiles = 0;

        // Get all JSON files from source directory recursively
        $sourceFiles = $this->getAllJsonFiles($sourceLocaleDir);

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
                // Calculate relative path to maintain directory structure
                $relativePath = str_replace($sourceLocaleDir . '/', '', $sourceFile);
                $targetFile = $targetLocaleDir . '/' . $relativePath;
                $hasTargetFile = file_exists($targetFile);

                // Get original strings from source file
                $sourceTransformer = new JSONLangTransformer($sourceFile);
                $sourceStrings = $sourceTransformer->flatten();

                // Skip empty files
                if (empty($sourceStrings)) {
                    continue;
                }

                // Get target strings if target file exists
                $targetStrings = [];
                if ($hasTargetFile) {
                    $targetTransformer = new JSONLangTransformer($targetFile);
                    $targetStrings = $targetTransformer->flatten();
                }

                // Limit maximum items per file
                $maxPerFile = min(20, intval($maxContextItems / max(count($sourceFiles) / 2, 1)) + 1);

                // Prioritize high-priority items from longer files
                if (count($sourceStrings) > $maxPerFile) {
                    if ($hasTargetFile && ! empty($targetStrings)) {
                        // If target exists, apply both source and target prioritization
                        $prioritizedItems = $this->getPrioritizedStrings($sourceStrings, $targetStrings, $maxPerFile);
                        $sourceStrings = $prioritizedItems['source'];
                        $targetStrings = $prioritizedItems['target'];
                    } else {
                        // If no target exists, prioritize source items only
                        $sourceStrings = $this->getPrioritizedSourceStrings($sourceStrings, $maxPerFile);
                    }
                }

                // Add file context
                $context[$relativePath] = [
                    'source' => $sourceStrings,
                    'target' => $targetStrings,
                ];

                $totalContextItems += count($sourceStrings);
                $processedFiles++;
            } catch (\Exception $e) {
                // Silently skip files that cannot be processed
                continue;
            }
        }

        return $context;
    }

    /**
     * Get all JSON files recursively from a directory
     */
    protected function getAllJsonFiles(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
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
     * Get prioritized strings based on importance
     *
     * @param  array  $sourceStrings  Source language strings
     * @param  array  $targetStrings  Target language strings  
     * @param  int  $maxItems  Maximum number of items to return
     * @return array Prioritized source and target strings
     */
    protected function getPrioritizedStrings(array $sourceStrings, array $targetStrings, int $maxItems): array
    {
        $prioritizedSource = [];
        $prioritizedTarget = [];

        // Priority 1: Short strings (UI elements, buttons, etc.)
        // Exclude very long texts with many line breaks
        foreach ($sourceStrings as $key => $value) {
            // Skip very long texts (5+ line breaks)
            if ($this->isVeryLongText($value)) {
                continue;
            }
            
            if (strlen($value) < self::SHORT_STRING_LENGTH && count($prioritizedSource) < $maxItems * self::PRIORITY_RATIO) {
                $prioritizedSource[$key] = $value;
                if (isset($targetStrings[$key])) {
                    $prioritizedTarget[$key] = $targetStrings[$key];
                }
            }
        }

        // Priority 2: Add remaining items (excluding very long texts)
        foreach ($sourceStrings as $key => $value) {
            // Skip very long texts (5+ line breaks)
            if ($this->isVeryLongText($value)) {
                continue;
            }
            
            if (! isset($prioritizedSource[$key]) && count($prioritizedSource) < $maxItems) {
                $prioritizedSource[$key] = $value;
                if (isset($targetStrings[$key])) {
                    $prioritizedTarget[$key] = $targetStrings[$key];
                }
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
     * Get prioritized source strings
     *
     * @param  array  $sourceStrings  Source language strings
     * @param  int  $maxItems  Maximum number of items to return
     * @return array Prioritized source strings
     */
    protected function getPrioritizedSourceStrings(array $sourceStrings, int $maxItems): array
    {
        $prioritized = [];

        // Priority 1: Short strings (UI elements, buttons, etc.)
        // Exclude very long texts with many line breaks
        foreach ($sourceStrings as $key => $value) {
            // Skip very long texts (5+ line breaks)
            if ($this->isVeryLongText($value)) {
                continue;
            }
            
            if (strlen($value) < self::SHORT_STRING_LENGTH && count($prioritized) < $maxItems * self::PRIORITY_RATIO) {
                $prioritized[$key] = $value;
            }
        }

        // Priority 2: Add remaining items (excluding very long texts)
        foreach ($sourceStrings as $key => $value) {
            // Skip very long texts (5+ line breaks)
            if ($this->isVeryLongText($value)) {
                continue;
            }
            
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
     * Get language directory path
     *
     * @param  string  $baseDirectory  Base language directory
     * @param  string  $locale  Locale code
     * @return string Full directory path
     */
    protected function getLanguageDirectory(string $baseDirectory, string $locale): string
    {
        return $baseDirectory . '/' . $locale;
    }

    /**
     * Check if text is very long (has too many line breaks)
     * 
     * @param string $text The text to check
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