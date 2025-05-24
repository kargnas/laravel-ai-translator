<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\Transformers\PoLangTransformer;

/**
 * Provides translation context for PO files.
 */
class PoTranslationContextProvider
{
    public function getGlobalTranslationContext(
        string $sourceLocale,
        string $targetLocale,
        string $currentFilePath,
        int $maxContextItems = 100
    ): array {
        $langDirectory = config('ai-translator.source_directory');

        $sourceLocaleDir = $this->getLanguageDirectory($langDirectory, $sourceLocale);
        $targetLocaleDir = $this->getLanguageDirectory($langDirectory, $targetLocale);

        if (!is_dir($sourceLocaleDir)) {
            return [];
        }

        $currentFileName = basename($currentFilePath);
        $context = [];
        $totalContextItems = 0;

        $sourceFiles = glob("{$sourceLocaleDir}/*.po");
        if (empty($sourceFiles)) {
            return [];
        }

        usort($sourceFiles, function ($a, $b) use ($currentFileName) {
            $similarityA = similar_text($currentFileName, basename($a));
            $similarityB = similar_text($currentFileName, basename($b));
            return $similarityB <=> $similarityA;
        });

        foreach ($sourceFiles as $sourceFile) {
            if ($totalContextItems >= $maxContextItems) {
                break;
            }

            try {
                $targetFile = $targetLocaleDir . '/' . basename($sourceFile);
                $hasTargetFile = file_exists($targetFile);

                $sourceTransformer = new PoLangTransformer($sourceFile);
                $sourceStrings = $sourceTransformer->flatten();
                if (empty($sourceStrings)) {
                    continue;
                }

                $targetStrings = [];
                if ($hasTargetFile) {
                    $targetTransformer = new PoLangTransformer($targetFile);
                    $targetStrings = $targetTransformer->flatten();
                }

                $maxPerFile = min(20, intval($maxContextItems / count($sourceFiles) / 2) + 1);

                if (count($sourceStrings) > $maxPerFile) {
                    if ($hasTargetFile && !empty($targetStrings)) {
                        $prioritizedItems = $this->getPrioritizedStrings($sourceStrings, $targetStrings, $maxPerFile);
                        $sourceStrings = $prioritizedItems['source'];
                        $targetStrings = $prioritizedItems['target'];
                    } else {
                        $sourceStrings = $this->getPrioritizedSourceOnly($sourceStrings, $maxPerFile);
                    }
                }

                $fileContext = [];
                foreach ($sourceStrings as $key => $sourceValue) {
                    if ($hasTargetFile && !empty($targetStrings)) {
                        $targetValue = $targetStrings[$key] ?? null;
                        if ($targetValue !== null) {
                            $fileContext[$key] = [
                                'source' => $sourceValue,
                                'target' => $targetValue,
                            ];
                        }
                    } else {
                        $fileContext[$key] = [
                            'source' => $sourceValue,
                            'target' => null,
                        ];
                    }
                }

                if (!empty($fileContext)) {
                    $rootKey = pathinfo(basename($sourceFile), PATHINFO_FILENAME);
                    $context[$rootKey] = $fileContext;
                    $totalContextItems += count($fileContext);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $context;
    }

    protected function getLanguageDirectory(string $langDirectory, string $locale): string
    {
        $langDirectory = rtrim($langDirectory, '/');
        if (preg_match('#/[a-z]{2}(_[A-Z]{2})?$#', $langDirectory)) {
            return preg_replace('#/[a-z]{2}(_[A-Z]{2})?$#', "/{$locale}", $langDirectory);
        }
        return "{$langDirectory}/{$locale}";
    }

    protected function getPrioritizedStrings(array $sourceStrings, array $targetStrings, int $maxItems): array
    {
        $prioritizedSource = [];
        $prioritizedTarget = [];
        $commonKeys = array_intersect(array_keys($sourceStrings), array_keys($targetStrings));

        foreach ($commonKeys as $key) {
            if (strlen($sourceStrings[$key]) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $sourceStrings[$key];
                $prioritizedTarget[$key] = $targetStrings[$key];
            }
        }

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

    protected function getPrioritizedSourceOnly(array $sourceStrings, int $maxItems): array
    {
        $prioritizedSource = [];
        foreach ($sourceStrings as $key => $value) {
            if (strlen($value) < 50 && count($prioritizedSource) < $maxItems * 0.7) {
                $prioritizedSource[$key] = $value;
            }
        }
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
