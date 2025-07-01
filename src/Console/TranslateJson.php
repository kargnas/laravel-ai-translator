<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\TransformerInterface;

class TranslateJson extends BaseTranslateCommand
{
    protected $signature = 'ai-translator:translate-json
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translate root JSON language files such as lang/en.json';

    /**
     * Get the transformer instance for JSON files
     */
    protected function getTransformer(): TransformerInterface
    {
        return new JSONLangTransformer();
    }

    /**
     * Get language files for the given locale
     */
    protected function getLanguageFiles(string $locale): array
    {
        $jsonFile = "{$this->sourceDirectory}/{$locale}.json";
        return file_exists($jsonFile) ? ["{$locale}.json"] : [];
    }

    /**
     * Check if source language files exist
     */
    protected function hasSourceFiles(): bool
    {
        return file_exists("{$this->sourceDirectory}/{$this->sourceLocale}.json");
    }

    /**
     * Get the source file path for a given file and locale
     */
    protected function getSourceFilePath(string $file, string $locale): string
    {
        // For JSON files, we use the source locale
        return "{$this->sourceDirectory}/{$this->sourceLocale}.json";
    }

    /**
     * Get the target file path for a given file and locale
     */
    protected function getTargetFilePath(string $file, string $locale): string
    {
        return "{$this->sourceDirectory}/{$locale}.json";
    }
}