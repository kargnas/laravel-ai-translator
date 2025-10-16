<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\AI\Parsers\AIResponseParser;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Text\PendingRequest as PrismPendingTextRequest;
use Prism\Prism\ValueObjects\Usage;

class AIProvider
{
    protected string $configProvider;

    protected string $configModel;

    protected int $configRetries;

    public Language $sourceLanguageObj;

    public Language $targetLanguageObj;

    // Variable to store the raw XML of the translation response
    public static string $lastRawResponse = '';

    /**
     * 토큰 사용량 추적을 위한 속성들
     */
    protected int $inputTokens = 0;

    protected int $outputTokens = 0;

    protected int $totalTokens = 0;

    protected ?int $cacheCreationTokens = null;

    protected ?int $cacheReadTokens = null;

    protected ?int $thoughtTokens = null;

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
        $this->totalTokens = 0;
        $this->cacheCreationTokens = null;
        $this->cacheReadTokens = null;
        $this->thoughtTokens = null;

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
        return $this->translateWithPrism();
    }

    protected function translateWithPrism(): array
    {
        $debugMode = config('app.debug', false);

        $responseParser = new AIResponseParser($this->onTranslated, $debugMode);

        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->totalTokens = 0;
        $this->cacheCreationTokens = null;
        $this->cacheReadTokens = null;
        $this->thoughtTokens = null;

        try {
            $request = $this->buildPrismTextRequest();
        } catch (InvalidArgumentException $e) {
            throw new \Exception("Provider {$this->configProvider} is not supported.", 0, $e);
        }

        if (! config('ai-translator.ai.disable_stream', false)) {
            return $this->translateWithPrismStream($request, $responseParser);
        }

        return $this->translateWithPrismText($request, $responseParser);
    }

    protected function buildPrismTextRequest(): PrismPendingTextRequest
    {
        $providerKey = $this->normalizeProviderKey($this->configProvider);
        $providerConfig = $this->resolvePrismProviderConfig($providerKey);

        $request = Prism::text()
            ->using($providerKey, $this->configModel, $providerConfig)
            ->withSystemPrompt($this->getSystemPrompt())
            ->withPrompt($this->getUserPrompt());

        $providerOptions = $this->buildProviderOptions();

        $maxTokensConfig = config('ai-translator.ai.max_tokens');
        $maxTokens = $maxTokensConfig !== null ? (int) $maxTokensConfig : $this->determineDefaultMaxTokens();

        $thinkingBudget = Arr::get($providerOptions, 'thinking.budget_tokens');
        if ($thinkingBudget !== null && $maxTokens !== null && $maxTokens < $thinkingBudget) {
            throw new \Exception("Max tokens is less than thinking budget tokens. Please increase max tokens. Current max tokens: {$maxTokens}, Thinking budget tokens: {$thinkingBudget}");
        }

        if ($maxTokens !== null) {
            $request->withMaxTokens($maxTokens);
        }

        $temperature = config('ai-translator.ai.temperature', 0);
        if ($temperature !== null) {
            $request->usingTemperature($temperature);
        }

        if (! empty($providerOptions)) {
            $request->withProviderOptions($providerOptions);
        }

        return $request;
    }

    protected function translateWithPrismText(PrismPendingTextRequest $request, AIResponseParser $responseParser): array
    {
        $response = $request->asText();

        $responseText = $response->text;
        self::$lastRawResponse = $responseText;

        $responseParser->parse($responseText);
        $translatedItems = $responseParser->getTranslatedItems();

        $this->updateTokenUsageFromUsage($response->usage ?? null, false);

        if ($this->onProgress) {
            ($this->onProgress)($responseText, $translatedItems);
        }

        if ($this->onTranslated) {
            foreach ($translatedItems as $item) {
                ($this->onTranslated)($item, TranslationStatus::STARTED, $translatedItems);
                ($this->onTranslated)($item, TranslationStatus::COMPLETED, $translatedItems);
            }
        }

        return $translatedItems;
    }

    protected function translateWithPrismStream(PrismPendingTextRequest $request, AIResponseParser $responseParser): array
    {
        $responseText = '';
        $processedKeys = [];
        $thinkingBuffer = '';

        foreach ($request->asStream() as $event) {
            $this->handleStreamEvent(
                $event,
                $responseParser,
                $responseText,
                $processedKeys,
                $thinkingBuffer
            );
        }

        if (empty($responseParser->getTranslatedItems()) && $responseText !== '') {
            $responseParser->parse($responseText);
        }

        $translatedItems = $responseParser->getTranslatedItems();
        self::$lastRawResponse = $responseText;

        if ($this->onTranslated) {
            foreach ($translatedItems as $item) {
                if (! isset($processedKeys[$item->key])) {
                    $processedKeys[$item->key] = true;
                    ($this->onTranslated)($item, TranslationStatus::COMPLETED, $translatedItems);
                }
            }
        }

        return $translatedItems;
    }

    protected function handleStreamEvent(
        StreamEvent $event,
        AIResponseParser $responseParser,
        string &$responseText,
        array &$processedKeys,
        string &$thinkingBuffer
    ): void {
        if ($event instanceof ThinkingStartEvent) {
            $thinkingBuffer = '';
            if ($this->onThinkingStart) {
                ($this->onThinkingStart)();
            }

            return;
        }

        if ($event instanceof ThinkingEvent) {
            $thinkingBuffer .= $event->delta;
            if ($this->onThinking) {
                ($this->onThinking)($event->delta);
            }

            return;
        }

        if ($event instanceof ThinkingCompleteEvent) {
            if ($this->onThinkingEnd) {
                ($this->onThinkingEnd)($thinkingBuffer);
            }

            return;
        }

        if ($event instanceof TextDeltaEvent) {
            $delta = $event->delta;
            $responseText .= $delta;

            $previousCount = count($responseParser->getTranslatedItems());
            $responseParser->parseChunk($delta);
            $currentItems = $responseParser->getTranslatedItems();
            $currentCount = count($currentItems);

            if ($this->onProgress) {
                ($this->onProgress)($delta, $currentItems);
            }

            if ($this->onTranslated && $currentCount > $previousCount) {
                $newItems = array_slice($currentItems, $previousCount);
                foreach ($newItems as $item) {
                    if (! isset($processedKeys[$item->key])) {
                        $processedKeys[$item->key] = true;
                        ($this->onTranslated)($item, TranslationStatus::COMPLETED, $currentItems);
                    }
                }
            }

            return;
        }

        if ($event instanceof StreamEndEvent) {
            $this->updateTokenUsageFromUsage($event->usage ?? null, false);
        }
    }

    protected function resolvePrismProviderConfig(PrismProvider|string $provider): array
    {
        $providerKey = $provider instanceof PrismProvider ? $provider->value : strtolower($provider);

        $baseConfig = config("prism.providers.{$providerKey}", []);
        $customConfig = config("ai-translator.ai.prism.providers.{$providerKey}", []);

        $config = array_replace_recursive(
            is_array($baseConfig) ? $baseConfig : [],
            is_array($customConfig) ? $customConfig : []
        );

        $apiKey = config('ai-translator.ai.api_key');
        $existingKey = Arr::get($config, 'api_key');
        if ($apiKey && empty($existingKey)) {
            Arr::set($config, 'api_key', $apiKey);
        }

        if ($providerKey === PrismProvider::OpenRouter->value) {
            $site = Arr::get($config, 'site', []);
            $site['http_referer'] = $site['http_referer'] ?? 'https://kargn.as';
            $site['x_title'] = $site['x_title'] ?? 'Sangrak';
            Arr::set($config, 'site', $site);
        }

        return $config;
    }

    protected function normalizeProviderKey(string $provider): PrismProvider|string
    {
        $normalized = strtolower($provider);

        return PrismProvider::tryFrom($normalized) ?? $normalized;
    }

    protected function buildProviderOptions(): array
    {
        $options = [];
        $configured = config('ai-translator.ai.provider_options', []);
        if (is_array($configured)) {
            $options = $configured;
        }

        if ($this->isAnthropicProvider() && config('ai-translator.ai.use_extended_thinking', false)) {
            $budget = (int) config('ai-translator.ai.extended_thinking_budget', 10000);
            $options = array_replace_recursive([
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => $budget,
                ],
            ], $options);
        }

        return $options;
    }

    protected function isAnthropicProvider(): bool
    {
        return strtolower($this->configProvider) === PrismProvider::Anthropic->value;
    }

    protected function determineDefaultMaxTokens(): ?int
    {
        if (! $this->isAnthropicProvider()) {
            return null;
        }

        if (preg_match('/^claude\-3\-5\-/', $this->configModel)) {
            return 8192;
        }

        if (preg_match('/^claude\-3\-7\-/', $this->configModel)) {
            return 64000;
        }

        return 4096;
    }

    protected function updateTokenUsageFromUsage(?Usage $usage, bool $finalUpdate): void
    {
        if (! $usage) {
            return;
        }

        $this->inputTokens = $usage->promptTokens;
        $this->outputTokens = $usage->completionTokens;
        $this->cacheCreationTokens = $usage->cacheWriteInputTokens;
        $this->cacheReadTokens = $usage->cacheReadInputTokens;
        $this->thoughtTokens = $usage->thoughtTokens;
        $this->totalTokens = $this->inputTokens + $this->outputTokens;

        $this->notifyTokenUsage($finalUpdate);
    }

    protected function notifyTokenUsage(bool $final): void
    {
        if (! $this->onTokenUsage) {
            return;
        }

        $usage = $this->getTokenUsage();
        $usage['final'] = $final;

        ($this->onTokenUsage)($usage);
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
            'cache_creation_input_tokens' => $this->cacheCreationTokens,
            'cache_read_input_tokens' => $this->cacheReadTokens,
            'thought_tokens' => $this->thoughtTokens,
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
            'thought_tokens' => $tokenInfo['thought_tokens'],
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
