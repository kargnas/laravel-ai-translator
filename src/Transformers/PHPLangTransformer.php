<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

/**
 * Example of content of $filePath:
 * <?php
 *
 * return [
 * 'title' => [
 * 'blog' => 'Sangrak\'s Blog',
 * ],
 * ];
 */
class PHPLangTransformer
{
    public function __construct(
        public string $filePath,
    ) {
    }

    public function isTranslated($key) {
        $flatten = $this->flatten();
        return array_key_exists($key, $flatten);
    }

    public function flatten(): array {
        if (!file_exists($this->filePath)) {
            return [];
        }
        $content = require $this->filePath;
        return $this->flattenArray($content);
    }

    public function flattenArray($content, $prefix = ''): array {
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

    public function updateString(string $key, string $translated) {
        $list = $this->flatten();
        $list[$key] = $translated;
        file_put_contents($this->filePath, "<?php" . PHP_EOL . "return ".var_export($list, true).";");
    }
}
