<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Facades\Config;

class JSONLangTransformer implements TransformerInterface
{
    private bool $useDotNotation;
    private string $sourceLanguage;

    public function __construct()
    {
        $this->useDotNotation = Config::get('ai-translator.dot_notation', false);
        $this->sourceLanguage = Config::get('ai-translator.source_locale', 'en');
    }

    /**
     * Parse a JSON language file and return its contents as an array
     */
    public function parse(string $file): array
    {
        if (file_exists($file)) {
            $json = file_get_contents($file);
            $content = json_decode($json, true);
            return is_array($content) ? $content : [];
        }
        return [];
    }

    /**
     * Save the language data to a JSON file
     */
    public function save(string $file, array $data): void
    {
        $content = $this->useDotNotation ? $data : $this->unflattenArray($this->flattenArray($data));

        // Add comment at the beginning to indicate this is an auto-generated file
        $currentDate = date('Y-m-d H:i:s T');
        $finalContent = [
            '_comment' => "WARNING: This is an auto-generated file. Do not modify this file manually as your changes will be lost. This file was automatically translated from {$this->sourceLanguage} on {$currentDate}.",
        ];

        // Remove _comment from content if it exists before merging
        unset($content['_comment']);
        
        // Merge with existing content
        $finalContent = array_merge($finalContent, $content);

        // Ensure directory exists
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, json_encode($finalContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Flatten a nested array into dot notation
     */
    public function flatten(array $array, string $prefix = ''): array
    {
        // Exclude _comment field from flattening as it's metadata
        $contentWithoutComment = array_filter($array, function ($key) {
            return $key !== '_comment';
        }, ARRAY_FILTER_USE_KEY);

        return $this->flattenArray($contentWithoutComment, $prefix);
    }

    /**
     * Unflatten a dot notation key-value pair back into a nested array
     */
    public function unflatten(array $array, string $key, string $value): array
    {
        if ($this->useDotNotation) {
            $flattened = $this->flattenArray($array);
            $flattened[$key] = $value;
            return $flattened;
        }

        $parts = explode('.', $key);
        $current = &$array;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        return $array;
    }

    /**
     * Check if a key is already translated
     */
    public function isTranslated(array $content, string $key): bool
    {
        // Don't consider _comment as a translatable key
        if ($key === '_comment') {
            return true;
        }

        $flattened = $this->flatten($content);
        return array_key_exists($key, $flattened) && !empty(trim($flattened[$key]));
    }

    /**
     * Flatten array implementation
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result += $this->flattenArray($value, $newKey);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Unflatten array implementation
     */
    private function unflattenArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $parts = explode('.', $key);
            $current = &$result;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $current[$part] = $value;
                } else {
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        return $result;
    }
}