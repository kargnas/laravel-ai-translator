<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;

/**
 * Command to test translation using the new TranslationBuilder
 */
class TestTranslateCommand extends Command
{
    protected $signature = 'ai-translator:test-translate
                          {source_language? : Source language code (uses config default if not specified)}
                          {target_language=ko : Target language code (ex: ko)}
                          {--text= : Text to translate}
                          {--rules=* : Additional rules}
                          {--extended-thinking : Use Extended Thinking feature (only supported for claude-3-7 models)}
                          {--debug : Enable debug mode with detailed logging}
                          {--show-xml : Show raw XML response in the output}';

    protected $description = 'Test translation using TranslationBuilder.';

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
        $sourceLanguage = $this->argument('source_language') ?: config('ai-translator.source_locale', 'en');
        $targetLanguage = $this->argument('target_language');
        $text = $this->option('text');
        $rulesList = $this->option('rules');
        $useExtendedThinking = $this->option('extended-thinking');
        $debug = $this->option('debug');
        $showXml = $this->option('show-xml');
        $showThinking = true; // Always show thinking content

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

        // Token usage tracking
        $tokenUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'total_tokens' => 0,
        ];

        // Build provider configuration
        $providerConfig = $this->getProviderConfig($useExtendedThinking);

        // Create TranslationBuilder instance
        $builder = TranslationBuilder::make()
            ->from($sourceLanguage)
            ->to($targetLanguage)
            ->withProviders(['default' => $providerConfig]);

        // Add additional rules if provided
        if (!empty($rulesList)) {
            $builder->withStyle('custom', implode("\n", $rulesList));
        }

        // Add progress callback
        $builder->onProgress(function($output) use ($showThinking, &$tokenUsage, $text) {
            if ($output->type === 'thinking_start' && $showThinking) {
                $this->thinkingBlockCount++;
                $this->line('');
                $this->line($this->colors['purple'].'🧠 AI Thinking Block #'.$this->thinkingBlockCount.' Started...'.$this->colors['reset']);
            } elseif ($output->type === 'thinking' && $showThinking) {
                echo $this->colors['gray'].$output->value.$this->colors['reset'];
            } elseif ($output->type === 'thinking_end' && $showThinking) {
                $this->line('');
                $this->line($this->colors['purple'].'🧠 AI Thinking Block #'.$this->thinkingBlockCount.' Completed'.$this->colors['reset']);
                $this->line('');
            } elseif ($output->type === 'translation_start') {
                $this->line("\n".str_repeat('─', 80));
                $this->line("\033[1;44;37m Translation Start \033[0m");
                $this->line("\033[90m원본:\033[0m ".substr($text, 0, 100).
                    (strlen($text) > 100 ? '...' : ''));
            } elseif ($output->type === 'token_usage' && isset($output->data)) {
                // Update token usage
                $usage = $output->data;
                $tokenUsage['input_tokens'] = $usage['input_tokens'] ?? $tokenUsage['input_tokens'];
                $tokenUsage['output_tokens'] = $usage['output_tokens'] ?? $tokenUsage['output_tokens'];
                $tokenUsage['cache_creation_input_tokens'] = $usage['cache_creation_input_tokens'] ?? $tokenUsage['cache_creation_input_tokens'];
                $tokenUsage['cache_read_input_tokens'] = $usage['cache_read_input_tokens'] ?? $tokenUsage['cache_read_input_tokens'];
                $tokenUsage['total_tokens'] = $usage['total_tokens'] ?? $tokenUsage['total_tokens'];

                // Display token usage
                $this->output->write("\033[2K\r");
                $this->output->write(
                    '<fg=magenta>Tokens:</> '.
                    "Input: <fg=green>{$tokenUsage['input_tokens']}</> | ".
                    "Output: <fg=green>{$tokenUsage['output_tokens']}</> | ".
                    "Cache created: <fg=blue>{$tokenUsage['cache_creation_input_tokens']}</> | ".
                    "Cache read: <fg=blue>{$tokenUsage['cache_read_input_tokens']}</> | ".
                    "Total: <fg=yellow>{$tokenUsage['total_tokens']}</>"
                );
            } elseif ($output->type === 'raw_xml' && $showXml) {
                $this->rawXmlResponse = $output->value;
            }
        });

        try {
            // Execute translation
            $result = $builder->translate(['test' => $text]);

            // Get translations
            $translations = $result->getTranslations();
            
            if (!empty($translations['test'])) {
                $this->line("\033[1;32mTranslation:\033[0m \033[1m".substr($translations['test'], 0, 100).
                    (strlen($translations['test']) > 100 ? '...' : '')."\033[0m");
                
                // Full translation if truncated
                if (strlen($translations['test']) > 100) {
                    $this->line("\n\033[1;32mFull Translation:\033[0m");
                    $this->line($translations['test']);
                }
            }

            // Display XML if requested
            if ($showXml && !empty($this->rawXmlResponse)) {
                $this->line("\n".str_repeat('─', 80));
                $this->line($this->colors['blue'].'📄 RAW XML RESPONSE'.$this->colors['reset']);
                $this->line(str_repeat('─', 80));
                $this->line($this->rawXmlResponse);
                $this->line(str_repeat('─', 80));
            }

            // Display final token usage
            $this->output->writeln('');
            $this->line("\n".str_repeat('─', 80));
            $this->line($this->colors['blue'].'📊 FINAL TOKEN USAGE'.$this->colors['reset']);
            $this->line(str_repeat('─', 80));
            
            $finalTokenUsage = $result->getTokenUsage();
            if (!empty($finalTokenUsage)) {
                $printer = new TokenUsagePrinter($this->output);
                $printer->printTokenUsage($finalTokenUsage);
            }

            $this->line(str_repeat('─', 80));
            $this->line($this->colors['green'].'✅ Translation completed successfully!'.$this->colors['reset']);

        } catch (\Exception $e) {
            $this->error('Translation failed: ' . $e->getMessage());
            if ($debug) {
                $this->error($e->getTraceAsString());
            }
            Log::error('Test translation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Get provider configuration
     */
    protected function getProviderConfig(bool $useExtendedThinking = false): array
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
            'thinking' => $useExtendedThinking || config('ai-translator.ai.use_extended_thinking', false),
            'retries' => config('ai-translator.ai.retries', 1),
            'max_tokens' => config('ai-translator.ai.max_tokens', 4096),
        ];
    }
}