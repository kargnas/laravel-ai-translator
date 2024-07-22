<?php

namespace Kargnas\LaravelAiTranslator\Console;


use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class TranslateStrings extends Command
{
    protected static $additionalRules = [
        'pl' => [
            "- Polish pluralization: Always use 3 forms: {1} singular, [2,4] plural for few, [5,*] plural for many. Example: \"One book|:count books\" becomes \"{1} jedna książka|[2,4] :count książki|[5,*] :count książek\".",
            "- Polish pluralization example: For 'apple': {1} jedno jabłko|[2,4] :count jabłka|[5,*] :count jabłek. Consider gender (męski, żeński, nijaki) and case (mianownik, dopełniacz, etc.) when forming plurals.",
        ],
        'zh' => [
            "- CRITICAL: For ALL Chinese translations, ALWAYS use exactly THREE parts: {1} 一 + measure word + noun|{2} 两 + measure word + noun|[3,*] :count + measure word + noun. This is MANDATORY, even if the original only has two parts. NO SPACES in Chinese text except right after numbers in curly braces and square brackets.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} 一X词Y|{2} 两X词Y|[3,*] :countX词Y. Replace X with correct measure word, Y with noun. Ensure NO SPACE between :count and the measure word. If any incorrect spaces are found, remove them and flag for review.",
        ],
        'ar' => [
            "- CRITICAL: For ALL Arabic translations, ALWAYS use exactly FOUR parts: {1} singular|{2} dual|[3,10] plural for few|[11,*] plural for many. This is MANDATORY, even if the original has fewer forms.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} كتاب واحد|{2} كتابان|[3,10] :count كتب|[11,*] :count كتابًا. Adjust endings based on grammatical case. Consider gender and definiteness. If unsure about a form, use a placeholder and flag for human review.",
        ],
        'ru' => [
            "- CRITICAL: For ALL Russian translations, ALWAYS use exactly THREE parts: {1} singular|[2,4] plural for few|[5,*] plural for many. This is MANDATORY, even if the original has fewer forms.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} книга|[2,4] :count книги|[5,*] :count книг. Consider gender (masculine, feminine, neuter) and case (nominative, genitive, etc.) when forming plurals. If unsure about a form, use a placeholder and flag for human review.",
        ],
        'ga' => [
            "- CRITICAL: For ALL Irish (Gaeilge) translations, ALWAYS use exactly FOUR parts: {1} singular|{2} dual|[3,6] plural for few|[7,*] plural for many. This is MANDATORY, even if the original has fewer forms.",
            "- Example structure (DO NOT COPY WORDS, only structure): {1} leabhar amháin|{2} dhá leabhar|[3,6] :count leabhair|[7,*] :count leabhar. Consider initial mutations (séimhiú, urú) and irregular plurals. For nouns that don't have all forms, repeat the closest appropriate form. If unsure, flag for human review.",
        ],
        'ko' => [
            // 1개, 2개 할 때 '1 개', '2 개' 이런식으로 써지는 것 방지
            "- Don't add a space between the number and the measure word with variables. Example: {1} 한 개|{2} 두 개|[3,*] :count개",
        ]
    ];

    protected $signature = 'ai-translator:translate';

    protected $sourceLocale;
    protected $sourceDirectory;
    protected $chunkSize;

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
        $this->chunkSize = config('ai-translator.chunk_size', 10);

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

    private static function getAdditionalRulesFromConfig($locale): array {
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

    private static function getAdditionalRulesDefault($locale): array {
        $list = static::$additionalRules;
        $locale = strtolower(str_replace('-', '_', $locale));

        if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return $list['default'] ?? [];
        }
    }

    protected static function getAdditionalRules($locale): array {
        return array_merge(static::getAdditionalRulesFromConfig($locale), static::getAdditionalRulesDefault($locale));
    }

    public function translate() {
        $locales = $this->getExistingLocales();
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale) {
                continue;
            }

            $targetLanguageName = static::getLanguageName($locale);

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
                    ->each(function ($chunk) use ($locale, $file, $targetStringTransformer) {
                        $translator = new AIProvider(
                            filename: $file,
                            strings: $chunk->toArray(),
                            sourceLanguage: static::getLanguageName($this->sourceLocale) ?? $this->sourceLocale,
                            targetLanguage: static::getLanguageName($locale) ?? $locale,
                            additionalRules: static::getAdditionalRules($locale),
                        );

                        $items = $translator->translate();

                        foreach ($items as $item) {
                            \Log::debug('Saving: ' . $item->key . ' => ' . $item->translated);
                            $targetStringTransformer->updateString($item->key, $item->translated);
                        }
                    });
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
