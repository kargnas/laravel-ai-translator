<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

class PHPLangTransformer
{
    protected bool $dotNotation;

    public function __construct(
        public string $filePath,
    ) {
        $this->dotNotation = Config::get('ai-translator.dot_notation', false);
    }

    public function isTranslated($key)
    {
        $flatten = $this->flatten();
        return array_key_exists($key, $flatten);
    }

    public function flatten(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $content = require $this->filePath;
        return $this->flattenArray($content);
    }

    public function flattenArray($content, $prefix = ''): array
    {
        $flattened = [];
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $flattened = array_merge(
                    $flattened,
                    $this->flattenArray($value, $prefix . $key . '.')
                );
            } else {
                $flattened[$prefix . $key] = $value;
            }
        }
        return $flattened;
    }

    public function updateString(string $key, string $translated)
    {
        $list = $this->flatten();
        $list[$key] = trim($translated, '"'); // Remove extra double quotes

        if ($this->dotNotation) {
            // Save translations as flat array with dot notation keys
            $exported = var_export($list, true);
        } else {
            // Convert dot notation keys back to a multi-dimensional array
            $arrayTranslations = $this->unflatten($list);
            $exported = var_export($arrayTranslations, true);
        }

        // Optional: Convert to short array syntax ('[]' instead of 'array()')
        $exported = str_replace(['array (', ')'], ['[', ']'], $exported);
        $exported = preg_replace('/\s+=>\s+/', ' => ', $exported);

        // Save the translations to the file
        file_put_contents($this->filePath, "<?php\n\nreturn {$exported};\n");
    }

    protected function unflatten(array $array)
    {
        $result = [];
        foreach ($array as $key => $value) {
            Arr::set($result, $key, $value);
        }
        return $result;
    }
}
