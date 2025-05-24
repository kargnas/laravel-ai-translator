<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;

class TranslateJsonFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-json
                            {file : Path to the JSON file to translate}
                            {--source-language=en : Source language code (ex: en)}
                            {--target-language=ko : Target language code (ex: ko)}
                            {--rules=* : Additional rules}
                            {--debug : Enable debug mode}
                            {--show-ai-response : Show raw AI response during translation}';

    protected $description = 'Translate a JSON language file with an array of strings';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $sourceLanguage = $this->option('source-language');
        $targetLanguage = $this->option('target-language');
        $rules = $this->option('rules') ?: [];
        $showAiResponse = $this->option('show-ai-response');
        $debug = $this->option('debug');

        if ($debug) {
            config(['app.debug' => true]);
            config(['ai-translator.debug' => true]);
        }

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $content = file_get_contents($filePath);
        $strings = json_decode($content, true);
        if (!is_array($strings)) {
            $this->error('File must be a valid JSON object with key-value pairs');
            return 1;
        }

        $this->info("Starting translation of file: {$filePath}");
        $this->info("Source language: {$sourceLanguage}");
        $this->info("Target language: {$targetLanguage}");
        $this->info('Total strings: ' . count($strings));

        $provider = new AIProvider(
            filename: basename($filePath),
            strings: $strings,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            additionalRules: $rules,
            globalTranslationContext: []
        );

        $provider
            ->setOnTranslated(function (LocalizedString $item, string $status) {
                if ($status === TranslationStatus::COMPLETED) {
                    $this->line("Translated: {$item->key}");
                }
            })
            ->setOnProgress(function ($currentText) use ($showAiResponse) {
                if ($showAiResponse) {
                    $this->line($currentText);
                }
            })
            ->setOnTokenUsage(function (array $usage) {
                if (isset($usage['final']) && $usage['final']) {
                    $this->line(
                        "Tokens - input: {$usage['input_tokens']}, output: {$usage['output_tokens']}, total: {$usage['total_tokens']}"
                    );
                }
            });

        $translatedItems = $provider->translate();

        $results = [];
        foreach ($translatedItems as $item) {
            $results[$item->key] = $item->translated;
        }

        $basename = basename($filePath, '.json');
        $outputFileName = $basename === $sourceLanguage ? $targetLanguage : "{$basename}-{$targetLanguage}";
        $outputFilePath = dirname($filePath) . "/{$outputFileName}.json";

        file_put_contents($outputFilePath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("Translation completed. Output written to: {$outputFilePath}");

        return 0;
    }
}
