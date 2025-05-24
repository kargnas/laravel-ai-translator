<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\PoTranslationContextProvider;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Kargnas\LaravelAiTranslator\Transformers\PoLangTransformer;

class TranslatePoFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-po-file'
        . ' {file : Path to the PO file to translate}'
        . ' {--source-language=en : Source language code (ex: en)}'
        . ' {--target-language=ko : Target language code (ex: ko)}'
        . ' {--rules=* : Additional rules}'
        . ' {--debug : Enable debug mode}'
        . ' {--show-ai-response : Show raw AI response during translation}'
        . ' {--max-context-items=100 : Maximum number of context items}';

    protected $description = 'Translate a specific PO file with an array of strings';

    private $thinkingBlockCount = 0;

    protected $colors = [
        'gray' => "\033[38;5;245m",
        'blue' => "\033[38;5;33m",
        'green' => "\033[38;5;40m",
        'yellow' => "\033[38;5;220m",
        'purple' => "\033[38;5;141m",
        'red' => "\033[38;5;196m",
        'reset' => "\033[0m",
        'blue_bg' => "\033[48;5;24m",
        'white' => "\033[38;5;255m",
        'bold' => "\033[1m",
        'yellow_bg' => "\033[48;5;220m",
        'black' => "\033[38;5;16m",
        'line_clear' => "\033[2K\r",
    ];

    public function handle()
    {
        $GLOBALS['instant_results'] = [];

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

        if (!file_exists($filePath) || pathinfo($filePath, PATHINFO_EXTENSION) !== 'po') {
            $this->error("PO file not found: {$filePath}");
            return 1;
        }

        $transformer = new PoLangTransformer($filePath, $sourceLanguage);
        $strings = $transformer->flatten();

        $this->info("Starting translation of file: {$filePath}");
        $this->info("Source language: {$sourceLanguage}");
        $this->info("Target language: {$targetLanguage}");
        $this->info('Total strings: ' . count($strings));

        config(['ai-translator.ai.model' => 'claude-3-7-sonnet-latest']);
        config(['ai-translator.ai.max_tokens' => 64000]);
        config(['ai-translator.ai.use_extended_thinking' => false]);
        config(['ai-translator.ai.disable_stream' => false]);

        $contextProvider = new PoTranslationContextProvider();
        $maxContextItems = (int) $this->option('max-context-items') ?: 100;
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $sourceLanguage,
            $targetLanguage,
            $filePath,
            $maxContextItems
        );

        $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . ' Translation Context ' . $this->colors['reset']);
        $this->line(' - Context files: ' . count($globalContext));
        $this->line(' - Total context items: ' . collect($globalContext)->map(fn($items) => count($items))->sum());

        $provider = new AIProvider(
            basename($filePath),
            $strings,
            $sourceLanguage,
            $targetLanguage,
            [],
            $rules,
            $globalContext
        );

        $this->line("\n" . str_repeat('─', 80));
        $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . ' Translation Configuration ' . $this->colors['reset']);

        $this->line($this->colors['yellow'] . 'Source' . $this->colors['reset'] . ': '
            . $this->colors['green'] . $provider->sourceLanguageObj->name
            . $this->colors['gray'] . ' (' . $provider->sourceLanguageObj->code . ')' . $this->colors['reset']);

        $this->line($this->colors['yellow'] . 'Target' . $this->colors['reset'] . ': '
            . $this->colors['green'] . $provider->targetLanguageObj->name
            . $this->colors['gray'] . ' (' . $provider->targetLanguageObj->code . ')' . $this->colors['reset']);

        $this->line($this->colors['yellow'] . 'Rules' . $this->colors['reset'] . ': '
            . $this->colors['purple'] . count($provider->additionalRules) . ' rules' . $this->colors['reset']);

        if (!empty($provider->additionalRules)) {
            $this->line($this->colors['gray'] . 'Rule Preview:' . $this->colors['reset']);
            foreach (array_slice($provider->additionalRules, 0, 3) as $index => $rule) {
                $shortRule = strlen($rule) > 100 ? substr($rule, 0, 97) . '...' : $rule;
                $this->line($this->colors['blue'] . ' ' . ($index + 1) . '.' . $this->colors['reset'] . $shortRule);
            }
            if (count($provider->additionalRules) > 3) {
                $this->line($this->colors['gray'] . ' ... and '
                    . (count($provider->additionalRules) - 3) . ' more rules' . $this->colors['reset']);
            }
        }

        $this->line(str_repeat('─', 80) . "\n");

        $totalItems = count($strings);
        $results = [];

        $onTokenUsage = function (array $usage) use ($provider) {
            $this->updateTokenUsageDisplay($usage);
            if (($usage['final'] ?? false)) {
                $printer = new TokenUsagePrinter($provider->getModel());
                $printer->printFullReport($this, $usage);
            }
        };

        $onTranslated = function (LocalizedString $item, string $status, array $translatedItems) use ($strings, $totalItems) {
            $originalText = $strings[$item->key] ?? '';
            switch ($status) {
                case TranslationStatus::STARTED:
                    $this->line("\n" . str_repeat('─', 80));
                    $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold']
                        . ' Translation Started ' . count($translatedItems) . "/{$totalItems} " . $this->colors['reset'] . ' '
                        . $this->colors['yellow_bg'] . $this->colors['black'] . $this->colors['bold'] . " {$item->key} " . $this->colors['reset']);
                    $this->line($this->colors['gray'] . 'Source:' . $this->colors['reset'] . ' ' . substr($originalText, 0, 100)
                        . (strlen($originalText) > 100 ? '...' : ''));
                    break;
                case TranslationStatus::COMPLETED:
                    $this->line($this->colors['green'] . $this->colors['bold'] . 'Translation:' . $this->colors['reset'] . ' '
                        . $this->colors['bold'] . substr($item->translated, 0, 100)
                        . (strlen($item->translated) > 100 ? '...' : '') . $this->colors['reset']);
                    if ($item->comment) {
                        $this->line($this->colors['gray'] . 'Comment:' . $this->colors['reset'] . ' ' . $item->comment);
                    }
                    break;
            }
        };

        $onProgress = function ($currentText) use ($showAiResponse) {
            if ($showAiResponse) {
                $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($currentText, -100));
                $this->line($this->colors['line_clear'] . $this->colors['purple'] . 'AI Response:' . $this->colors['reset'] . ' ' . $responsePreview);
            }
        };

        $onThinking = function ($delta) {
            echo $this->colors['gray'] . $delta . $this->colors['reset'];
        };
        $onThinkingStart = function () {
            $this->thinkingBlockCount++;
            $this->line('');
            $this->line($this->colors['purple'] . '🧠 AI Thinking Block #' . $this->thinkingBlockCount . ' Started...' . $this->colors['reset']);
        };
        $onThinkingEnd = function ($completeThinkingContent) {
            $this->line('');
            $this->line($this->colors['purple'] . '✓ Thinking completed (' . strlen($completeThinkingContent) . ' chars)' . $this->colors['reset']);
            $this->line('');
        };

        $translatedItems = $provider
            ->setOnTranslated($onTranslated)
            ->setOnThinking($onThinking)
            ->setOnProgress($onProgress)
            ->setOnThinkingStart($onThinkingStart)
            ->setOnThinkingEnd($onThinkingEnd)
            ->setOnTokenUsage($onTokenUsage)
            ->translate();

        foreach ($translatedItems as $item) {
            $results[$item->key] = $item->translated;
        }

        $outputFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/'
            . pathinfo($filePath, PATHINFO_FILENAME) . '-' . $targetLanguage . '.po';

        $outputTransformer = new PoLangTransformer($outputFilePath, $sourceLanguage);
        foreach ($results as $k => $v) {
            $outputTransformer->updateString($k, $v);
        }

        $this->info("\nTranslation completed. Output written to: {$outputFilePath}");

        return 0;
    }

    protected function updateTokenUsageDisplay(array $usage): void
    {
        if (($usage['input_tokens'] ?? 0) == 0 && ($usage['output_tokens'] ?? 0) == 0) {
            return;
        }

        $this->output->write("\033[2K\r");
        $this->output->write(
            $this->colors['purple'] . 'Tokens: '
            . $this->colors['reset'] . 'Input: ' . $this->colors['green'] . ($usage['input_tokens'] ?? 0)
            . $this->colors['reset'] . ' | Output: ' . $this->colors['green'] . ($usage['output_tokens'] ?? 0)
            . $this->colors['reset'] . ' | Cache created: ' . $this->colors['blue'] . ($usage['cache_creation_input_tokens'] ?? 0)
            . $this->colors['reset'] . ' | Cache read: ' . $this->colors['blue'] . ($usage['cache_read_input_tokens'] ?? 0)
            . $this->colors['reset'] . ' | Total: ' . $this->colors['yellow'] . ($usage['total_tokens'] ?? 0)
            . $this->colors['reset']
        );
    }
}
