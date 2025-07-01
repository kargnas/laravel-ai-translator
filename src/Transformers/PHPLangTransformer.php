<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Facades\Config;

class PHPLangTransformer implements TransformerInterface
{
    private bool $useDotNotation;
    private string $sourceLanguage;

    public function __construct()
    {
        $this->useDotNotation = Config::get('ai-translator.dot_notation', false);
        $this->sourceLanguage = Config::get('ai-translator.source_locale', 'en');
    }

    /**
     * Parse a PHP language file and return its contents as an array
     */
    public function parse(string $file): array
    {
        return file_exists($file) ? require $file : [];
    }

    /**
     * Save the language data to a PHP file
     */
    public function save(string $file, array $data): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        
        // If not using dot notation and data is already nested, use it as is
        // If using dot notation, keep it flat
        $content = $data;
        if (!$this->useDotNotation && $this->isFlattened($data)) {
            $content = $this->unflattenArray($data);
        }

        $lines = [
            "<?php\n",
            '/**',
            ' * WARNING: This is an auto-generated file.',
            ' * Do not modify this file manually as your changes will be lost.',
            " * This file was automatically translated from {$this->sourceLanguage} at {$timestamp}.",
            " */\n",
            'return '.$this->arrayExport($content, 0).";\n",
        ];

        // Ensure directory exists
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, implode("\n", $lines));
    }

    /**
     * Check if array is flattened (contains dot notation keys)
     */
    private function isFlattened(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (strpos($key, '.') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flatten a nested array into dot notation
     */
    public function flatten(array $array, string $prefix = ''): array
    {
        return $this->flattenArray($array, $prefix);
    }

    /**
     * Unflatten a dot notation key-value pair back into a nested array
     */
    public function unflatten(array $array, string $key, string $value): array
    {
        if ($this->useDotNotation) {
            $array[$key] = $value;
            return $array;
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
        $flattened = $this->flattenArray($content);
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

    /**
     * Export array as PHP code
     */
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