<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\TransformerInterface;

class TranslateStrings extends BaseTranslateCommand
{
    protected $signature = 'ai-translator:translate-strings
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es). If not provided, will ask interactively}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translate string files in all languages from the source language (e.g. lang/en/) to other languages';

    /**
     * Get the transformer instance for PHP files
     */
    protected function getTransformer(): TransformerInterface
    {
        return new PHPLangTransformer();
    }

    /**
     * Get language files for the given locale
     */
    protected function getLanguageFiles(string $locale): array
    {
        $localeDir = "{$this->sourceDirectory}/{$locale}";
        
        if (!is_dir($localeDir)) {
            return [];
        }

        $files = glob("{$localeDir}/*.php");
        return array_map(function ($file) use ($locale) {
            return str_replace("{$this->sourceDirectory}/{$locale}/", '', $file);
        }, $files);
    }

    /**
     * Check if source language files exist
     */
    protected function hasSourceFiles(): bool
    {
        $sourceDir = "{$this->sourceDirectory}/{$this->sourceLocale}";
        return is_dir($sourceDir) && count(glob("{$sourceDir}/*.php")) > 0;
    }

    /**
     * Get the source file path for a given file and locale
     */
    protected function getSourceFilePath(string $file, string $locale): string
    {
        return "{$this->sourceDirectory}/{$locale}/{$file}";
    }

    /**
     * Get the target file path for a given file and locale
     */
    protected function getTargetFilePath(string $file, string $locale): string
    {
        return "{$this->sourceDirectory}/{$locale}/{$file}";
    }
}