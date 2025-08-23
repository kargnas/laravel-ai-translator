<?php

namespace Kargnas\LaravelAiTranslator\Support\Printer;

use Illuminate\Console\Command;

class TokenUsagePrinter
{
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

    private const MODEL_RATES = [
        'claude-opus-4-1-20250805' => ['name' => 'Claude Opus 4.1', 'input' => 15.0, 'output' => 75.0],
        'claude-opus-4-20250514' => ['name' => 'Claude Opus 4', 'input' => 15.0, 'output' => 75.0],
        'claude-sonnet-4-20250514' => ['name' => 'Claude Sonnet 4', 'input' => 3.0, 'output' => 15.0],
        'claude-3-5-sonnet-20241022' => ['name' => 'Claude 3.5 Sonnet', 'input' => 3.0, 'output' => 15.0],
        'claude-3-5-haiku-20241022' => ['name' => 'Claude 3.5 Haiku', 'input' => 0.80, 'output' => 4.0],
        'claude-3-7-sonnet-latest' => ['name' => 'Claude 3.7 Sonnet', 'input' => 3.0, 'output' => 15.0],
    ];

    private string $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model ?? self::DEFAULT_MODEL;
        
        if (!isset(self::MODEL_RATES[$this->model])) {
            $this->model = self::DEFAULT_MODEL;
        }
    }

    public function printTokenUsageSummary(Command $command, array $usage): void
    {
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? ($inputTokens + $outputTokens);
        
        $command->line("\n" . str_repeat('─', 60));
        $command->line(" <fg=blue;bg=blue> Token Usage Summary </>");
        $command->line("<fg=yellow>Input Tokens:</> <fg=green>{$inputTokens}</>");
        $command->line("<fg=yellow>Output Tokens:</> <fg=green>{$outputTokens}</>");
        
        // Show cache tokens if present
        if ($cacheCreationTokens > 0 || $cacheReadTokens > 0) {
            $command->line("<fg=yellow>Cache Creation:</> <fg=cyan>{$cacheCreationTokens}</> <fg=gray>(25% cost)</>");
            $command->line("<fg=yellow>Cache Read:</> <fg=cyan>{$cacheReadTokens}</> <fg=gray>(10% cost)</>");
            
            // Calculate cache savings
            $normalCost = $cacheReadTokens;
            $cachedCost = $cacheReadTokens * 0.1;
            $savedTokens = $normalCost - $cachedCost;
            $savingsPercent = $cacheReadTokens > 0 ? round(($savedTokens / $normalCost) * 100) : 0;
            
            if ($savingsPercent > 0) {
                $command->line("<fg=yellow>Cache Savings:</> <fg=green>{$savingsPercent}%</> on cached tokens");
            }
        }
        
        $command->line("<fg=yellow>Total Tokens:</> <fg=magenta>{$totalTokens}</>");
    }

    public function printCostEstimation(Command $command, array $usage): void
    {
        $rates = self::MODEL_RATES[$this->model];
        
        // Regular token costs
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;
        
        // Calculate costs with cache pricing
        // Regular input tokens (excluding cache tokens)
        $regularInputTokens = max(0, $inputTokens - $cacheCreationTokens - $cacheReadTokens);
        $inputCost = $regularInputTokens * $rates['input'] / 1_000_000;
        
        // Cache creation costs 25% of regular price
        $cacheCreationCost = $cacheCreationTokens * $rates['input'] * 0.25 / 1_000_000;
        
        // Cache read costs 10% of regular price
        $cacheReadCost = $cacheReadTokens * $rates['input'] * 0.10 / 1_000_000;
        
        // Output cost remains the same
        $outputCost = $outputTokens * $rates['output'] / 1_000_000;
        
        // Total cost
        $totalCost = $inputCost + $cacheCreationCost + $cacheReadCost + $outputCost;
        
        // Calculate savings from caching
        $withoutCachesCost = ($inputTokens * $rates['input'] + $outputTokens * $rates['output']) / 1_000_000;
        $savedAmount = $withoutCachesCost - $totalCost;

        $command->line("\n" . str_repeat('─', 60));
        $command->line(" <fg=blue;bg=blue> Cost Estimation ({$rates['name']}) </>");
        
        // Show breakdown if cache tokens present
        if ($cacheCreationTokens > 0 || $cacheReadTokens > 0) {
            $command->line("<fg=gray>Regular Input:</> $" . number_format($inputCost, 6));
            if ($cacheCreationTokens > 0) {
                $command->line("<fg=gray>Cache Creation (25%):</> $" . number_format($cacheCreationCost, 6));
            }
            if ($cacheReadTokens > 0) {
                $command->line("<fg=gray>Cache Read (10%):</> $" . number_format($cacheReadCost, 6));
            }
            $command->line("<fg=gray>Output:</> $" . number_format($outputCost, 6));
            $command->line(str_repeat('─', 30));
        }
        
        $command->line("<fg=yellow>Total Cost:</> $" . number_format($totalCost, 6));
        
        // Show savings if applicable
        if ($savedAmount > 0.000001) {
            $savingsPercent = round(($savedAmount / $withoutCachesCost) * 100, 1);
            $command->line("<fg=green>Saved from caching:</> $" . number_format($savedAmount, 6) . " ({$savingsPercent}%)");
        }
    }

    public function printFullReport(Command $command, array $usage): void
    {
        $this->printTokenUsageSummary($command, $usage);
        $this->printCostEstimation($command, $usage);
    }
}
