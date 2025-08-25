<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Traits;

use Kargnas\LaravelAiTranslator\Support\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Results\TranslationResult;

trait TokenUsageTrait
{
    /**
     * Token usage tracking
     */
    protected array $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => 0,
    ];

    /**
     * Update token usage statistics
     */
    protected function updateTokenUsage(array $usage): void
    {
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;

        $this->tokenUsage['input_tokens'] += $inputTokens;
        $this->tokenUsage['output_tokens'] += $outputTokens;
        $this->tokenUsage['cache_creation_input_tokens'] += $cacheCreationTokens;
        $this->tokenUsage['cache_read_input_tokens'] += $cacheReadTokens;
        $this->tokenUsage['total_tokens'] =
            $this->tokenUsage['input_tokens'] +
            $this->tokenUsage['output_tokens'] +
            $this->tokenUsage['cache_creation_input_tokens'] +
            $this->tokenUsage['cache_read_input_tokens'];
    }

    /**
     * Display token usage information
     */
    protected function displayTokenUsage(array $usage): void
    {
        $this->line($this->colors['gray'].'    Tokens: '.
            'Input='.$this->colors['green'].($usage['input_tokens'] ?? 0).$this->colors['gray'].', '.
            'Output='.$this->colors['green'].($usage['output_tokens'] ?? 0).$this->colors['gray'].', '.
            'Total='.$this->colors['purple'].($usage['total_tokens'] ?? 0).$this->colors['gray'].
            $this->colors['reset']);
    }

    /**
     * Display total token usage summary
     */
    protected function displayTotalTokenUsage(): void
    {
        $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Total Token Usage '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'Input Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['input_tokens'].$this->colors['reset']);
        $this->line($this->colors['yellow'].'Output Tokens: '.$this->colors['reset'].$this->colors['green'].$this->tokenUsage['output_tokens'].$this->colors['reset']);
        $this->line($this->colors['yellow'].'Cache Created: '.$this->colors['reset'].$this->colors['blue'].$this->tokenUsage['cache_creation_input_tokens'].$this->colors['reset']);
        $this->line($this->colors['yellow'].'Cache Read: '.$this->colors['reset'].$this->colors['blue'].$this->tokenUsage['cache_read_input_tokens'].$this->colors['reset']);
        $this->line($this->colors['yellow'].'Total Tokens: '.$this->colors['reset'].$this->colors['bold'].$this->colors['purple'].$this->tokenUsage['total_tokens'].$this->colors['reset']);
    }

    /**
     * Display cost estimation
     */
    protected function displayCostEstimation(TranslationResult $result): void
    {
        $usage = $result->getTokenUsage();
        $model = config('ai-translator.ai.model');
        $printer = new TokenUsagePrinter($model);
        $printer->printTokenUsageSummary($this, $usage);
    }
}
