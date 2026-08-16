<?php

namespace Kargnas\LaravelAiTranslator\AI\Printer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * 토큰 사용량 및 비용 계산을 출력하는 유틸리티 클래스
 */
class TokenUsagePrinter
{
    protected const OPENROUTER_MODELS_URL = 'https://openrouter.ai/api/v1/models';

    protected const PRICING_CACHE_KEY = 'laravel-ai-translator.openrouter-model-pricing';

    protected const PRICING_CACHE_TTL_SECONDS = 21_600;

    protected const COMPARISON_MODELS = [
        'anthropic/claude-opus-5',
        'openai/gpt-5.6-sol',
        'google/gemini-3.7-flash',
    ];

    /**
     * 사용자 정의 색상 코드
     */
    protected array $colors = [
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

    protected ?string $currentModel;

    protected string $provider;

    protected ?array $openRouterModels = null;

    protected ?RuntimeException $pricingError = null;

    public function __construct(?string $model = null, ?string $provider = null)
    {
        $this->currentModel = $model;
        $this->provider = $provider ?? (string) config('ai-translator.ai.provider', 'openrouter');
    }

    public function setModel(string $model): self
    {
        $this->currentModel = $model;

        return $this;
    }

    protected function getOpenRouterModelId(string $model): string
    {
        if (str_contains($model, '/')) {
            return $model;
        }

        return match ($this->provider) {
            'openai' => "openai/{$model}",
            'anthropic' => "anthropic/{$model}",
            'gemini' => "google/{$model}",
            default => $model,
        };
    }

    protected function getOpenRouterModels(): array
    {
        if ($this->pricingError !== null) {
            throw $this->pricingError;
        }

        if ($this->openRouterModels !== null) {
            return $this->openRouterModels;
        }

        try {
            // The catalog is several megabytes, so cache it instead of downloading it for every report.
            $models = Cache::remember(
                self::PRICING_CACHE_KEY,
                self::PRICING_CACHE_TTL_SECONDS,
                function (): array {
                    // Never forward a direct-provider API key to the public OpenRouter catalog.
                    $response = Http::acceptJson()
                        ->timeout(10)
                        ->get(self::OPENROUTER_MODELS_URL);

                    if (! $response->successful()) {
                        throw new RuntimeException("OpenRouter pricing request failed with HTTP {$response->status()}.");
                    }

                    $models = $response->json('data');
                    if (! is_array($models)) {
                        throw new RuntimeException('OpenRouter pricing response did not contain a model catalog.');
                    }

                    return $models;
                }
            );

            if (! is_array($models)) {
                throw new RuntimeException('The cached OpenRouter model catalog is invalid.');
            }

            return $this->openRouterModels = $models;
        } catch (RuntimeException $exception) {
            $this->pricingError = $exception;

            throw $exception;
        } catch (Throwable $exception) {
            $this->pricingError = new RuntimeException(
                "OpenRouter pricing request failed: {$exception->getMessage()}",
                0,
                $exception
            );

            throw $this->pricingError;
        }
    }

    protected function getModelRates(int $promptTokens, ?string $model = null): array
    {
        $requestedModel = $model ?? $this->currentModel;
        if ($requestedModel === null || $requestedModel === '') {
            throw new RuntimeException('A model is required to load pricing from OpenRouter.');
        }

        $openRouterModelId = $this->getOpenRouterModelId($requestedModel);
        $modelData = null;

        foreach ($this->getOpenRouterModels() as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $openRouterModelId) {
                $modelData = $candidate;
                break;
            }
        }

        if ($modelData === null) {
            throw new RuntimeException("Pricing for '{$requestedModel}' is not available from OpenRouter.");
        }

        $pricing = $modelData['pricing'] ?? null;
        if (! is_array($pricing)) {
            throw new RuntimeException("Pricing for '{$requestedModel}' is not available from OpenRouter.");
        }

        $pricing = $this->applyPricingOverride($pricing, $promptTokens);

        return [
            'id' => $openRouterModelId,
            'name' => is_string($modelData['name'] ?? null) ? $modelData['name'] : $openRouterModelId,
            'input' => $this->requiredPrice($pricing, 'prompt', $requestedModel),
            'output' => $this->requiredPrice($pricing, 'completion', $requestedModel),
            'cache_write' => $this->optionalPrice($pricing, 'input_cache_write'),
            'cache_read' => $this->optionalPrice($pricing, 'input_cache_read'),
        ];
    }

