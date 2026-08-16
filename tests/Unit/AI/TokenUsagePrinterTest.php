<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function openRouterPricingFixture(): array
{
    return [
        'data' => [
            [
                'id' => 'anthropic/claude-opus-5',
                'canonical_slug' => 'anthropic/claude-opus-5-20260723',
                'name' => 'Claude Opus 5',
                'context_length' => 1_048_576,
                'pricing' => [
                    'prompt' => '0.000005',
                    'completion' => '0.000025',
                    'input_cache_read' => '0.0000005',
                    'input_cache_write' => '0.00000625',
                ],
                'top_provider' => ['context_length' => 1_048_576, 'max_completion_tokens' => 128_000],
                'supported_parameters' => ['reasoning', 'max_tokens'],
            ],
            [
                'id' => 'openai/gpt-5.6-sol',
                'canonical_slug' => 'openai/gpt-5.6-sol-20260709',
                'name' => 'OpenAI: GPT-5.6 Sol',
                'context_length' => 1_048_576,
                'pricing' => [
                    'prompt' => '0.000005',
                    'completion' => '0.00003',
                    'input_cache_read' => '0.0000005',
                    'input_cache_write' => '0.00000625',
                    'overrides' => [
                        [
                            'min_prompt_tokens' => 272_000,
                            'prompt' => '0.00001',
                            'completion' => '0.000045',
                            'input_cache_read' => '0.000001',
                            'input_cache_write' => '0.0000125',
                        ],
                    ],
                ],
                'top_provider' => ['context_length' => 1_048_576, 'max_completion_tokens' => 128_000],
                'supported_parameters' => ['reasoning', 'max_tokens'],
            ],
            [
                'id' => 'google/gemini-3.7-flash',
                'canonical_slug' => 'google/gemini-3.7-flash-20260813',
                'name' => 'Google: Gemini 3.7 Flash',
                'context_length' => 1_048_576,
                'pricing' => [
                    'prompt' => '0.000000375',
                    'completion' => '0.000001875',
                    'input_cache_read' => '0.0000000375',
                    'input_cache_write' => '0.0000000208333333333333',
                ],
                'top_provider' => ['context_length' => 1_048_576, 'max_completion_tokens' => 65_536],
                'supported_parameters' => ['reasoning', 'max_tokens'],
            ],
        ],
    ];
}

function pricingCommand(): array
{
    $command = new class extends Command {};
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    return [$command, $output];
}

function pricingUsage(int $inputTokens = 100_000, int $outputTokens = 100_000): array
{
    return [
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => $inputTokens + $outputTokens,
    ];
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

test('uses OpenRouter pricing for OpenRouter and direct providers', function (string $provider, string $model, string $name, string $totalCost) {
    config()->set('ai-translator.ai.provider', $provider);
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(openRouterPricingFixture()),
    ]);
    [$command, $output] = pricingCommand();

    (new TokenUsagePrinter($model))->printCostEstimation($command, pricingUsage());

    expect($output->fetch())
        ->toContain("Cost Estimation ({$name}")
        ->toContain("Total Cost: \${$totalCost}");
    Http::assertSentCount(1);
})->with([
    ['openrouter', 'anthropic/claude-opus-5', 'Claude Opus 5', '3.000000'],
    ['openai', 'gpt-5.6-sol', 'OpenAI: GPT-5.6 Sol', '3.500000'],
    ['anthropic', 'claude-opus-5', 'Claude Opus 5', '3.000000'],
    ['gemini', 'gemini-3.7-flash', 'Google: Gemini 3.7 Flash', '0.225000'],
]);

test('caches the OpenRouter catalog across full reports', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(openRouterPricingFixture()),
    ]);
    [$command] = pricingCommand();

    (new TokenUsagePrinter('openai/gpt-5.6-sol'))->printFullReport($command, pricingUsage());
    (new TokenUsagePrinter('openai/gpt-5.6-sol'))->printFullReport($command, pricingUsage());

    Http::assertSentCount(1);
});

test('applies OpenRouter prompt-token pricing overrides', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(openRouterPricingFixture()),
    ]);
    [$command, $output] = pricingCommand();

    (new TokenUsagePrinter('gpt-5.6-sol'))->printCostEstimation($command, pricingUsage(300_000, 100_000));

    expect($output->fetch())->toContain('Total Cost: $7.500000');
});

test('reports unavailable pricing without a model fallback', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response(['data' => []]),
    ]);
    [$command, $output] = pricingCommand();

    (new TokenUsagePrinter('unknown/model'))->printCostEstimation($command, pricingUsage());

    expect($output->fetch())
        ->toContain("Pricing for 'unknown/model' is not available from OpenRouter.")
        ->not->toContain('Total Cost:');
});

test('reports OpenRouter catalog failures without a model fallback', function () {
    config()->set('ai-translator.ai.provider', 'anthropic');
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([], 503),
    ]);
    [$command, $output] = pricingCommand();

    (new TokenUsagePrinter('claude-opus-5'))->printCostEstimation($command, pricingUsage());

    expect($output->fetch())
        ->toContain('OpenRouter pricing request failed with HTTP 503.')
        ->not->toContain('Total Cost:');
});
