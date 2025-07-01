<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\Transformers\TransformerInterface;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

/**
 * Purpose: Provides global translation context for improving translation consistency across files
 * Objectives:
 * - Collect existing translations from already translated files
 * - Include both source and target language strings for context
 * - Prioritize most relevant translations for context
 * - Manage context size to prevent context window overflows
 */
class TranslationContextProvider
{
    protected string $langDirectory;
    protected TransformerInterface $transformer;

    public function __construct(string $langDirectory, TransformerInterface $transformer)
    {
        $this->langDirectory = rtrim($langDirectory, '/');
        $this->transformer = $transformer;
    }

    /**
     * Get global translation context with reference locales
     */
    public function getGlobalTranslationContext(
        string $targetLocale,
        array $referenceLocales = [],
        int $maxContextItems = 1000
    ): array {
        $sourceLocale = config('ai-translator.source_locale', 'en');
        
        $context = [
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'references' => [],
            'items' => [],
            'file_count' => 0,
            'item_count' => 0,
        ];

        // Load reference translations
        foreach ($referenceLocales as $refLocale) {
            $refContext = $this->loadLocaleContext($sourceLocale, $refLocale, $maxContextItems);
            if (!empty($refContext)) {
                $context['references'][] = [
                    'locale' => $refLocale,
                    'file_count' => $refContext['file_count'],
                    'item_count' => $refContext['item_count'],
                ];
                $context['items'] = array_merge($context['items'], $refContext['items']);
                $context['file_count'] += $refContext['file_count'];
                $context['item_count'] += $refContext['item_count'];
            }
        }

        // Trim to max context items
        if ($context['item_count'] > $maxContextItems) {
            $context['items'] = array_slice($context['items'], 0, $maxContextItems, true);
            $context['item_count'] = count($context['items']);
        }

        return $context;
    }

    /**
     * Load context for a specific locale
     */
    protected function loadLocaleContext(string $sourceLocale, string $targetLocale, int $maxItems): array
    {
        $context = [
            'items' => [],
            'file_count' => 0,
            'item_count' => 0,
        ];

        // Check both PHP and JSON files
        $phpFiles = $this->getPhpFiles($targetLocale);
        $jsonFiles = $this->getJsonFiles($targetLocale);
        
        $allFiles = array_merge($phpFiles, $jsonFiles);
        
        foreach ($allFiles as $fileInfo) {
            if ($context['item_count'] >= $maxItems) {
                break;
            }

            $sourceFile = $fileInfo['source'];
            $targetFile = $fileInfo['target'];
            
            if (!file_exists($sourceFile) || !file_exists($targetFile)) {
                continue;
            }

            try {
                // Determine the transformer based on file type
                $transformer = str_ends_with($sourceFile, '.json') 
                    ? new JSONLangTransformer($sourceFile)
                    : new PHPLangTransformer($sourceFile);
                
                $sourceStrings = $transformer->parse($sourceFile);
                $targetStrings = $transformer->parse($targetFile);
                
                $sourceFlatten = $transformer->flatten($sourceStrings);
                $targetFlatten = $transformer->flatten($targetStrings);
                
                // Get prioritized strings
                $prioritized = $this->getPrioritizedReferenceStrings($sourceFlatten, $targetFlatten, 
                    min(50, $maxItems - $context['item_count']));
                
                if (!empty($prioritized)) {
                    $fileName = basename($sourceFile, '.php');
                    $fileName = basename($fileName, '.json');
                    
                    foreach ($prioritized as $key => $item) {
                        $context['items']["{$fileName}.{$key}"] = $item;
                        $context['item_count']++;
                    }
                    $context['file_count']++;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $context;
    }

    /**
     * Get PHP files for a locale
     */
    protected function getPhpFiles(string $locale): array
    {
        $sourceDir = "{$this->langDirectory}/{$locale}";
        if (!is_dir($sourceDir)) {
            return [];
        }

        $files = glob("{$sourceDir}/*.php");
        $sourceLocale = config('ai-translator.source_locale', 'en');
        
        return array_map(function ($file) use ($sourceLocale, $locale) {
            $filename = basename($file);
            return [
                'source' => "{$this->langDirectory}/{$sourceLocale}/{$filename}",
                'target' => "{$this->langDirectory}/{$locale}/{$filename}",
            ];
        }, $files);
    }

    /**
     * Get JSON files for a locale
     */
    protected function getJsonFiles(string $locale): array
    {
        $sourceLocale = config('ai-translator.source_locale', 'en');
        $sourceFile = "{$this->langDirectory}/{$sourceLocale}.json";
        $targetFile = "{$this->langDirectory}/{$locale}.json";
        
        if (!file_exists($sourceFile)) {
            return [];
        }

        return [[
            'source' => $sourceFile,
            'target' => $targetFile,
        ]];
    }

    /**
     * Get prioritized reference strings for context
     */
    protected function getPrioritizedReferenceStrings(array $sourceStrings, array $targetStrings, int $maxItems): array
    {
        $result = [];
        $commonKeys = array_intersect_key($sourceStrings, $targetStrings);
        
        if (empty($commonKeys)) {
            return $result;
        }

        // Sort by string length (shorter strings are often more common UI elements)
        uasort($commonKeys, function ($a, $b) use ($sourceStrings) {
            return strlen($sourceStrings[$a]) <=> strlen($sourceStrings[$b]);
        });

        $count = 0;
        foreach ($commonKeys as $key => $value) {
            if ($count >= $maxItems) {
                break;
            }
            
            if (!empty($targetStrings[$key])) {
                $result[$key] = [
                    'source' => $sourceStrings[$key],
                    'target' => $targetStrings[$key],
                ];
                $count++;
            }
        }

        return $result;
    }
}