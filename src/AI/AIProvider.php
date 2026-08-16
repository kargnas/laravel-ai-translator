<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\AI\Parsers\AIResponseParser;
use Kargnas\LaravelAiTranslator\Contracts\Translator;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Kargnas\LaravelAiTranslator\Translation\Validator;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

class AIProvider implements Translator
{
    protected string $configProvider;

    protected string $configModel;

    protected int $configRetries;

    /** @var array<string, mixed>|null */
    protected ?array $providerConfigOverride = null;

    public Language $sourceLanguageObj;

    public Language $targetLanguageObj;

    // Variable to store the raw XML of the translation response
    public static string $lastRawResponse = '';

    /**
     * 토큰 사용량 추적을 위한 속성들
     */
    protected int $inputTokens = 0;

    protected int $outputTokens = 0;

    protected int $cacheCreationInputTokens = 0;

    protected int $cacheReadInputTokens = 0;

    protected int $totalTokens = 0;

    // Callback properties
    protected $onTranslated = null;

    protected $onThinking = null;

    protected $onProgress = null;

    protected $onThinkingStart = null;

    protected $onThinkingEnd = null;

    protected $onTokenUsage = null;

    protected $onPromptGenerated = null;

    /**
     * AIProvider 생성자
     */
    public function __construct(
        public string $filename,
        public array $strings,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array $references = [],
        public array $additionalRules = [],
        public ?array $globalTranslationContext = null,
    ) {
        $this->configProvider = config('ai-translator.ai.provider');
        $this->configModel = config('ai-translator.ai.model');
        $this->configRetries = config('ai-translator.ai.retries', 1);

        // Add file prefix to all keys
        $prefix = $this->getFilePrefix();
        $this->strings = collect($this->strings)->mapWithKeys(function ($value, $key) use ($prefix) {
            $newKey = "{$prefix}.{$key}";

            return [$newKey => $value];
        })->toArray();

        try {
            // Create language objects
            $this->sourceLanguageObj = Language::fromCode($sourceLanguage);
            $this->targetLanguageObj = Language::fromCode($targetLanguage);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Failed to initialize language: '.$e->getMessage());
        }

        // Get additional rules from LanguageRules
        $this->additionalRules = array_merge(
            $this->additionalRules,
            LanguageRules::getAdditionalRules($this->targetLanguageObj)
        );

        // Initialize tokens
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->cacheCreationInputTokens = 0;
        $this->cacheReadInputTokens = 0;
        $this->totalTokens = 0;
        $this->cacheCreationInputTokens = 0;
        $this->cacheReadInputTokens = 0;

        Log::info("AIProvider initiated: Source language = {$this->sourceLanguageObj->name} ({$this->sourceLanguageObj->code}), Target language = {$this->targetLanguageObj->name} ({$this->targetLanguageObj->code})");
        Log::info('AIProvider additional rules: '.json_encode($this->additionalRules));
    }

    protected function getFilePrefix(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }

    protected function verify(array $list): void
    {
        // Standard verification for production translations
        $sourceKeys = collect($this->strings)->keys()->unique()->sort()->values();
        $resultKeys = collect($list)->pluck('key')->unique()->sort()->values();

        $missingKeys = $sourceKeys->diff($resultKeys);
        $extraKeys = $resultKeys->diff($sourceKeys);
        $hasValidTranslations = false;

        // Check if there are any valid translations among the translated items
        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (! empty($item->key) && isset($item->translated) && $sourceKeys->contains($item->key)) {
                $hasValidTranslations = true;

                // Output warning log if there is a comment
                if (! empty($item->comment)) {
                    Log::warning("Translation comment for key '{$item->key}': {$item->comment}");
                }

                break;
            }
        }

        // Throw exception only if there are no valid translations
        if (! $hasValidTranslations) {
            throw new VerifyFailedException('No valid translations found in the response.');
        }

        // Warning for missing keys
        if ($missingKeys->count() > 0) {
            Log::warning("Some keys were not translated: {$missingKeys->implode(', ')}");
        }

        // Warning for extra keys
        if ($extraKeys->count() > 0) {
            Log::warning("Found unexpected translation keys: {$extraKeys->implode(', ')}");
        }

        $validator = new Validator;
        $translatedItemCount = 0;
        $invalidItems = [];

        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (empty($item->key) || ! isset($item->translated) || ! array_key_exists($item->key, $this->strings)) {
                continue;
            }

