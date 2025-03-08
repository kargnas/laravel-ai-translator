<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

class TranslateFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-file
                           {file : PHP file with return array of strings}
                           {target_language=ko : Target language code (ex: ko)}
                           {source_language=en : Source language code (ex: en)}
                           {--rules=* : Additional rules}
                           {--debug : Enable debug mode}
                           {--show-ai-response : Show raw AI response during translation}';

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
        'reset' => "\033[0m"
    ];

    public function handle()
    {
        // 전역 변수 설정 (실시간 결과 저장용)
        $GLOBALS['instant_results'] = [];

        $filePath = $this->argument('file');
        $targetLanguage = $this->argument('target_language');
        $sourceLanguage = $this->argument('source_language');
        $rules = $this->option('rules');
        $debug = (bool) $this->option('debug');
        $showAiResponse = (bool) $this->option('show-ai-response');

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

        if ($debug) {
            $this->info('Debug mode enabled');
            config(['ai-translator.debug' => true]);
        }

        config(['ai-translator.ai.model' => 'claude-3-7-sonnet-latest']);
        config(['ai-translator.ai.max_tokens' => 64000]);
        // config(['ai-translator.ai.model' => 'claude-3-5-sonnet-latest']);
        // config(['ai-translator.ai.max_tokens' => 8192]);
        config(['ai-translator.ai.use_extended_thinking' => true]);
        config(['ai-translator.ai.disable_stream' => false]);

        try {
            // AIProvider 생성
            $provider = new AIProvider(
                filename: basename($filePath),
                strings: $strings,
                sourceLanguage: $sourceLanguage,
                targetLanguage: $targetLanguage,
                additionalRules: $rules,
            );

            // 총 항목 수
            $totalItems = count($strings);
            $results = [];

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

                        $this->line("\033[1;44;37m 번역시작 " . count($translatedItems) . "/{$totalItems} \033[0m \033[1;43;30m {$item->key} \033[0m");
                        $this->line("\033[90m원본:\033[0m " . substr($originalText, 0, 100) .
                            (strlen($originalText) > 100 ? '...' : ''));
                        break;

                    case TranslationStatus::COMPLETED:
                        $this->line("\033[1;32m번역:\033[0m \033[1m" . substr($item->translated, 0, 100) .
                            (strlen($item->translated) > 100 ? '...' : '') . "\033[0m");
                        break;
                }
            };

            // AI 응답 표시용 콜백
            $onProgress = function ($currentText, $translatedItems) use ($showAiResponse) {
                if ($showAiResponse) {
                    $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($currentText, -100));
                    $this->line("\033[2K\r\033[35mAI응답:\033[0m " . $responsePreview);
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
            $translatedItems = $provider->translate($onTranslated, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd);

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
}
