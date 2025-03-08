<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
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
        $text = $this->option('text');
        $rules = $this->option('rules');
        $debug = (bool) $this->option('debug');
        $showXml = $this->option('show-xml');

        if (!$text) {
            $text = $this->ask('Enter text to translate');
        }

        if ($debug) {
            config(['app.debug' => true]);
            config(['ai-translator.debug' => true]);
        }

        if ($this->option('extended-thinking')) {
            config(['ai-translator.ai.use_extended_thinking' => true]);
        }

        return (function () use ($sourceLanguage, $targetLanguage, $text, $rules, $debug, $showXml) {
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
                $onThinkingEnd = function ($thinkingContent) {
                    $this->line('');
                    $this->line($this->colors['purple'] . "🧠 AI Thinking Block #" . $this->thinkingBlockCount . " Completed" . $this->colors['reset']);
                };

                // Called for each chunk of response
                $onProgress = function ($chunk, $translatedItems) use ($showXml) {
                    if ($showXml) {
                        $this->rawXmlResponse .= $chunk;
                    }
                };

                $translatedItems = $provider->translate(
                    $onTranslated,
                    $onThinking,
                    $onProgress,
                    $onThinkingStart,
                    $onThinkingEnd
                );

                if ($showXml) {
                    $this->line("\n" . str_repeat('─', 80));
                    $this->line("\033[1;44;37m Raw XML Response \033[0m");
                    $this->line($this->rawXmlResponse);
                }

                return 0;
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                if ($debug) {
                    Log::error($e);
                }
                return 1;
            }
        })();
    }
}