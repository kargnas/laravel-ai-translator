<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;

class TranslateFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-file
                           {file : Path to the PHP file to translate}
                           {--source-language= : Source language code (uses config default if not specified)}
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
        'line_clear' => "\033[2K\r",
    ];

    public function handle()
    {
        // 전역 변수 설정 (실시간 결과 저장용)
        $GLOBALS['instant_results'] = [];

        try {
            $filePath = $this->argument('file');
            $sourceLanguage = $this->option('source-language') ?: config('ai-translator.source_locale', 'en');
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
            if (! file_exists($filePath)) {
                $this->error("File not found: {$filePath}");

                return 1;
            }

            // 파일 로드 (PHP 배열 반환 형식 필요)
            $strings = include $filePath;
            if (! is_array($strings)) {
                $this->error('File must return an array of strings');

                return 1;
            }

            $this->info("Starting translation of file: {$filePath}");
            $this->info("Source language: {$sourceLanguage}");
            $this->info("Target language: {$targetLanguage}");
            $this->info('Total strings: '.count($strings));

            config(['ai-translator.ai.model' => 'claude-3-7-sonnet-latest']);
            config(['ai-translator.ai.max_tokens' => 64000]);
            // config(['ai-translator.ai.model' => 'claude-3-5-sonnet-latest']);
            // config(['ai-translator.ai.max_tokens' => 8192]);
            config(['ai-translator.ai.use_extended_thinking' => false]);
            config(['ai-translator.ai.disable_stream' => false]);

            // Get global translation context
            $contextProvider = new TranslationContextProvider;
            $maxContextItems = (int) $this->option('max-context-items') ?: 100;
            $globalContext = $contextProvider->getGlobalTranslationContext(
                $sourceLanguage,
                $targetLanguage,
                $filePath,
                $maxContextItems
            );

            $this->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Translation Context '.$this->colors['reset']);
            $this->line(' - Context files: '.count($globalContext));
            $this->line(' - Total context items: '.collect($globalContext)->map(fn ($items) => count($items))->sum());

            // Translation configuration display
            $this->line("\n".str_repeat('─', 80));
            $this->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Translation Configuration '.$this->colors['reset']);

            // Source Language
            $this->line($this->colors['yellow'].'Source'.$this->colors['reset'].': '.
                $this->colors['green'].$sourceLanguage.
                $this->colors['reset']);

            // Target Language
            $this->line($this->colors['yellow'].'Target'.$this->colors['reset'].': '.
                $this->colors['green'].$targetLanguage.
                $this->colors['reset']);

            // Additional Rules
            $this->line($this->colors['yellow'].'Rules'.$this->colors['reset'].': '.
                $this->colors['purple'].count($rules).' rules'.
                $this->colors['reset']);

            // Display rules if present
            if (! empty($rules)) {
                $this->line($this->colors['gray'].'Rule Preview:'.$this->colors['reset']);
                foreach (array_slice($rules, 0, 3) as $index => $rule) {
                    $shortRule = strlen($rule) > 100 ? substr($rule, 0, 97).'...' : $rule;
                    $this->line($this->colors['blue'].' '.($index + 1).'. '.
                        $this->colors['reset'].$shortRule);
                }
                if (count($rules) > 3) {
                    $this->line($this->colors['gray'].' ... and '.
                        (count($rules) - 3).' more rules'.
                        $this->colors['reset']);
                }
            }

            $this->line(str_repeat('─', 80)."\n");

            // 총 항목 수
            $totalItems = count($strings);
            $processedCount = 0;
            $results = [];

            // 토큰 사용량 추적을 위한 변수
            $tokenUsage = [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'total_tokens' => 0,
            ];

            // Provider configuration
            $providerConfig = $this->getProviderConfig();

            // Create TranslationBuilder instance
            $builder = TranslationBuilder::make()
                ->from($sourceLanguage)
                ->to($targetLanguage)
                ->withProviders(['default' => $providerConfig]);

            // Add custom rules if provided
            if (!empty($rules)) {
                $builder->withStyle('custom', implode("\n", $rules));
            }

            // Add context as metadata
            $builder->option('global_context', $globalContext);
            $builder->option('filename', basename($filePath));

            // Add progress callback
            $builder->onProgress(function($output) use (&$tokenUsage, &$processedCount, $totalItems, $strings, $showAiResponse) {
                if ($output->type === 'thinking_start') {
                    $this->thinkingBlockCount++;
                    $this->line('');
                    $this->line($this->colors['purple'].'🧠 AI Thinking Block #'.$this->thinkingBlockCount.' Started...'.$this->colors['reset']);
                } elseif ($output->type === 'thinking' && config('ai-translator.ai.use_extended_thinking', false)) {
                    echo $this->colors['gray'].$output->value.$this->colors['reset'];
                } elseif ($output->type === 'thinking_end') {
                    $this->line('');
                    $this->line($this->colors['purple'].'✓ Thinking completed'.$this->colors['reset']);
                    $this->line('');
                } elseif ($output->type === 'translation_start' && isset($output->data['key'])) {
                    $key = $output->data['key'];
                    $processedCount++;
                    
                    // Get original text
                    $originalText = '';
                    if (isset($strings[$key])) {
                        $originalText = is_array($strings[$key]) ?
                            ($strings[$key]['text'] ?? '') :
                            $strings[$key];
                    }
                    
                    $this->line("\n".str_repeat('─', 80));
                    $this->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold']." Translation Started {$processedCount}/{$totalItems} ".$this->colors['reset'].' '.$this->colors['yellow_bg'].$this->colors['black'].$this->colors['bold']." {$key} ".$this->colors['reset']);
                    $this->line($this->colors['gray'].'Source:'.$this->colors['reset'].' '.substr($originalText, 0, 100).
                        (strlen($originalText) > 100 ? '...' : ''));
                } elseif ($output->type === 'translation_complete' && isset($output->data['key'])) {
                    $key = $output->data['key'];
                    $translation = $output->data['translation'];
                    
                    $this->line($this->colors['green'].$this->colors['bold'].'Translation:'.$this->colors['reset'].' '.$this->colors['bold'].substr($translation, 0, 100).
                        (strlen($translation) > 100 ? '...' : '').$this->colors['reset']);
                } elseif ($output->type === 'token_usage' && isset($output->data)) {
                    // Update token usage
                    $usage = $output->data;
                    $tokenUsage['input_tokens'] = $usage['input_tokens'] ?? $tokenUsage['input_tokens'];
                    $tokenUsage['output_tokens'] = $usage['output_tokens'] ?? $tokenUsage['output_tokens'];
                    $tokenUsage['cache_creation_input_tokens'] = $usage['cache_creation_input_tokens'] ?? $tokenUsage['cache_creation_input_tokens'];
                    $tokenUsage['cache_read_input_tokens'] = $usage['cache_read_input_tokens'] ?? $tokenUsage['cache_read_input_tokens'];
                    $tokenUsage['total_tokens'] = $usage['total_tokens'] ?? $tokenUsage['total_tokens'];
                    
                    $this->updateTokenUsageDisplay($tokenUsage);
                } elseif ($output->type === 'raw' && $showAiResponse) {
                    $responsePreview = preg_replace('/[\n\r]+/', ' ', substr($output->value, -100));
                    $this->line($this->colors['line_clear'].$this->colors['purple'].'AI Response:'.$this->colors['reset'].' '.$responsePreview);
                }
            });

            // Execute translation
            $result = $builder->translate($strings);

            // Get translation results
            $results = $result->getTranslations();

            // Create translation result file
            $outputFilePath = pathinfo($filePath, PATHINFO_DIRNAME).'/'.
                pathinfo($filePath, PATHINFO_FILENAME).'-'.
                $targetLanguage.'.php';

            $fileContent = '<?php return '.var_export($results, true).';';
            file_put_contents($outputFilePath, $fileContent);

            // Display final token usage
            $this->line("\n".str_repeat('─', 80));
            $this->line($this->colors['blue'].'📊 FINAL TOKEN USAGE'.$this->colors['reset']);
            $this->line(str_repeat('─', 80));
            
            $finalTokenUsage = $result->getTokenUsage();
            if (!empty($finalTokenUsage)) {
                $printer = new TokenUsagePrinter($this->output);
                $printer->printTokenUsage($finalTokenUsage);
            }

            $this->info("\nTranslation completed. Output written to: {$outputFilePath}");

        } catch (\Exception $e) {
            $this->error('Translation error: '.$e->getMessage());

            if ($debug) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }

        return 0;
    }
    
    /**
     * Get provider configuration
     */
    protected function getProviderConfig(): array
    {
        $provider = config('ai-translator.ai.provider');
        $model = config('ai-translator.ai.model');
        $apiKey = config('ai-translator.ai.api_key');
        
        if (!$provider || !$model || !$apiKey) {
            throw new \Exception('AI provider configuration is incomplete. Please check your config/ai-translator.php file.');
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => $apiKey,
            'temperature' => config('ai-translator.ai.temperature', 0.3),
            'thinking' => config('ai-translator.ai.use_extended_thinking', false),
            'retries' => config('ai-translator.ai.retries', 1),
            'max_tokens' => config('ai-translator.ai.max_tokens', 4096),
        ];
    }

    /**
     * Display current token usage in real-time
     *
     * @param  array  $usage  Token usage information
     */
    protected function updateTokenUsageDisplay(array $usage): void
    {
        // Don't display if zero
        if ($usage['input_tokens'] == 0 && $usage['output_tokens'] == 0) {
            return;
        }

        // Save cursor position and clear previous line
        $this->output->write("\033[2K\r");

        // Display token usage
        $this->output->write(
            $this->colors['purple'].'Tokens: '.
            $this->colors['reset'].'Input: '.$this->colors['green'].$usage['input_tokens'].
            $this->colors['reset'].' | Output: '.$this->colors['green'].$usage['output_tokens'].
            $this->colors['reset'].' | Cache created: '.$this->colors['blue'].$usage['cache_creation_input_tokens'].
            $this->colors['reset'].' | Cache read: '.$this->colors['blue'].$usage['cache_read_input_tokens'].
            $this->colors['reset'].' | Total: '.$this->colors['yellow'].$usage['total_tokens'].
            $this->colors['reset']
        );
    }
}