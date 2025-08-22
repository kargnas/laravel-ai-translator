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
        $totalTokens = $usage['total_tokens'] ?? ($inputTokens + $outputTokens);
        
        $command->line("\n" . str_repeat('─', 60));
        $command->line(" <fg=blue;bg=blue> Token Usage Summary </>");
        $command->line("<fg=yellow>Input Tokens:</> <fg=green>{$inputTokens}</>");
        $command->line("<fg=yellow>Output Tokens:</> <fg=green>{$outputTokens}</>");
        $command->line("<fg=yellow>Total Tokens:</> <fg=magenta>{$totalTokens}</>");
    }

    public function printCostEstimation(Command $command, array $usage): void
    {
        $rates = self::MODEL_RATES[$this->model];
        $inputCost = ($usage['input_tokens'] ?? 0) * $rates['input'] / 1_000_000;
        $outputCost = ($usage['output_tokens'] ?? 0) * $rates['output'] / 1_000_000;
        $totalCost = $inputCost + $outputCost;

        $command->line("\n" . str_repeat('─', 60));
        $command->line(" <fg=blue;bg=blue> Cost Estimation ({$rates['name']}) </>");
        $command->line("<fg=yellow>Total Cost:</> $" . number_format($totalCost, 6));
    }

    public function printFullReport(Command $command, array $usage): void
    {
        $this->printTokenUsageSummary($command, $usage);
        $this->printCostEstimation($command, $usage);
    }
}
