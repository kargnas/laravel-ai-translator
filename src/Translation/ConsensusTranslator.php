<?php

namespace Kargnas\LaravelAiTranslator\Translation;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Contracts\Translator;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Usage;

class ConsensusTranslator implements Translator
{
    protected $onTranslated = null;

    protected $onThinking = null;

    protected $onProgress = null;

    protected $onPromptGenerated = null;

    protected $onThinkingStart = null;

    protected $onThinkingEnd = null;

    protected $onTokenUsage = null;

    /** @var array<string, int> */
    protected array $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => 0,
    ];

    /**
     * @param  array<string, mixed>  $strings
     * @param  array<string, array<string, mixed>>  $references
     * @param  array<int, string>  $additionalRules
     * @param  array<string, array<string, mixed>>|null  $globalContext
     * @param  array<int, array<string, mixed>>  $translatorConfigs
     * @param  array<string, mixed>  $judgeConfig
     */
    public function __construct(
        protected string $filename,
        protected array $strings,
        protected string $sourceLocale,
        protected string $targetLocale,
        protected array $references,
        protected array $additionalRules,
        protected ?array $globalContext,
        protected array $translatorConfigs,
        protected array $judgeConfig,
    ) {}

    public function setOnTranslated(?callable $callback): self
    {
        $this->onTranslated = $callback;

        return $this;
    }

    public function setOnThinking(?callable $callback): self
    {
        $this->onThinking = $callback;

        return $this;
    }

    public function setOnProgress(?callable $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    public function setOnPromptGenerated(?callable $callback): self
    {
        $this->onPromptGenerated = $callback;

        return $this;
    }

    public function setOnThinkingStart(?callable $callback): self
    {
        $this->onThinkingStart = $callback;

        return $this;
    }

    public function setOnThinkingEnd(?callable $callback): self
    {
        $this->onThinkingEnd = $callback;

        return $this;
    }

    public function setOnTokenUsage(?callable $callback): self
    {
        $this->onTokenUsage = $callback;

        return $this;
    }

    /** @return array<int, LocalizedString> */
    public function translate(): array
    {
        $this->resetTokenUsage();
        $candidates = [];

        foreach ($this->translatorConfigs as $index => $config) {
            $provider = new AIProvider(
                $this->filename,
                $this->strings,
                $this->sourceLocale,
                $this->targetLocale,
                $this->references,
                $this->additionalRules,
                $this->globalContext,
            );
            $provider->withProviderConfig($config);

            if ($index === 0) {
                $provider
                    ->setOnTranslated($this->onTranslated)
                    ->setOnThinking($this->onThinking)
                    ->setOnThinkingStart($this->onThinkingStart)
                    ->setOnThinkingEnd($this->onThinkingEnd)
                    ->setOnProgress($this->onProgress)
                    ->setOnPromptGenerated($this->onPromptGenerated);
            }

            $items = $provider->translate();
            $this->addTokenUsage($provider->getTokenUsage());
            $this->emitTokenUsage(false);

            if ($items === []) {
                $model = (string) ($config['model'] ?? 'unknown');
                $message = "Translator {$model} produced no result; continuing with remaining candidates";
                Log::warning($message);
                $this->emitProgress($message);

                continue;
            }

            $candidates[$index] = $items;
        }

        if ($candidates === []) {
            $this->emitTokenUsage(true);

            return [];
        }

        if (count($candidates) === 1) {
            $this->emitTokenUsage(true);

            return array_values($candidates)[0];
        }

        try {
            $response = $this->judge($candidates);
            if ($response->usage instanceof Usage) {
                $this->addTokenUsageFromUsage($response->usage);
            }
            $this->emitTokenUsage(true);

            return $this->mergeCandidates($candidates, $response->structured);
        } catch (\Throwable $exception) {
            $message = 'Judge failed; using first translator results for all keys.';
            Log::warning($message, ['exception' => $exception->getMessage()]);
            $this->emitProgress($message);
            $this->emitTokenUsage(true);

            return array_values($candidates)[0];
        }
    }

    /** @return array<string, int> */
    public function getTokenUsage(): array
    {
        return $this->tokenUsage;
    }

    public function getModel(): string
    {
        return (string) ($this->judgeConfig['model'] ?? ($this->translatorConfigs[0]['model'] ?? 'unknown'));
    }

    /** @param array<int, array<int, LocalizedString>> $candidates */
    protected function judge(array $candidates): object
    {
        $candidateMap = $this->candidateMap($candidates);
        $labelsByKey = [];
        $promptParts = [
            "Target locale: {$this->targetLocale}",
            'Choose the best candidate label for each translation key.',
        ];

        foreach ($candidateMap as $key => $items) {
            $source = $this->strings[$key] ?? '';
            if (is_array($source)) {
                $source = $source['text'] ?? '';
            }

            $promptParts[] = "Key: `{$key}`\nSource: \"\"\"{$source}\"\"\"";
            foreach ($items as $label => $item) {
                $promptParts[] = "Candidate {$label}: \"\"\"{$item->translated}\"\"\"";
            }

            $labelsByKey[$key] = array_keys($items);
        }

        $model = (string) ($this->judgeConfig['model'] ?? '');
        $temperature = str_starts_with($model, 'gpt-5')
            // gpt-5 API requires temperature 1.0, overriding judge preference.
            ? 1.0
            : 0.3;

        $providerName = (string) ($this->judgeConfig['provider'] ?? '');
        $provider = AIProvider::resolveProvider($providerName);
        config(["ai.providers.{$providerName}.key" => (string) ($this->judgeConfig['api_key'] ?? '')]);
        Ai::forgetInstance($provider->value);

        $agent = $this->makeJudgeAgent($labelsByKey, $temperature, array_key_exists('max_tokens', $this->judgeConfig)
            ? ($this->judgeConfig['max_tokens'] === null ? null : (int) $this->judgeConfig['max_tokens'])
            : null);

        return $agent->prompt(
            implode("\n\n", $promptParts),
            provider: $provider,
            model: $model,
        );
    }

    /**
     * @param  array<string, array<int, string>>  $labelsByKey
     */
    protected function makeJudgeAgent(array $labelsByKey, float $temperature, ?int $maxTokens): Agent
    {
        return new class($labelsByKey, $temperature, $maxTokens) implements Agent, HasProviderOptions, HasStructuredOutput
        {
            use Promptable;

            /** @param  array<string, array<int, string>>  $labelsByKey */
            public function __construct(
                protected array $labelsByKey,
                protected float $configuredTemperature,
                protected ?int $configuredMaxTokens,
            ) {}

            public function instructions(): string
            {
                return 'Pick the most accurate and natural translation for the target locale. Respond only with candidate labels.';
            }

            public function temperature(): float
            {
                return $this->configuredTemperature;
            }

            public function maxTokens(): ?int
            {
                return $this->configuredMaxTokens;
            }

            public function providerOptions(Lab|string $provider): array
            {
                return [];
            }

            public function schema(JsonSchema $schema): array
            {
                return collect($this->labelsByKey)->mapWithKeys(
                    fn (array $labels, string $key): array => [$key => $schema->string()->enum($labels)->required()]
                )->all();
            }
        };
    }

    /** @param array<int, array<int, LocalizedString>> $candidates */
    protected function candidateMap(array $candidates): array
    {
        $map = [];
        foreach ($candidates as $index => $items) {
            $label = chr(65 + $index);
            foreach ($items as $item) {
                $map[$item->key][$label] = $item;
            }
        }

        return $map;
    }

    /** @param array<int, array<int, LocalizedString>> $candidates */
    protected function mergeCandidates(array $candidates, array $judgment): array
    {
        $map = $this->candidateMap($candidates);
        $merged = [];

        foreach ($map as $key => $items) {
            $label = $judgment[$key] ?? null;
            if (! is_string($label) || ! isset($items[$label])) {
                Log::warning("Judge returned invalid label for key '{$key}'; using first candidate.");
                $label = array_key_first($items);
            }

            $merged[] = $items[$label];
        }

        return $merged;
    }

    protected function resetTokenUsage(): void
    {
        $this->tokenUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'total_tokens' => 0,
        ];
    }

    /** @param array<string, int> $usage */
    protected function addTokenUsage(array $usage): void
    {
        $this->tokenUsage['input_tokens'] += $usage['input_tokens'] ?? 0;
        $this->tokenUsage['output_tokens'] += $usage['output_tokens'] ?? 0;
        $this->tokenUsage['cache_creation_input_tokens'] += $usage['cache_creation_input_tokens'] ?? 0;
        $this->tokenUsage['cache_read_input_tokens'] += $usage['cache_read_input_tokens'] ?? 0;
        $this->tokenUsage['total_tokens'] =
            $this->tokenUsage['input_tokens'] + $this->tokenUsage['output_tokens'];
    }

    protected function addTokenUsageFromUsage(Usage $usage): void
    {
        $this->tokenUsage['input_tokens'] += $usage->promptTokens;
        $this->tokenUsage['output_tokens'] += $usage->completionTokens;
        $this->tokenUsage['cache_creation_input_tokens'] += $usage->cacheWriteInputTokens;
        $this->tokenUsage['cache_read_input_tokens'] += $usage->cacheReadInputTokens;
        $this->tokenUsage['total_tokens'] =
            $this->tokenUsage['input_tokens'] + $this->tokenUsage['output_tokens'];
    }

    protected function emitTokenUsage(bool $final): void
    {
        if ($this->onTokenUsage !== null) {
            ($this->onTokenUsage)(array_merge($this->tokenUsage, ['final' => $final]));
        }
    }

    protected function emitProgress(string $message): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($message, []);
        }
    }
}
