<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Illuminate\Support\Facades\Log;

class TestTranslateCommand extends Command
{
    protected $signature = 'ai-translator:test-translate
                          {source_language=en : Source language code (ex: en)}
                          {target_language=ko : Target language code (ex: ko)}
                          {--text= : Text to translate}
                          {--rules=* : Additional rules}
                          {--extended-thinking : Use Extended Thinking feature (only supported for claude-3-7 models)}
                          {--debug : Enable debug mode with detailed logging}
                          {--show-xml : Show raw XML response in the output}';

    protected $description = 'Test translation using AIProvider.';

    // Console color codes
    protected $colors = [
        'gray' => "\033[38;5;245m",
        'blue' => "\033[38;5;33m",
        'green' => "\033[38;5;40m",
        'yellow' => "\033[38;5;220m",
        'purple' => "\033[38;5;141m",
        'red' => "\033[38;5;196m",
        'reset' => "\033[0m"
    ];

    // Last displayed progress info
    private $lastProgressInfo = '';

    // Thinking block count
    private $thinkingBlockCount = 0;

    // Raw XML response (always stored for potential display)
    private $rawXmlResponse = '';

    public function handle()
    {
        $sourceLanguage = $this->argument('source_language');
        $targetLanguage = $this->argument('target_language');
        $text = $this->option('text') ?? $this->ask('Enter text to translate');
        $rules = $this->option('rules');
        $useExtendedThinking = (bool) $this->option('extended-thinking');
        $useExtendedThinking = false;
        $debugMode = (bool) $this->option('debug');
        $showXml = (bool) $this->option('show-xml');

        $this->info("Starting translation test...");
        $this->info("Source language: {$sourceLanguage}");
        $this->info("Target language: {$targetLanguage}");
        $this->info("Text to translate: {$text}");

        if ($debugMode) {
            $this->info("Debug mode: Enabled");
            config(['ai-translator.debug' => true]);
        }

        if ($showXml) {
            $this->info("Show XML: Enabled");
        }

        if (!empty($rules)) {
            $this->info("Additional rules:");
            foreach ($rules as $rule) {
                $this->info("- {$rule}");
            }
        }

        if ($useExtendedThinking) {
            $this->info("Extended Thinking: Enabled");
        }

        // Set up the progress bar
        $progressBar = $this->output->createProgressBar(1);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        // Set Extended Thinking configuration
        config(['ai-translator.ai.use_extended_thinking' => $useExtendedThinking]);

        // Indicates whether we've displayed thinking output
        $hasDisplayedThinking = false;

        try {
            $provider = new AIProvider(
                filename: 'test.php',
                strings: ['test' => $text],
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
                additionalRules: $rules,
            );

            // Called when a translation item is completed
            $onTranslated = function (LocalizedString $translatedItem, int $count) use ($progressBar, &$text) {
                $progressBar->setProgress($count);

                // 이 번역 결과는 디버깅용이며 실제 번역은 원래  번역 결과를 사용
                $cleanTranslation = $translatedItem->translated;

                // Show completed translation in green with full content preserved
                $this->line('');
                $this->line($this->colors['green'] . "✅ Translation completed: " . $translatedItem->key . $this->colors['reset']);
                $this->line($this->colors['green'] . "   " . $cleanTranslation . $this->colors['reset']);
                $this->line('');

                // 번역된 결과 객체의 번역 내용도 업데이트
                $translatedItem->translated = $cleanTranslation;
            };

            // Called for AI's thinking process
            $onThinking = function ($thinkingDelta) use (&$hasDisplayedThinking) {
                // Display thinking content in gray
                echo $this->colors['gray'] . $thinkingDelta . $this->colors['reset'];
            };

            // Called when thinking block starts
            $onThinkingStart = function () use (&$hasDisplayedThinking, &$thinkingBlockCount) {
                $this->thinkingBlockCount++;
                $this->line('');
                $this->line($this->colors['purple'] . "🧠 AI Thinking Block #" . $this->thinkingBlockCount . " Started..." . $this->colors['reset']);
                $hasDisplayedThinking = true;
            };

            // Called when thinking block ends
            $onThinkingEnd = function ($completeThinkingContent) {
                // Add a separator line to indicate the end of thinking block
                $this->line('');
                $this->line($this->colors['purple'] . "✓ Thinking completed (" . strlen($completeThinkingContent) . " chars)" . $this->colors['reset']);
                $this->line('');
            };

            // Called for each response chunk to show progress
            $onProgress = function ($currentText, $translatedItems) {
                // Store the response for later use (always store it)
                $this->rawXmlResponse = $currentText;

                // We don't want to spam the console, so we'll only show summary of progress
                // Extract last 50 characters of current text
                // $lastChars = mb_substr($currentText, -50);

                // // Only update if text is different from last displayed
                // if ($lastChars !== $this->lastProgressInfo) {
                //     echo "\r" . $this->colors['blue'] . "🔄 Processing: ..." . $lastChars . $this->colors['reset'] . str_repeat(' ', 10);
                //     $this->lastProgressInfo = $lastChars;
                // }
            };

            // Execute translation with callbacks
            $result = $provider->translate($onTranslated, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd);

            $progressBar->finish();
            $this->line('');

            // 이미 $onTranslated 콜백에서 처리했으므로 여기서는 간소화
            $this->info("\nTranslation result summary:");
            $this->info("Original: {$text}");

            // 모든 번역 결과 출력
            if (count($result) > 1) {
                $this->info("Multiple translations were generated (" . count($result) . " items):");

                foreach ($result as $index => $item) {
                    $this->info("Item #" . ($index + 1) . " [" . $item->key . "]: " . $item->translated);
                }
            }

            // 이미 상단에서 원본 XML을 처리하고 표시했으므로 이 부분은 제거

            // If it looks like the translation missed the HTML tags, show a tip
            if (strpos($text, '<b>') !== false && strpos($result[0]->translated, '<b>') === false) {
                $this->line('');
                $this->line($this->colors['yellow'] . "Note: It appears HTML tags in the input were not translated completely. You may want to try again." . $this->colors['reset']);
            }

            // Show raw XML response if requested
            if ($showXml && !empty($this->rawXmlResponse)) {
                $this->line('');
                $this->line($this->colors['yellow'] . "Raw XML Response:" . $this->colors['reset']);
                $this->line($this->colors['gray'] . $this->rawXmlResponse . $this->colors['reset']);
            }

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->line('');
            $this->error("Error occurred during translation: " . $e->getMessage());

            if ($debugMode) {
                $this->line('');
                $this->line($this->colors['red'] . "Error details:" . $this->colors['reset']);
                $this->line($this->colors['red'] . $e->getTraceAsString() . $this->colors['reset']);
            }

            return 1;
        }

        return 0;
    }
}