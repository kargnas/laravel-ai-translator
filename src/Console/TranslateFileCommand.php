<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class TranslateFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-file
                           {file : Path to the PHP file to translate}
                           {--source-language=en : Source language code (ex: en)}
                           {--target-language=ko : Target language code (ex: ko)}
                           {--rules=* : Additional rules}
                           {--debug : Enable debug mode}
                           {--show-ai-response : Show raw AI response during translation}
                           {--max-context-items=100 : Maximum number of context items}';

    protected $description = 'Translate a specific PHP file with an array of strings';

    // Thinking block count
    private $thinkingBlockCount = 0;

    // Console color codes
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
        'line_clear' => "\033[2K\r"
    ];

    public function handle()
    {
        // 전역 변수 설정 (실시간 결과 저장용)
        $GLOBALS['instant_results'] = [];

        try {
            $filePath = $this->argument('file');
            $sourceLanguage = $this->option('source-language');
            $targetLanguage = $this->option('target-language');
            $rules = $this->option('rules') ?: [];
            $showAiResponse = $this->option('show-ai-response');
            $debug = $this->option('debug');

            // 디버그 모드 설정
            if ($debug) {
                config(['app.debug' => true]);
                config(['ai-translator.debug' => true]);
            }

            // 파일 존재 확인
            if (!file_exists($filePath)) {
                $this->error("File not found: {$filePath}");
                return 1;
            }

            // 파일 로드 (PHP 배열 반환 형식 필요)
            $strings = include $filePath;
            if (!is_array($strings)) {
                $this->error('File must return an array of strings');
                return 1;
            }

            $this->info("Starting translation of file: {$filePath}");
            $this->info("Source language: {$sourceLanguage}");
            $this->info("Target language: {$targetLanguage}");
            $this->info('Total strings: ' . count($strings));

            config(['ai-translator.ai.model' => 'claude-3-7-sonnet-latest']);
            config(['ai-translator.ai.max_tokens' => 64000]);
            // config(['ai-translator.ai.model' => 'claude-3-5-sonnet-latest']);
            // config(['ai-translator.ai.max_tokens' => 8192]);
            config(['ai-translator.ai.use_extended_thinking' => false]);
            config(['ai-translator.ai.disable_stream' => false]);

            \Log::info("TranslateFileCommand: Starting translation with source language = {$sourceLanguage}, target language = {$targetLanguage}, additional rules = " . json_encode($rules));

            // 전역 번역 컨텍스트 가져오기
            $contextProvider = new TranslationContextProvider();
            $maxContextItems = (int) $this->option('max-context-items') ?: 100;
            $globalContext = $contextProvider->getGlobalTranslationContext(
                $sourceLanguage,
                $targetLanguage,
                $filePath,
                $maxContextItems
            );

            $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Translation Context " . $this->colors['reset']);
            $this->line(" - Context files: " . count($globalContext));
            $this->line(" - Total context items: " . collect($globalContext)->map(fn($items) => count($items))->sum());

            // AIProvider 생성
            $provider = new AIProvider(
                filename: basename($filePath),
                strings: $strings,
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
                additionalRules: $rules,
                globalTranslationContext: $globalContext
            );

            // 번역 시작 정보. sourceLanguageObj, targetLanguageObj, 총 추가 규칙 수등 표현
            $this->line("\n" . str_repeat('─', 80));
            $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Translation Configuration " . $this->colors['reset']);

            // Source Language
            $this->line($this->colors['yellow'] . "Source" . $this->colors['reset'] . ": " .
                $this->colors['green'] . $provider->sourceLanguageObj->name .
                $this->colors['gray'] . " (" . $provider->sourceLanguageObj->code . ")" .
                $this->colors['reset']);

            // Target Language
            $this->line($this->colors['yellow'] . "Target" . $this->colors['reset'] . ": " .
                $this->colors['green'] . $provider->targetLanguageObj->name .
                $this->colors['gray'] . " (" . $provider->targetLanguageObj->code . ")" .
                $this->colors['reset']);

            // Additional Rules
            $this->line($this->colors['yellow'] . "Rules" . $this->colors['reset'] . ": " .
                $this->colors['purple'] . count($provider->additionalRules) . " rules" .
                $this->colors['reset']);

            // Display rules if present
            if (!empty($provider->additionalRules)) {
                $this->line($this->colors['gray'] . "Rule Preview:" . $this->colors['reset']);
                foreach (array_slice($provider->additionalRules, 0, 3) as $index => $rule) {
                    $shortRule = strlen($rule) > 100 ? substr($rule, 0, 97) . '...' : $rule;
                    $this->line($this->colors['blue'] . " " . ($index + 1) . ". " .
                        $this->colors['reset'] . $shortRule);
                }
                if (count($provider->additionalRules) > 3) {
                    $this->line($this->colors['gray'] . " ... and " .
                        (count($provider->additionalRules) - 3) . " more rules" .
                        $this->colors['reset']);
                }
            }

            $this->line(str_repeat('─', 80) . "\n");

            // 총 항목 수
            $totalItems = count($strings);
            $results = [];

            // 토큰 사용량 추적을 위한 변수
            $tokenUsage = [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'total_tokens' => 0
            ];

            // 토큰 사용량 업데이트 콜백
            $onTokenUsage = function (array $usage) use ($provider) {
                $this->updateTokenUsageDisplay($usage);

                // 마지막 토큰 사용량 정보는 바로 출력
                if (isset($usage['final']) && $usage['final']) {
                    $printer = new TokenUsagePrinter($provider->getModel());
                    $printer->printFullReport($this, $usage);
                }
            };

            // 번역 완료 콜백
            $onTranslated = function (LocalizedString $item, string $status, array $translatedItems) use ($strings, $totalItems) {
                // 원본 텍스트 가져오기
                $originalText = '';
                if (isset($strings[$item->key])) {
                    $originalText = is_array($strings[$item->key]) ?
                        ($strings[$item->key]['text'] ?? '') :
                        $strings[$item->key];
                }

                switch ($status) {
                    case TranslationStatus::STARTED:
                        $this->line("\n" . str_repeat('─', 80));

                        $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " 번역시작 " . count($translatedItems) . "/{$totalItems} " . $this->colors['reset'] . " " . $this->colors['yellow_bg'] . $this->colors['black'] . $this->colors['bold'] . " {$item->key} " . $this->colors['reset']);
                        $this->line($this->colors['gray'] . "원본:" . $this->colors['reset'] . " " . substr($originalText, 0, 100) .
                            (strlen($originalText) > 100 ? '...' : ''));
                        break;

                    case TranslationStatus::COMPLETED:
                        $this->line($this->colors['green'] . $this->colors['bold'] . "번역:" . $this->colors['reset'] . " " . $this->colors['bold'] . substr($item->translated, 0, 100) .
                            (strlen($item->translated) > 100 ? '...' : '') . $this->colors['reset']);
                        if ($item->comment) {
                            $this->line($this->colors['gray'] . "주석:" . $this->colors['reset'] . " " . $item->comment);
                        }
                        break;
                }
            };

            // AI 응답 표시용 콜백
            $onProgress = function ($currentText, $translatedItems) use ($showAiResponse) {
                if ($showAiResponse) {
                    $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($currentText, -100));
                    $this->line($this->colors['line_clear'] . $this->colors['purple'] . "AI응답:" . $this->colors['reset'] . " " . $responsePreview);
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

            // 번역 실행
            $translatedItems = $provider
                ->setOnTranslated($onTranslated)
                ->setOnThinking($onThinking)
                ->setOnProgress($onProgress)
                ->setOnThinkingStart($onThinkingStart)
                ->setOnThinkingEnd($onThinkingEnd)
                ->setOnTokenUsage($onTokenUsage)
                ->translate();

            // 번역 결과를 배열로 변환
            $results = [];
            foreach ($translatedItems as $item) {
                $results[$item->key] = $item->translated;
            }

            // 번역 결과 파일 생성
            $outputFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' .
                pathinfo($filePath, PATHINFO_FILENAME) . '-' .
                $targetLanguage . '.php';

            $fileContent = '<?php return ' . var_export($results, true) . ';';
            file_put_contents($outputFilePath, $fileContent);

            $this->info("\nTranslation completed. Output written to: {$outputFilePath}");

        } catch (\Exception $e) {
            $this->error('Translation error: ' . $e->getMessage());

            if ($debug) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }

        return 0;
    }

    /**
     * 현재 토큰 사용량을 실시간으로 출력합니다.
     * 
     * @param array $usage 토큰 사용량 정보
     */
    protected function updateTokenUsageDisplay(array $usage): void
    {
        // 0이면 표시하지 않음
        if ($usage['input_tokens'] == 0 && $usage['output_tokens'] == 0) {
            return;
        }

        // 현재 커서 위치 저장 및 이전 라인 지우기
        $this->output->write("\033[2K\r");

        // 토큰 사용량 표시
        $this->output->write(
            $this->colors['purple'] . "Tokens: " .
            $this->colors['reset'] . "Input: " . $this->colors['green'] . $usage['input_tokens'] .
            $this->colors['reset'] . " | Output: " . $this->colors['green'] . $usage['output_tokens'] .
            $this->colors['reset'] . " | Cache created: " . $this->colors['blue'] . $usage['cache_creation_input_tokens'] .
            $this->colors['reset'] . " | Cache read: " . $this->colors['blue'] . $usage['cache_read_input_tokens'] .
            $this->colors['reset'] . " | Total: " . $this->colors['yellow'] . $usage['total_tokens'] .
            $this->colors['reset']
        );
    }
}
