<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Facades\Config;

class PHPLangTransformer
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
            $content = require $this->filePath;
            // Handle empty files or files without return statement
            $this->content = is_array($content) ? $content : [];
        } else {
            $this->content = [];
        }
    }

    public function isTranslated(string $key): bool
    {
        $flattened = $this->flattenArray($this->content);

        return array_key_exists($key, $flattened);
    }

    public function flatten(): array
    {
        return $this->flattenArray($this->content);
    }

    /**
     * Get translatable strings from the file
     * This is an alias for flatten() to maintain backward compatibility
     */
    public function getTranslatable(): array
    {
        return $this->flatten();
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
        $timestamp = date('Y-m-d H:i:s T');
        $content = $this->useDotNotation ? $this->content : $this->unflattenArray($this->flattenArray($this->content));

        $lines = [
            "<?php\n",
            '/**',
            ' * WARNING: This is an auto-generated file.',
            ' * Do not modify this file manually as your changes will be lost.',
            " * This file was automatically translated from {$this->sourceLanguage} at {$timestamp}.",
            " */\n",
            'return '.$this->arrayExport($content, 0).";\n",
        ];

        file_put_contents($this->filePath, implode("\n", $lines));
    }

    private function arrayExport(array $array, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $output = "[\n";

        $items = [];
        foreach ($array as $key => $value) {
            $formattedKey = is_int($key) ? $key : "'".str_replace("'", "\\'", $key)."'";
            if (is_array($value)) {
                $items[] = $indent."    {$formattedKey} => ".$this->arrayExport($value, $level + 1);
            } else {
                $formattedValue = "'".str_replace("'", "\\'", $value)."'";
                $items[] = $indent."    {$formattedKey} => {$formattedValue}";
            }
        }

        $output .= implode(",\n", $items);
        $output .= "\n".$indent.']';

        return $output;
    }
}
