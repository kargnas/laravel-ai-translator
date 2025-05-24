<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

class PoLangTransformer
{
    private array $entries = [];
    private string $filePath;
    private string $sourceLanguage;

    public function __construct(string $filePath, string $sourceLanguage = 'en')
    {
        $this->filePath = $filePath;
        $this->sourceLanguage = $sourceLanguage;
        $this->loadContent();
    }

    private function loadContent(): void
    {
        if (!file_exists($this->filePath)) {
            $this->entries = [];
            return;
        }

        $this->entries = [];
        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        $currentId = null;
        foreach ($lines as $line) {
            if (preg_match('/^msgid\s+"(.*)"/', $line, $m)) {
                $currentId = stripcslashes($m[1]);
            } elseif ($currentId !== null && preg_match('/^msgstr\s+"(.*)"/', $line, $m)) {
                $this->entries[$currentId] = stripcslashes($m[1]);
                $currentId = null;
            }
        }
    }

    public function flatten(): array
    {
        return $this->entries;
    }

    public function isTranslated(string $key): bool
    {
        return isset($this->entries[$key]) && $this->entries[$key] !== '';
    }

    public function updateString(string $key, string $translated): void
    {
        $this->entries[$key] = $translated;
        $this->saveToFile();
    }

    private function saveToFile(): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        $lines = [
            '# WARNING: This is an auto-generated file.',
            '# Do not modify this file manually as your changes will be lost.',
            "# This file was automatically translated from {$this->sourceLanguage} at {$timestamp}.",
        ];

        foreach ($this->entries as $id => $str) {
            $idEsc = addcslashes($id, "\n\r\t\"");
            $strEsc = addcslashes($str, "\n\r\t\"");
            $lines[] = '';
            $lines[] = "msgid \"{$idEsc}\"";
            $lines[] = "msgstr \"{$strEsc}\"";
        }

        file_put_contents($this->filePath, implode("\n", $lines) . "\n");
    }
}
