<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Facades\Config;

class JSONLangTransformer
{
    private array $content = [];

    private bool $useDotNotation;

    public function __construct(
        public string $filePath,
        private string $sourceLanguage = 'en',
    ) {
        $this->useDotNotation = Config::get('ai-translator.dot_notation', false);
        $this->loadContent();
    }

    private function loadContent(): void
    {
        if (file_exists($this->filePath)) {
            $json = file_get_contents($this->filePath);
            $content = json_decode($json, true);
            $this->content = is_array($content) ? $content : [];
        } else {
            $this->content = [];
        }
    }

    public function isTranslated(string $key): bool
    {
        // Don't consider _comment as a translatable key
        if ($key === '_comment') {
            return true;
        }

        $flattened = $this->flatten();

        return array_key_exists($key, $flattened);
    }

    public function flatten(): array
    {
        // Exclude _comment field from flattening as it's metadata
        $contentWithoutComment = array_filter($this->content, function ($key) {
            return $key !== '_comment';
        }, ARRAY_FILTER_USE_KEY);

        return $this->flattenArray($contentWithoutComment);
    }

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
                    if (! isset($current[$part]) || ! is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        return $result;
    }

    public function updateString(string $key, string $translated): void
    {
        if ($this->useDotNotation) {
            $flattened = $this->flattenArray($this->content);
            $flattened[$key] = $translated;
            $this->content = $flattened;
        } else {
            $parts = explode('.', $key);
            $current = &$this->content;

            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $current[$part] = $translated;
                } else {
                    if (! isset($current[$part]) || ! is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        $this->saveToFile();
    }

    private function saveToFile(): void
    {
        $content = $this->useDotNotation ? $this->content : $this->unflattenArray($this->flattenArray($this->content));

        // Add comment at the beginning to indicate this is an auto-generated file
        $currentDate = date('Y-m-d H:i:s T');
        $finalContent = [
            '_comment' => "WARNING: This is an auto-generated file. Do not modify this file manually as your changes will be lost. This file was automatically translated from {$this->sourceLanguage} on {$currentDate}.",
        ];

        // Merge with existing content
        $finalContent = array_merge($finalContent, $content);

        file_put_contents($this->filePath, json_encode($finalContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
