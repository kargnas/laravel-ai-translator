<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Utility;

class TranslateStrings extends Command
{
    protected $signature = 'ai-translator:translate';

    protected $sourceLocale;
    protected $sourceDirectory;
    protected $chunkSize;
    protected array $referenceLocales = [];

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(
            "Translates all PHP language files in this directory: " . config('ai-translator.source_directory') .
            "\n  Source Locale: " . config('ai-translator.source_locale'),
        );
    }

    public function handle()
    {
        $this->sourceDirectory = config('ai-translator.source_directory');

        $this->sourceLocale = $this->choiceLanguages("Choose a source language to translate from", false, 'en');

        if ($this->ask('Do you want to add reference languages? (y/n)', 'n') === 'y') {
            $this->referenceLocales = $this->choiceLanguages("Choose a language to reference when translating, preferably one that has already been vetted and translated to a high quality. You can select multiple languages via ',' (e.g. '1, 2')", true);
        }

        $this->chunkSize = $this->ask("Enter the chunk size for translation. Translate strings in a batch. The higher, the cheaper. (default: 30)", 30);
        $this->translate();
    }

    public function choiceLanguages($question, $multiple, $default = null)
    {
        $locales = $this->getExistingLocales();

        $selectedLocales = $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple
        );

        if (is_array($selectedLocales)) {
            $this->info("Selected locales: " . implode(', ', $selectedLocales));
        } else {
            $this->info("Selected locale: " . $selectedLocales);
        }

        return $selectedLocales;
    }

    public function translate()
    {
        $locales = $this->getExistingLocales();
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale) {
                continue;
            }

            if (in_array($locale, config('ai-translator.skip_locales', []))) {
                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($locale);

            if ($targetLanguageName) {
                $this->info("Starting {$targetLanguageName} ({$locale})");
            } else {
                throw new \Exception("Language name not found for locale: {$locale}. Please add it to the config file.");
            }

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

                // 번역할 항목이 없으면 건너뛰기
                if (count($sourceStringList) === 0) {
                    $this->info("  > All strings are already translated. Skipping.");
                    continue;
                }

                // Extended Thinking 설정
                config(['ai-translator.ai.use_extended_thinking' => false]);

                $referenceStringList = collect($this->referenceLocales)
                    ->filter(fn($referenceLocale) => !in_array($referenceLocale, [$locale, $this->sourceLocale]))
                    ->map(function ($referenceLocale) use ($file, $sourceStringList) {
                        $referenceFile = $this->getOutputDirectoryLocale($referenceLocale) . '/' . basename($file);
                        if (!file_exists($referenceFile)) {
                            return null;
                        }

                        $referenceTransformer = new PHPLangTransformer($referenceFile);
                        $referenceStringList = $referenceTransformer->flatten();

                        return [
                            'locale' => $referenceLocale,
                            'strings' => $referenceStringList,
                        ];
                    })
                    ->filter()
                    ->toArray();

                if (sizeof($sourceStringList) > 100) {
                    if (!$this->confirm("{$outputFile}, Strings: " . sizeof($sourceStringList) . " -> Many strings to translate. Could be expensive. Continue?")) {
                        $this->warn("Stopped translating!");
                        exit;
                    }
                }

                // Chunk the strings because of the pricing
                // But also this will increase the speed of the translation, and quality of continuous translation
                collect($sourceStringList)
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($locale, $file, $targetStringTransformer, $referenceStringList) {
                        $translator = new AIProvider(
                            filename: $file,
                            strings: $chunk->mapWithKeys(function ($item, $key) use ($referenceStringList) {
                                return [
                                    $key => [
                                        'text' => $item,
                                        'references' => collect($referenceStringList)->map(function ($items) use ($key) {
                                            return $items['strings'][$key] ?? "";
                                        })->filter(function ($value) {
                                            return strlen($value) > 0;
                                        }),
                                    ],
                                ];
                            })->toArray(),
                            sourceLanguage: $this->sourceLocale,
                            targetLanguage: $locale,
                            additionalRules: [],
                        );

                        try {
                            $translatedItems = $translator->translate();

                            foreach ($translatedItems as $item) {
                                \Log::debug('Saving: ' . $item->key . ' => ' . $item->translated);
                                $targetStringTransformer->updateString($item->key, $item->translated);
                            }
                        } catch (\Exception $e) {
                            $this->error("Translation failed: " . $e->getMessage());
                        }
                    });
            }

            $this->info("Finished translating $locale");
        }
    }

    /**
     * @return array|string[]
     */
    public function getExistingLocales(): array
    {
        $root = $this->sourceDirectory;
        $directories = array_diff(scandir($root), ['.', '..']);
        // only directories
        $directories = array_filter($directories, function ($directory) use ($root) {
            return is_dir($root . '/' . $directory);
        });
        return collect($directories)->values()->toArray();
    }

    public function getOutputDirectoryLocale($locale)
    {
        return config('ai-translator.source_directory') . '/' . $locale;
    }

    public function getStringFilePaths($locale)
    {
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
