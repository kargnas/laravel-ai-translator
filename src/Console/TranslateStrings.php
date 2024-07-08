<?php

namespace Kargnas\LaravelAiTranslator\Console;


use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class TranslateStrings extends Command
{
    protected $signature = 'ai-translator:translate';

    protected $sourceLocale;
    protected $sourceDirectory;

    public function __construct() {
        parent::__construct();
        $this->setDescription(
            "Translates all PHP language files in this directory: " . config('ai-translator.source_directory') .
            "\n  Source Locale: " . config('ai-translator.source_locale'),
        );
    }

    public function handle() {
        $this->sourceLocale = config('ai-translator.source_locale');
        $this->sourceDirectory = config('ai-translator.source_directory');

        $this->translate();
    }

    protected static function getLanguageName($locale): ?string {
        $list = config('ai-translator.locale_names');
        $locale = strtolower(str_replace('-', '_', $locale));

        if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return null;
        }
    }

    protected static function getAdditionalRules($locale): array {
        $list = config('ai-translator.additional_rules');
        $locale = strtolower(str_replace('-', '_', $locale));

        if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return $list['default'] ?? [];
        }
    }

    public function translate() {
        $locales = $this->getExistingLocales();
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale) {
                continue;
            }

            $this->info("Starting {$locale}");
            $files = $this->getStringFilePaths($this->sourceLocale);
            foreach ($files as $file) {
                $outputFile = $this->getOutputDirectoryLocale($locale) . '/' . basename($file);
                $this->info("> Translating {$file} to {$locale} => {$outputFile}");
                $transformer = new PHPLangTransformer($file);
                $sourceStringList = $transformer->flatten();
                $targetStringTransformer = new PHPLangTransformer($outputFile);

                // Filter for untranslated strings
                $sourceStringList = collect($sourceStringList)
                    ->filter(function ($value, $key) use ($targetStringTransformer) {
                        // Skip if already translated
                        return !$targetStringTransformer->isTranslated($key);
                    })
                    ->toArray();

                if (sizeof($sourceStringList) > 50) {
                    if (!$this->confirm("{$outputFile}, Strings: " . sizeof($sourceStringList) . " -> Too many strings to translate. Could be expensive. Continue?")) {
                        $this->warn("Stopped translating!");
                        exit;
                    }
                }

                foreach ($sourceStringList as $key => $value) {
                    $translator = new AIProvider(
                        key: pathinfo($file, PATHINFO_FILENAME) . "." . $key,
                        string: $value,
                        sourceLanguage: static::getLanguageName($this->sourceLocale) ?? $this->sourceLocale,
                        targetLanguage: static::getLanguageName($locale) ?? $locale,
                        additionalRules: static::getAdditionalRules($locale),
                    );

                    $result = $translator->translate();

                    $targetStringTransformer->updateString($key, $result['translated']);
                }
            }

            $this->info("Finished translating $locale");
        }
    }

    public function getExistingLocales(): array {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // only directories
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root . '/' . $directory);
        });
        return $directories;
    }

    public function getOutputDirectoryLocale($locale) {
        return config('ai-translator.source_directory') . '/' . $locale;
    }

    public function getStringFilePaths($locale) {
        $files = [];
        $root = $this->sourceDirectory . '/' . $locale;
        $directories = array_diff(scandir($root), ['.', '..']);
        foreach ($directories as $directory) {
            // only .php
            if (pathinfo($directory, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $files[] = $root . '/' . $directory;
        }
        return $files;
    }
}
