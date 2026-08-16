<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('reports current frontier model pricing', function (string $model, string $name, string $totalCost) {
    $command = new class extends Command {};
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    (new TokenUsagePrinter($model))->printCostEstimation($command, [
        'input_tokens' => 100_000,
        'output_tokens' => 100_000,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => 200_000,
    ]);

    expect($output->fetch())
        ->toContain("Cost Estimation ({$name})")
        ->toContain("Total Cost: \${$totalCost}");
})->with([
    ['anthropic/claude-opus-5', 'Claude Opus 5', '3.000000'],
    ['openai/gpt-5.6-sol', 'GPT-5.6 Sol', '3.500000'],
    ['google/gemini-3.7-flash', 'Gemini 3.7 Flash', '0.225000'],
]);