    protected function applyPricingOverride(array $pricing, int $promptTokens): array
    {
        $overrides = $pricing['overrides'] ?? [];
        if (! is_array($overrides)) {
            return $pricing;
        }

        usort($overrides, function ($left, $right): int {
            return ((int) ($left['min_prompt_tokens'] ?? 0)) <=> ((int) ($right['min_prompt_tokens'] ?? 0));
        });

        foreach ($overrides as $override) {
            if (! is_array($override)) {
                continue;
            }

            $minimum = (int) ($override['min_prompt_tokens'] ?? 0);
            $maximum = isset($override['max_prompt_tokens']) ? (int) $override['max_prompt_tokens'] : null;

            if ($promptTokens < $minimum || ($maximum !== null && $promptTokens > $maximum)) {
                continue;
            }

            $pricing = array_replace($pricing, $override);
        }

        return $pricing;
    }

    protected function requiredPrice(array $pricing, string $field, string $model): float
    {
        $price = $pricing[$field] ?? null;
        if (! is_numeric($price)) {
            throw new RuntimeException("Pricing field '{$field}' for '{$model}' is not available from OpenRouter.");
        }

        return (float) $price;
    }

    protected function optionalPrice(array $pricing, string $field): ?float
    {
        $price = $pricing[$field] ?? null;

        return is_numeric($price) ? (float) $price : null;
    }

    protected function getPromptTokenCount(array $usage): int
    {
        return (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0);
    }

    protected function calculateCosts(array $rates, array $usage): array
    {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);

        if ($cacheCreationTokens > 0 && $rates['cache_write'] === null) {
            throw new RuntimeException("Cache-write pricing for '{$rates['id']}' is not available from OpenRouter.");
        }

        if ($cacheReadTokens > 0 && $rates['cache_read'] === null) {
            throw new RuntimeException("Cache-read pricing for '{$rates['id']}' is not available from OpenRouter.");
        }

        $inputCost = $inputTokens * $rates['input'];
        $outputCost = $outputTokens * $rates['output'];
        $cacheCreationCost = $cacheCreationTokens * ($rates['cache_write'] ?? 0);
        $cacheReadCost = $cacheReadTokens * ($rates['cache_read'] ?? 0);
        $noCacheTotalCost = (($inputTokens + $cacheCreationTokens + $cacheReadTokens) * $rates['input']) + $outputCost;
        $totalCost = $inputCost + $outputCost + $cacheCreationCost + $cacheReadCost;
        $savedCost = $noCacheTotalCost - $totalCost;