            $source = $this->strings[$item->key];
            $original = is_array($source) ? ($source['text'] ?? '') : $source;
            $issues = $validator->validate((string) $original, $item->translated);
            $translatedItemCount++;

            if ($issues === []) {
                continue;
            }

            $issueSummary = implode('; ', $issues);
            Log::warning("Translation validation issues for key '{$item->key}': {$issueSummary}");

            if (empty($item->comment)) {
                $item->comment = $issueSummary;
            }

            $invalidItems[] = "{$item->key} ({$issueSummary})";
        }

        if ($translatedItemCount > 0 && count($invalidItems) * 2 > $translatedItemCount) {
            $sample = implode('; ', array_slice($invalidItems, 0, 3));
            throw new VerifyFailedException(
                'Post-translation validation failed for '.count($invalidItems)." of {$translatedItemCount} items: {$sample}"
            );
        }

        // After verification is complete, restore original keys
        $prefix = $this->getFilePrefix();
        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (! empty($item->key)) {
                $item->key = preg_replace("/^{$prefix}\./", '', $item->key);
            }
        }
    }

    protected function getSystemPrompt($replaces = [])
    {
        $systemPrompt = file_get_contents(config('ai-translator.ai.prompt_custom_system_file_path') ?? __DIR__.'/prompt-system.txt');

        $translationContext = '';

        if ($this->globalTranslationContext && count($this->globalTranslationContext) > 0) {
            $contextFileCount = count($this->globalTranslationContext);
            $contextItemCount = 0;

            foreach ($this->globalTranslationContext as $items) {
                $contextItemCount += count($items);
            }

            Log::debug("AIProvider: Using translation context - {$contextFileCount} files, {$contextItemCount} items");

            $translationContext = collect($this->globalTranslationContext)->map(function ($translations, $file) {
                // Remove .php extension from filename
                $rootKey = pathinfo($file, PATHINFO_FILENAME);
                $itemCount = count($translations);

                Log::debug("AIProvider: Including context file - {$rootKey}: {$itemCount} items");

                $translationsText = collect($translations)->map(function ($item, $key) use ($rootKey) {
                    $sourceText = $item['source'] ?? '';

                    if (empty($sourceText)) {
                        return null;
                    }

                    $text = "`{$rootKey}.{$key}`: src=\"\"\"{$sourceText}\"\"\"";

                    // Check reference information
                    $referenceKey = $key;
                    foreach ($this->references as $locale => $strings) {
                        if (isset($strings[$referenceKey]) && ! empty($strings[$referenceKey])) {
                            $text .= "\n    {$locale}=\"\"\"{$strings[$referenceKey]}\"\"\"";
                        }
                    }

                    return $text;
                })->filter()->implode("\n");

                return empty($translationsText) ? '' : "## `{$rootKey}`\n{$translationsText}";
            })->filter()->implode("\n\n");

            $contextLength = strlen($translationContext);
            Log::debug("AIProvider: Generated context size - {$contextLength} bytes");
        } else {
            Log::debug('AIProvider: No translation context available or empty');
        }

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguageObj->name,
            'targetLanguage' => $this->targetLanguageObj->name,
            'additionalRules' => count($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguageObj->name}:\n".implode("\n", $this->additionalRules) : '',
            'translationContextInSourceLanguage' => $translationContext,
        ]);

        foreach ($replaces as $key => $value) {
            $systemPrompt = str_replace("{{$key}}", $value, $systemPrompt);
        }

        // 프롬프트 생성 콜백 호출 (모든 치환이 완료된 후)
        if ($this->onPromptGenerated) {
            ($this->onPromptGenerated)($systemPrompt, PromptType::SYSTEM);
        }

        return $systemPrompt;
    }

    protected function getUserPrompt($replaces = [])
    {
        $userPrompt = file_get_contents(config('ai-translator.ai.prompt_custom_user_file_path') ?? __DIR__.'/prompt-user.txt');

        $replaces = array_merge($replaces, [
            // Options
            'options.disablePlural' => config('ai-translator.disable_plural', false) ? 'true' : 'false',

            // Data
            'sourceLanguage' => $this->sourceLanguageObj->name,
            'targetLanguage' => $this->targetLanguageObj->name,
            'filename' => $this->filename,
            'parentKey' => pathinfo($this->filename, PATHINFO_FILENAME),
            'keys' => collect($this->strings)->keys()->implode('`, `'),
            'strings' => collect($this->strings)->map(function ($string, $key) {
                if (is_string($string)) {
                    return "  - `{$key}`: \"\"\"{$string}\"\"\"";
                } else {
                    $text = "  - `{$key}`: \"\"\"{$string['text']}\"\"\"";
                    if (isset($string['context'])) {
                        $text .= "\n    - Context: \"\"\"{$string['context']}\"\"\"";
                    }

                    return $text;
                }
            })->implode("\n"),
        ]);

        foreach ($replaces as $key => $value) {
            $userPrompt = str_replace("{{$key}}", $value, $userPrompt);
        }

        // 프롬프트 생성 콜백 호출 (모든 치환이 완료된 후)
        if ($this->onPromptGenerated) {
            ($this->onPromptGenerated)($userPrompt, PromptType::USER);
        }

        return $userPrompt;
    }

    /**
     * Set the translation completion callback
     */
    public function setOnTranslated(?callable $callback): self
    {
        $this->onTranslated = $callback;

        return $this;
    }

    /**
     * Set the callback to be called during thinking process
     */
    public function setOnThinking(?callable $callback): self
    {
        $this->onThinking = $callback;

        return $this;
    }

    /**
     * Set the callback to be called to report progress
     */
    public function setOnProgress(?callable $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    /**
     * Set the callback to be called when thinking starts
     */
    public function setOnThinkingStart(?callable $callback): self
    {
        $this->onThinkingStart = $callback;

        return $this;
    }

    /**
     * Set the callback to be called when thinking ends
     */
    public function setOnThinkingEnd(?callable $callback): self
    {
        $this->onThinkingEnd = $callback;

        return $this;
    }

    /**
     * Set the callback to be called to report token usage
     */
    public function setOnTokenUsage(?callable $callback): self
    {
        $this->onTokenUsage = $callback;

        return $this;
    }

    /**
     * Set the callback to be called when a prompt is generated
     *
     * @param  callable  $callback  Callback function that receives prompt text and PromptType
     */
    public function setOnPromptGenerated(?callable $callback): self
    {
        $this->onPromptGenerated = $callback;

        return $this;
    }

    /**
     * Override provider settings for this translation instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function withProviderConfig(array $config): self
    {
        $this->providerConfigOverride = $config;
        $this->configProvider = (string) ($config['provider'] ?? $this->configProvider);
        $this->configModel = (string) ($config['model'] ?? $this->configModel);

        return $this;
    }

    /**
     * Translate strings
     */
    public function translate(): array
    {
        $tried = 1;
        do {
            try {
                if ($tried > 1) {
                    Log::warning("[{$tried}/{$this->configRetries}] Retrying translation into {$this->targetLanguageObj->name} using {$this->configProvider} with {$this->configModel} model...");
                }

                $translatedObjects = $this->getTranslatedObjects();
                $this->verify($translatedObjects);

                // Pass final token usage after translation is complete
                if ($this->onTokenUsage) {
                    // 토큰 사용량에 final 플래그 추가
                    $tokenUsage = $this->getTokenUsage();
                    $tokenUsage['final'] = true;
                    ($this->onTokenUsage)($tokenUsage);
                }

                return $translatedObjects;
            } catch (VerifyFailedException $e) {
                Log::error($e->getMessage());
            } catch (\Exception $e) {
                Log::critical('AIProvider: Error during translation', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } while (++$tried <= $this->configRetries);

        Log::warning("Failed to translate {$this->filename} into {$this->targetLanguageObj->name} after {$this->configRetries} retries.");

        return [];
    }

    protected function getTranslatedObjects(): array
    {
        return $this->getTranslatedObjectsWithSdk();
    }

    public static function resolveProvider(string $provider): Lab
    {
        return match ($provider) {
            'anthropic' => Lab::Anthropic,
            'openai' => Lab::OpenAI,
            'gemini' => Lab::Gemini,
            'openrouter' => Lab::OpenRouter,
            default => throw new \RuntimeException("Provider {$provider} is not supported."),
        };
    }

    protected function getTranslatedObjectsWithSdk(): array
    {
        $provider = self::resolveProvider($this->configProvider);
        config(["ai.providers.{$this->configProvider}.key" => (string) $this->effectiveConfig('api_key', '')]);
        Ai::forgetInstance($provider->value);

        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->getUserPrompt();
        $responseParser = new AIResponseParser($this->onTranslated, config('app.debug', false));

        $agent = $this->makeAgent($systemPrompt);

        if ($this->effectiveConfig('disable_stream', false)) {
            $response = $agent->prompt($userPrompt, provider: $provider, model: $this->configModel);
            $this->updateTokenUsage($response->usage);
            $this->parseCompleteResponse($response->text, $responseParser);
            $this->logTokenUsage();

            return $responseParser->getTranslatedItems();
        }

        $responseText = '';
        $thinkingContent = '';
        $processedKeys = [];
        $lastUsage = null;

        foreach ($agent->stream($userPrompt, provider: $provider, model: $this->configModel) as $event) {
            if ($event instanceof TextDelta) {
                $responseText .= $event->delta;
                $previousItemCount = count($responseParser->getTranslatedItems());
                $responseParser->parseChunk($event->delta);
                $currentItems = $responseParser->getTranslatedItems();

                $this->emitCompletedItems(
                    array_slice($currentItems, $previousItemCount),
                    $currentItems,
                    $processedKeys
                );

                if ($this->onProgress) {
                    ($this->onProgress)($event->delta, $currentItems);
                }
            } elseif ($event instanceof ReasoningStart) {
                if ($this->onThinkingStart) {
                    ($this->onThinkingStart)();
                }
            } elseif ($event instanceof ReasoningDelta) {
                $thinkingContent .= $event->delta;
                if ($this->onThinking) {
                    ($this->onThinking)($event->delta);
                }
            } elseif ($event instanceof ReasoningEnd) {
                if ($this->onThinkingEnd) {
                    ($this->onThinkingEnd)($thinkingContent);
                }
                $thinkingContent = '';
            } elseif ($event instanceof StreamEnd) {
                $lastUsage = $event->usage;
            }
        }

        if ($lastUsage instanceof Usage) {
            $this->updateTokenUsage($lastUsage);
        }

        if ($responseParser->getTranslatedItems() === [] && $responseText !== '') {
            $this->parseCompleteResponse($responseText, $responseParser);
        }

        $this->logTokenUsage();

        return $responseParser->getTranslatedItems();
    }

    protected function makeAgent(string $systemPrompt): Agent
    {
        $temperature = $this->temperature();
        $maxTokens = $this->maxTokens();
        $providerOptions = $this->providerOptions($systemPrompt);

        return new class($systemPrompt, $temperature, $maxTokens, $providerOptions) implements Agent, HasProviderOptions
        {
            use Promptable;

            public function __construct(
                protected string $systemPrompt,
                protected int|float|null $configuredTemperature,
                protected ?int $configuredMaxTokens,
                protected array $configuredProviderOptions,
            ) {}

            public function instructions(): string
            {
                return $this->systemPrompt;
            }

            public function temperature(): ?float
            {
                return $this->configuredTemperature === null ? null : (float) $this->configuredTemperature;
            }

            public function maxTokens(): ?int
            {
                return $this->configuredMaxTokens;
            }

            public function providerOptions(Lab|string $provider): array
            {
                return $this->configuredProviderOptions;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerOptions(string $systemPrompt): array
    {
        if ($this->configProvider === 'anthropic') {
            // Explicit cache breakpoint on the system block (the shared prefix across
            // chunks of the same file). A top-level cache_control would auto-place the
            // breakpoint on the varying user chunk and never produce a cache hit.
            // Below Anthropic's minimum cacheable length the server treats it as a no-op.
            $options = [
                'system' => [
                    ['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']],
                ],
            ];

            if ($this->effectiveConfig('use_extended_thinking', false)) {
                $options['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ];
            }

            return $options;
        }

        // OpenRouter forwards reasoning effort (e.g. ['effort' => 'high']) to the underlying model.
        $reasoning = $this->effectiveConfig('reasoning');
        if ($this->configProvider === 'openrouter' && $reasoning !== null) {
            return ['reasoning' => $reasoning];
        }

        return [];
    }

    protected function maxTokens(): ?int
    {
        if ($this->configProvider !== 'anthropic') {
            return $this->effectiveConfig('max_tokens') === null
                ? null
                : (int) $this->effectiveConfig('max_tokens');
        }

        $defaultMaxTokens = match (true) {
            preg_match('/^claude\-3\-5\-/', $this->configModel) === 1 => 8192,
            preg_match('/^claude\-3\-7\-/', $this->configModel) === 1 => 64000,
            default => 4096,
        };
        $maxTokens = (int) $this->effectiveConfig('max_tokens', $defaultMaxTokens);

        if ($this->effectiveConfig('use_extended_thinking', false) && $maxTokens < 10000) {
            throw new \RuntimeException("Max tokens is less than thinking budget tokens. Please increase max tokens. Current max tokens: {$maxTokens}, Thinking budget tokens: 10000");
        }

        return $maxTokens;
    }

    protected function temperature(): int|float|null
    {
        if (str_starts_with($this->configModel, 'gpt-5')) {
            return 1.0;
        }

        // Anthropic rejects requests combining extended thinking with any temperature other than 1.
        if ($this->configProvider === 'anthropic' && $this->effectiveConfig('use_extended_thinking', false)) {
            return 1.0;
        }

        return $this->effectiveConfig('temperature');
    }

    protected function effectiveConfig(string $key, mixed $default = null): mixed
    {
        if ($this->providerConfigOverride !== null && array_key_exists($key, $this->providerConfigOverride)) {
            return $this->providerConfigOverride[$key];
        }

        return config("ai-translator.ai.{$key}", $default);
    }

    /**
     * @param  array<int, LocalizedString>  $items
     * @param  array<int, LocalizedString>  $allItems
     * @param  array<string, bool>  $processedKeys
     */
    protected function emitCompletedItems(array $items, array $allItems, array &$processedKeys): void
    {
        foreach ($items as $item) {
            if (isset($processedKeys[$item->key])) {
                continue;
            }

            $processedKeys[$item->key] = true;

            if ($this->onTranslated && $item->translated !== '') {
                ($this->onTranslated)($item, TranslationStatus::COMPLETED, $allItems);
            }
        }
    }

    protected function parseCompleteResponse(string $responseText, AIResponseParser $responseParser): void
    {
        $responseParser->parse($responseText);

        if ($this->onProgress) {
            ($this->onProgress)($responseText, $responseParser->getTranslatedItems());
        }

        if ($this->onTranslated) {
            foreach ($responseParser->getTranslatedItems() as $item) {
                ($this->onTranslated)($item, TranslationStatus::STARTED, $responseParser->getTranslatedItems());
                ($this->onTranslated)($item, TranslationStatus::COMPLETED, $responseParser->getTranslatedItems());
            }
        }
    }

    protected function updateTokenUsage(Usage $usage): void
    {
        $this->cacheCreationInputTokens = $usage->cacheWriteInputTokens;
        $this->cacheReadInputTokens = $usage->cacheReadInputTokens;
        // OpenRouter includes cache reads in promptTokens but exposes cache writes separately,
        // so subtract reads to keep input_tokens as freshly billed input only.
        $this->inputTokens = max(0, $usage->promptTokens - $this->cacheReadInputTokens);
        $this->outputTokens = $usage->completionTokens;
        $this->totalTokens = $this->inputTokens
            + $this->outputTokens
            + $this->cacheCreationInputTokens
            + $this->cacheReadInputTokens;

        if ($this->onTokenUsage) {
            $tokenUsage = $this->getTokenUsage();
            $tokenUsage['final'] = false;
            ($this->onTokenUsage)($tokenUsage);
        }
    }

    /**
     * 토큰 사용량 정보를 반환합니다.
     *
     * @return array 토큰 사용량 정보
     */
    public function getTokenUsage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }

    /**
     * 토큰 사용량 정보를 로그에 기록합니다.
     */
    public function logTokenUsage(): void
    {
        $tokenInfo = $this->getTokenUsage();

        Log::info('AIProvider: Token Usage Information', [
            'input_tokens' => $tokenInfo['input_tokens'],
            'output_tokens' => $tokenInfo['output_tokens'],
            'cache_creation_input_tokens' => $tokenInfo['cache_creation_input_tokens'],
            'cache_read_input_tokens' => $tokenInfo['cache_read_input_tokens'],
            'total_tokens' => $tokenInfo['total_tokens'],
        ]);
    }

    /**
     * 현재 사용 중인 AI 모델을 반환합니다.
     */
    public function getModel(): string
    {
        return $this->configModel;
    }
}
