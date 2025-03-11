<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

use Illuminate\Support\Facades\Config;

/**
 * Example of content of $filePath:
 * <?php
 *
 * return [
 *   'title' => [
 *     'blog' => 'Sangrak\'s Blog',
 *   ],
 * ];
 */
class PHPLangTransformer
{
    private array $originalContent = [];

    private array $flattenedContent = [];

    private bool $useDotNotation;

    public function __construct(
        public string $filePath,
    ) {
        $this->useDotNotation = Config::get('ai-translator.dot_notation', false);
        $this->loadOriginalContent();
        $this->flattenedContent = $this->flatten();
    }

    /**
     * Load original content from file
     */
    private function loadOriginalContent(): void
    {
        if (file_exists($this->filePath)) {
            $this->originalContent = require $this->filePath;
        } else {
            $this->originalContent = [];
        }
    }

    /**
     * Check if a key is already translated
     *
     * @param string $key Key in dot notation
     * @return bool
     */
    public function isTranslated(string $key): bool
    {
        return array_key_exists($key, $this->flattenedContent);
    }

    /**
     * Flatten a nested array to dot notation
     *
     * @return array
     */
    public function flatten(): array
    {
        return $this->flattenArray($this->originalContent);
    }

    /**
     * Recursive function to flatten an array with dot notation
     *
     * @param array $content Content to flatten
     * @param string $prefix Prefix for keys
     * @return array
     */
    public function flattenArray(array $content, string $prefix = ''): array
    {
        $flattened = [];
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenArray($value, $prefix . $key . '.'));
            } else {
                $flattened[$prefix . $key] = $value;
            }
        }
        return $flattened;
    }

    /**
     * Update a string in the language file
     *
     * @param string $key Key in dot notation
     * @param string $translated Translated value
     * @return void
     */
    public function updateString(string $key, string $translated): void
    {
        $this->loadOriginalContent();

        if ($this->useDotNotation) {
            $this->updateStringDotNotation($key, $translated);
        } else {
            $this->updateStringArrayNotation($key, $translated);
        }

        $this->flattenedContent = $this->flatten();
    }

    /**
     * Update a string using dot notation (flat array)
     *
     * @param string $key Key in dot notation
     * @param string $translated Translated value
     * @return void
     */
    private function updateStringDotNotation(string $key, string $translated): void
    {
        $this->flattenedContent[$key] = $translated;

        file_put_contents($this->filePath, "<?php" . PHP_EOL . "return " . var_export($this->flattenedContent, true) . ";");
    }

    /**
     * Update a string while maintaining the nested array structure
     *
     * @param string $key Key in dot notation
     * @param string $translated Translated value
     * @return void
     */
    private function updateStringArrayNotation(string $key, string $translated): void
    {
        // Create a copy of the original content to work with
        $content = $this->originalContent;

        // Split the key by dots to navigate the nested array
        $parts = explode('.', $key);

        // Start with a reference to the content array
        $current = &$content;

        // Navigate through the nested array to find the right place
        foreach ($parts as $i => $part) {
            // If we're at the last part of the key, set the value
            if ($i === count($parts) - 1) {
                $current[$part] = $translated;
            } else {
                // Create the nested array if it doesn't exist
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                // Move deeper into the nested array
                $current = &$current[$part];
            }
        }

        // Save the updated content back to the file
        $this->saveContentToFile($content);

        // Update the original content
        $this->originalContent = $content;
    }

    /**
     * Save content to file with proper formatting
     *
     * @param array $content Content to save
     * @return void
     */
    private function saveContentToFile(array $content): void
    {
        $code = "<?php\n\nreturn " . $this->arrayExport($content, 0) . ";\n";
        file_put_contents($this->filePath, $code);
    }

    /**
     * Export array with proper indentation and formatting
     *
     * @param array $array Array to export
     * @param int $level Indentation level
     * @return string
     */
    private function arrayExport(array $array, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $output = "[\n";

        $items = [];
        foreach ($array as $key => $value) {
            $formattedKey = is_int($key) ? $key : "'" . str_replace("'", "\\'", $key) . "'";

            if (is_array($value)) {
                $items[] = $indent . "    " . $formattedKey . " => " . $this->arrayExport($value, $level + 1);
            } else {
                $formattedValue = "'" . str_replace("'", "\\'", $value) . "'";
                $items[] = $indent . "    " . $formattedKey . " => " . $formattedValue;
            }
        }

        $output .= implode(",\n", $items);
        $output .= "\n" . $indent . "]";

        return $output;
    }
}
