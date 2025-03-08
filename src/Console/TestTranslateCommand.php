<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
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

        // Set Extended Thinking configuration
        config(['ai-translator.ai.model' => 'claude-3-5-haiku-20241022']);
        config(['ai-translator.ai.use_extended_thinking' => $useExtendedThinking]);

        // Indicates whether we've displayed thinking output
        $hasDisplayedThinking = true;

        try {
            $provider = new AIProvider(
                filename: 'test.php',
                strings: ['test' => $text],
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
                additionalRules: $rules,
            );

            // Called when a translation item is completed
            $onTranslated = function (LocalizedString $item, string $status, array $translatedItems) use ($text) {
                // 원본 텍스트 가져오기
                $originalText = $text;

                switch ($status) {
                    case TranslationStatus::STARTED:
                        $this->line("\n" . str_repeat('─', 80));
                        $this->line("\033[1;44;37m 번역시작 \033[0m \033[1;43;30m {$item->key} \033[0m");
                        $this->line("\033[90m원본:\033[0m " . substr($originalText, 0, 100) .
                            (strlen($originalText) > 100 ? '...' : ''));
                        break;

                    case TranslationStatus::COMPLETED:
                        $this->line("\033[1;32m번역:\033[0m \033[1m" . substr($item->translated, 0, 100) .
                            (strlen($item->translated) > 100 ? '...' : '') . "\033[0m");
                        break;
                }
            };

            // Called for AI's thinking process
            $onThinking = function ($thinkingDelta) {
                // Display thinking content in gray
                echo $this->colors['gray'] . $thinkingDelta . $this->colors['reset'];
            };

            // Called when thinking block starts
            $onThinkingStart = function () {
                $this->thinkingBlockCount++;
                $this->line('');
                $this->line($this->colors['purple'] . "🧠 AI Thinking Block #" . $this->thinkingBlockCount . " Started..." . $this->colors['reset']);
            };

            // Called when thinking block ends
            $onThinkingEnd = function ($completeThinkingContent) {
                // Add a separator line to indicate the end of thinking block
                $this->line('');
                $this->line($this->colors['purple'] . "✓ Thinking completed (" . strlen($completeThinkingContent) . " chars)" . $this->colors['reset']);
                $this->line('');
            };

            // Called for each response chunk to show progress
            $onProgress = function ($currentText, $translatedItems) use ($showXml) {
                // Store the response for later use (always store it)
                $this->rawXmlResponse = $currentText;

                if ($showXml) {
                    $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($currentText, -100));
                    $this->line("\033[2K\r\033[35mAI응답:\033[0m " . $responsePreview);
                }
            };

            // Execute translation with callbacks
            $result = $provider->translate($onTranslated, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd);

            // Show raw XML response if requested
            if ($showXml && !empty($this->rawXmlResponse)) {
                $this->line('');
                $this->line($this->colors['yellow'] . "Raw XML Response:" . $this->colors['reset']);
                $this->line($this->colors['gray'] . $this->rawXmlResponse . $this->colors['reset']);
            }

        } catch (\Exception $e) {
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