<?php

namespace Kargnas\LaravelAiTranslator\Translation;

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\ValueObjects\Usage;

class ConsensusTranslator
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

    /** @param array<int, array<string, mixed>> $translatorConfigs */
    public function __construct(
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

    /**
     * @param  array<string, mixed>  $strings
     * @param  array<string, array<string, mixed>>  $references
     * @param  array<int, string>  $additionalRules
     * @param  array<string, array<string, mixed>>|null  $globalContext
     * @return array<int, LocalizedString>
     */
    public function translate(
        string $filename,
        array $strings,
        string $sourceLocale,
        string $targetLocale,
        array $references,
        array $additionalRules,
        ?array $globalContext,
        ?callable $onTranslated,
        ?callable $onThinking,
        ?callable $onProgress,
        ?callable $onTokenUsage,
        ?callable $onPromptGenerated,
    ): array {
        $this->resetTokenUsage();
        $onTranslated ??= $this->onTranslated;
        $onThinking ??= $this->onThinking;
        $onProgress ??= $this->onProgress;
        $onPromptGenerated ??= $this->onPromptGenerated;
        $onTokenUsage ??= $this->onTokenUsage;
        $candidates = [];

        foreach ($this->translatorConfigs as $index => $config) {
            $provider = new AIProvider(
                $filename,
                $strings,
                $sourceLocale,
                $targetLocale,
                $references,
                $additionalRules,
                $globalContext,
            );
            $provider->withProviderConfig($config);

            if ($index === 0) {
                $provider
                    ->setOnTranslated($onTranslated)
                    ->setOnThinking($onThinking)
                    ->setOnThinkingStart($this->onThinkingStart)
                    ->setOnThinkingEnd($this->onThinkingEnd)
                    ->setOnProgress($onProgress)
                    ->setOnPromptGenerated($onPromptGenerated);
            }

            $items = $provider->translate();
            $this->addTokenUsage($provider->getTokenUsage());
            $this->emitTokenUsage($onTokenUsage, false);

            if ($items === []) {
                $model = (string) ($config['model'] ?? 'unknown');
                $message = "Translator {$model} produced no result; continuing with remaining candidates";
                Log::warning($message);
                $this->emitProgress($onProgress, $message);

                continue;
            }

            $candidates[$index] = $items;
        }

        if ($candidates === []) {
            $this->emitTokenUsage($onTokenUsage, true);

            return [];
        }

        if (count($candidates) === 1) {
            $this->emitTokenUsage($onTokenUsage, true);

            return array_values($candidates)[0];
        }

        try {
            $response = $this->judge($candidates, $strings, $targetLocale);
            if ($response->usage instanceof Usage) {
                $this->addTokenUsageFromUsage($response->usage);
            }
            $this->emitTokenUsage($onTokenUsage, true);

            return $this->mergeCandidates($candidates, $response->structured ?? []);
        } catch (\Throwable $exception) {
            $message = 'Judge failed; using first translator results for all keys.';
            Log::warning($message, ['exception' => $exception->getMessage()]);
            $this->emitProgress($onProgress, $message);
            $this->emitTokenUsage($onTokenUsage, true);

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
    protected function judge(array $candidates, array $strings, string $targetLocale): object
    {
        $candidateMap = $this->candidateMap($candidates);
        $properties = [];
        $promptParts = [
            "Target locale: {$targetLocale}",
            'Choose the best candidate label for each translation key.',
        ];

        foreach ($candidateMap as $key => $items) {
            $source = $strings[$key] ?? '';
            if (is_array($source)) {
                $source = $source['text'] ?? '';
            }

            $promptParts[] = "Key: `{$key}`\nSource: \"\"\"{$source}\"\"\"";
            foreach ($items as $label => $item) {
                $promptParts[] = "Candidate {$label}: \"\"\"{$item->translated}\"\"\"";
            }

            $properties[] = new EnumSchema(
                $key,
                "Best candidate label for {$key}",
                array_keys($items),
            );
        }

        $schema = new ObjectSchema(
            'translation_consensus',
            'Candidate label for each translation key.',
            $properties,
            array_keys($candidateMap),
        );

        $model = (string) ($this->judgeConfig['model'] ?? '');
        $temperature = str_starts_with($model, 'gpt-5')
            // gpt-5 API requires temperature 1.0, overriding judge preference.
            ? 1.0
            : 0.3;

        $request = Prism::structured()
            ->using($this->provider($this->judgeConfig['provider'] ?? ''), $model, [
                'api_key' => (string) ($this->judgeConfig['api_key'] ?? ''),
            ])
            ->withSystemPrompt('Pick the most accurate and natural translation for the target locale. Respond only with candidate labels.')
            ->withPrompt(implode("\n\n", $promptParts))
            ->withSchema($schema)
            ->usingTemperature($temperature);

        if (array_key_exists('max_tokens', $this->judgeConfig)) {
            $request->withMaxTokens($this->judgeConfig['max_tokens'] === null ? null : (int) $this->judgeConfig['max_tokens']);
        }

        return $request->asStructured();
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

    protected function provider(string $provider): Provider
    {
        return match ($provider) {
            'anthropic' => Provider::Anthropic,
            'openai' => Provider::OpenAI,
            'gemini' => Provider::Gemini,
            'openrouter' => Provider::OpenRouter,
            default => throw new \RuntimeException("Provider {$provider} is not supported."),
        };
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
        $this->tokenUsage['cache_creation_input_tokens'] += $usage->cacheWriteInputTokens ?? 0;
        $this->tokenUsage['cache_read_input_tokens'] += $usage->cacheReadInputTokens ?? 0;
        $this->tokenUsage['total_tokens'] =
            $this->tokenUsage['input_tokens'] + $this->tokenUsage['output_tokens'];
    }

    protected function emitTokenUsage(?callable $callback, bool $final): void
    {
        if ($callback === null) {
            return;
        }

        ($callback)(array_merge($this->tokenUsage, ['final' => $final]));
    }

    protected function emitProgress(?callable $callback, string $message): void
    {
        if ($callback !== null) {
            ($callback)($message, []);
        }
    }
}
