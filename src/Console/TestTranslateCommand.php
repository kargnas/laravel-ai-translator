<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

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
        'reset' => "\033[0m",
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
        $rulesList = $this->option('rules');
        $useExtendedThinking = $this->option('extended-thinking');
        $debug = $this->option('debug');
        $showXml = $this->option('show-xml');
        $showThinking = true; // 항상 thinking 내용 표시

        if (! $text) {
            $text = $this->ask('Enter text to translate');
        }

        if ($debug) {
            config(['app.debug' => true]);
            config(['ai-translator.debug' => true]);
        }

        if ($useExtendedThinking) {
            config(['ai-translator.ai.use_extended_thinking' => true]);
        }

        // 토큰 사용량 추적을 위한 변수
        $tokenUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'total_tokens' => 0,
        ];

        // AIProvider 생성
        $provider = new AIProvider(
            filename: 'Test.php',
            strings: ['test' => $text],
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            additionalRules: $rulesList,
            globalTranslationContext: null
        );

        // 토큰 사용량 추적 콜백
        $onTokenUsage = function (array $usage) use ($provider) {
            // 토큰 사용량을 한 줄로 표시 (실시간 업데이트)
            $this->output->write("\033[2K\r");
            $this->output->write(
                '<fg=magenta>Tokens:</> '.
                "Input: <fg=green>{$usage['input_tokens']}</> | ".
                "Output: <fg=green>{$usage['output_tokens']}</> | ".
                "Cache created: <fg=blue>{$usage['cache_creation_input_tokens']}</> | ".
                "Cache read: <fg=blue>{$usage['cache_read_input_tokens']}</> | ".
                "Total: <fg=yellow>{$usage['total_tokens']}</>"
            );

            // 마지막 토큰 사용량 정보는 자세히 출력
            if (isset($usage['final']) && $usage['final']) {
                $this->output->writeln(''); // 줄바꿈 추가
                $printer = new TokenUsagePrinter($provider->getModel());
                $printer->printFullReport($this, $usage);
            }
        };

        // Called when a translation item is completed
        $onTranslated = function (LocalizedString $item, string $status, array $translatedItems) use ($text) {
            // 원본 텍스트 가져오기
            $originalText = $text;

            switch ($status) {
                case TranslationStatus::STARTED:
                    $this->line("\n".str_repeat('─', 80));
                    $this->line("\033[1;44;37m 번역시작 \033[0m \033[1;43;30m {$item->key} \033[0m");
                    $this->line("\033[90m원본:\033[0m ".substr($originalText, 0, 100).
                        (strlen($originalText) > 100 ? '...' : ''));
                    break;

                case TranslationStatus::COMPLETED:
                    $this->line("\033[1;32m번역:\033[0m \033[1m".substr($item->translated, 0, 100).
                        (strlen($item->translated) > 100 ? '...' : '')."\033[0m");
                    break;
            }
        };

        // Called when a thinking delta is received (Claude 3.7 only)
        $onThinking = function ($delta) use ($showThinking) {
            // Display thinking content in gray
            if ($showThinking) {
                echo $this->colors['gray'].$delta.$this->colors['reset'];
            }
        };

        // Called when thinking starts
        $onThinkingStart = function () use ($showThinking) {
            if ($showThinking) {
                $this->thinkingBlockCount++;
                $this->line('');
                $this->line($this->colors['purple'].'🧠 AI Thinking Block #'.$this->thinkingBlockCount.' Started...'.$this->colors['reset']);
            }
        };

        // Called when thinking ends
        $onThinkingEnd = function ($content = null) use ($showThinking) {
            if ($showThinking) {
                $this->line('');
                $this->line($this->colors['purple'].'🧠 AI Thinking Block #'.$this->thinkingBlockCount.' Completed'.$this->colors['reset']);
            }
        };

        // Called for each progress chunk (streamed response)
        $onProgress = function ($chunk, $translatedItems) use ($showXml) {
            if ($showXml) {
                $this->rawXmlResponse .= $chunk;
            }
        };

        try {
            $translatedItems = $provider
                ->setOnTranslated($onTranslated)
                ->setOnThinking($onThinking)
                ->setOnProgress($onProgress)
                ->setOnThinkingStart($onThinkingStart)
                ->setOnThinkingEnd($onThinkingEnd)
                ->setOnTokenUsage($onTokenUsage)
                ->translate();

            // Show raw XML response if requested
            if ($showXml) {
                $this->line("\n".str_repeat('─', 80));
                $this->line("\033[1;44;37m Raw XML Response \033[0m");
                $this->line($this->rawXmlResponse);
            }

            // 토큰 사용량은 콜백에서 직접 출력하므로 여기서는 출력하지 않음

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());
            if ($debug) {
                Log::error($e);
            }

            return 1;
        }
    }
}