        return [
            'input' => $inputCost,
            'output' => $outputCost,
            'cache_write' => $cacheCreationCost,
            'cache_read' => $cacheReadCost,
            'total' => $totalCost,
            'no_cache_total' => $noCacheTotalCost,
            'saved' => $savedCost,
            'saved_percentage' => $noCacheTotalCost > 0 ? ($savedCost / $noCacheTotalCost) * 100 : 0,
        ];
    }

    protected function formatRate(?float $rate): string
    {
        if ($rate === null) {
            return 'Not listed';
        }

        $perMillion = rtrim(rtrim(number_format($rate * 1_000_000, 6, '.', ''), '0'), '.');

        return "\${$perMillion} per million tokens";
    }

    /**
     * 토큰 사용량 요약을 출력
     */
    public function printTokenUsageSummary(Command $command, array $usage): void
    {
        $command->line("\n".str_repeat('─', 80));
        $command->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Token Usage Summary '.$this->colors['reset']);
        $command->line($this->colors['yellow'].'Input Tokens'.$this->colors['reset'].': '.$this->colors['green'].$usage['input_tokens'].$this->colors['reset']);
        $command->line($this->colors['yellow'].'Output Tokens'.$this->colors['reset'].': '.$this->colors['green'].$usage['output_tokens'].$this->colors['reset']);
        $command->line($this->colors['yellow'].'Cache Created'.$this->colors['reset'].': '.$this->colors['blue'].$usage['cache_creation_input_tokens'].$this->colors['reset']);
        $command->line($this->colors['yellow'].'Cache Read'.$this->colors['reset'].': '.$this->colors['blue'].$usage['cache_read_input_tokens'].$this->colors['reset']);
        $command->line($this->colors['yellow'].'Total Tokens'.$this->colors['reset'].': '.$this->colors['bold'].$this->colors['purple'].$usage['total_tokens'].$this->colors['reset']);
    }

    /**
     * 비용 계산 정보를 출력
     */
    public function printCostEstimation(Command $command, array $usage): void
    {
        try {
            $rates = $this->getModelRates($this->getPromptTokenCount($usage));
            $costs = $this->calculateCosts($rates, $usage);
        } catch (RuntimeException $exception) {
            $command->warn($exception->getMessage());

            return;
        }

        $command->line("\n".str_repeat('─', 80));
        $modelHeader = ' Cost Estimation ('.$rates['name'].') ';
        if ($this->currentModel !== $rates['id']) {
            $modelHeader = ' Cost Estimation ('.$rates['name']." - mapped from '{$this->currentModel}') ";
        }

        $command->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].$modelHeader.$this->colors['reset']);
        $command->line($this->colors['gray'].'Model Pricing:'.$this->colors['reset']);
        $command->line($this->colors['gray'].'  Input: '.$this->formatRate($rates['input']).$this->colors['reset']);
        $command->line($this->colors['gray'].'  Output: '.$this->formatRate($rates['output']).$this->colors['reset']);
        $command->line($this->colors['gray'].'  Cache Write: '.$this->formatRate($rates['cache_write']).$this->colors['reset']);
        $command->line($this->colors['gray'].'  Cache Read: '.$this->formatRate($rates['cache_read']).$this->colors['reset']);

        $command->line("\n".$this->colors['yellow'].'Your Cost Breakdown'.$this->colors['reset'].':');
        $command->line('  Regular Input Cost: $'.number_format($costs['input'], 6));
        $command->line('  Cache Creation Cost: $'.number_format($costs['cache_write'], 6));
        $command->line('  Cache Read Cost: $'.number_format($costs['cache_read'], 6));
        $command->line('  Output Cost: $'.number_format($costs['output'], 6));
        $command->line('  Total Cost: $'.number_format($costs['total'], 6));

        if ((int) ($usage['cache_read_input_tokens'] ?? 0) > 0) {
            $command->line("\n".$this->colors['green'].$this->colors['bold'].'Cache Savings'.$this->colors['reset']);
            $command->line('  Cost without Caching: $'.number_format($costs['no_cache_total'], 6));
            $command->line('  Saved Amount: $'.number_format($costs['saved'], 6).' ('.number_format($costs['saved_percentage'], 2).'% reduction)');
        }
    }

    /**
     * 다른 모델과의 비용 비교 정보를 출력합니다
     */
    public function printModelComparison(Command $command, array $usage): void
    {
        if ($this->currentModel === null || $this->currentModel === '') {
            $command->warn('A model is required to compare OpenRouter pricing.');

            return;
        }

        // printCostEstimation already reported this catalog-level failure for a full report.
        if ($this->pricingError !== null) {
            return;
        }

        $command->line("\n".str_repeat('─', 80));
        $command->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Model Cost Comparison '.$this->colors['reset']);

        $promptTokens = $this->getPromptTokenCount($usage);
        $currentModelId = $this->getOpenRouterModelId($this->currentModel);
        $models = array_values(array_unique([$currentModelId, ...self::COMPARISON_MODELS]));
        $comparison = [];

        foreach ($models as $model) {
            try {
                $rates = $this->getModelRates($promptTokens, $model);
                $costs = $this->calculateCosts($rates, $usage);
            } catch (RuntimeException $exception) {
                $command->warn($exception->getMessage());

                continue;
            }

            $comparison[$rates['id']] = [
                'name' => $rates['name'],
                'total_cost' => $costs['total'],
            ];
        }

        $currentModelCost = $comparison[$currentModelId]['total_cost'] ?? 0;
        uasort($comparison, function ($left, $right): int {
            return $left['total_cost'] <=> $right['total_cost'];
        });

        $command->line('');
        $command->line($this->colors['bold'].'MODEL'.str_repeat(' ', 20).'TOTAL COST'.str_repeat(' ', 5).'SAVINGS vs CURRENT'.$this->colors['reset']);
        $command->line(str_repeat('─', 80));

        foreach ($comparison as $model => $data) {
            $isCurrentModel = $model === $currentModelId;
            $modelName = str_pad($data['name'], 25, ' ');
            $modelName = $isCurrentModel
                ? $this->colors['green'].'➤ '.$modelName.$this->colors['reset']
                : '  '.$modelName;
            $cost = '$'.str_pad(number_format($data['total_cost'], 6), 12, ' ', STR_PAD_LEFT);
            $savingsAmount = $currentModelCost - $data['total_cost'];
            $savingsPercentage = $currentModelCost > 0 ? ($savingsAmount / $currentModelCost) * 100 : 0;

            if ($isCurrentModel || $currentModelCost <= 0) {
                $savings = str_pad('—', 25, ' ');
            } elseif ($savingsAmount > 0) {
                $savings = $this->colors['green'].str_pad(number_format($savingsAmount, 6), 10, ' ', STR_PAD_LEFT).
                    ' ('.number_format($savingsPercentage, 1).'% less)'.$this->colors['reset'];
            } else {
                $savings = $this->colors['red'].str_pad(number_format(abs($savingsAmount), 6), 10, ' ', STR_PAD_LEFT).
                    ' ('.number_format(abs($savingsPercentage), 1).'% more)'.$this->colors['reset'];
            }

            $command->line($modelName.$cost.'  '.$savings);
        }
    }

    /**
     * 토큰 사용량과 비용 계산을 모두 출력
     */
    public function printFullReport(Command $command, array $usage, bool $includeComparison = true): void
    {
        $this->printTokenUsageSummary($command, $usage);
        $this->printCostEstimation($command, $usage);

        if ($includeComparison) {
            $this->printModelComparison($command, $usage);
        }
    }
}
