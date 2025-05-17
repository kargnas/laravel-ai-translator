<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\AI\Parsers\AIResponseParser;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

class AIProvider
{
    protected string $configProvider;

    protected string $configModel;

    protected int $configRetries;

    public Language $sourceLanguageObj;

    public Language $targetLanguageObj;

    // 번역 응답의 원본 XML을 저장하는 변수
    public static string $lastRawResponse = '';

    /**
     * 토큰 사용량 추적을 위한 속성들
     */
    protected int $inputTokens = 0;
    protected int $outputTokens = 0;
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
            throw new \InvalidArgumentException("Failed to initialize language: " . $e->getMessage());
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

        Log::info("AIProvider initiated: Source language = {$this->sourceLanguageObj->name} ({$this->sourceLanguageObj->code}), Target language = {$this->targetLanguageObj->name} ({$this->targetLanguageObj->code})");
        Log::info("AIProvider additional rules: " . json_encode($this->additionalRules));
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
            if (!empty($item->key) && isset($item->translated) && $sourceKeys->contains($item->key)) {
                $hasValidTranslations = true;

                // Output warning log if there is a comment
                if (!empty($item->comment)) {
                    Log::warning("Translation comment for key '{$item->key}': {$item->comment}");
                }

                break;
            }
        }

        // Throw exception only if there are no valid translations
        if (!$hasValidTranslations) {
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
            if (!empty($item->key)) {
                $item->key = preg_replace("/^{$prefix}\./", '', $item->key);
            }
        }
    }

    protected function getSystemPrompt($replaces = [])
    {
        $systemPrompt = file_get_contents(config('ai-translator.ai.prompt_custom_system_file_path') ?? __DIR__ . '/prompt-system.txt');

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
                        if (isset($strings[$referenceKey]) && !empty($strings[$referenceKey])) {
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
            Log::debug("AIProvider: No translation context available or empty");
        }

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguageObj->name,
            'targetLanguage' => $this->targetLanguageObj->name,
            'additionalRules' => count($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguageObj->name}:\n" . implode("\n", $this->additionalRules) : '',
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
        $userPrompt = file_get_contents(config('ai-translator.ai.prompt_custom_user_file_path') ?? __DIR__ . '/prompt-user.txt');

        $replaces = array_merge($replaces, [
            // Options
            'options.disablePlural' => config('ai-translator.disable_plural', false) ? 'true' : 'false',

            // Data
            'sourceLanguage' => $this->sourceLanguageObj->name,
            'targetLanguage' => $this->targetLanguageObj->name,
            'filename' => $this->filename,
            'parentKey' => basename($this->filename, '.php'),
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
     * 번역 완료 콜백 설정
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
     * @param callable $callback Callback function that receives prompt text and PromptType
     */
    public function setOnPromptGenerated(?callable $callback): self
    {
        $this->onPromptGenerated = $callback;
        return $this;
    }

    /**
     * 문자열 번역
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

                // 번역이 완료된 후 최종 토큰 사용량 전달
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
                Log::critical("AIProvider: Error during translation", [
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
        return match ($this->configProvider) {
            'anthropic' => $this->getTranslatedObjectsFromAnthropic(),
            'openai' => $this->getTranslatedObjectsFromOpenAI(),
            'fake' => $this->getTranslatedObjectsFromFake(),
            default => throw new \Exception("Provider {$this->configProvider} is not supported."),
        };
    }

    protected function getTranslatedObjectsFromOpenAI(): array
    {
        $client = new OpenAIClient(config('ai-translator.ai.api_key'));
        $totalItems = count($this->strings);

        // Initialize response parser
        $responseParser = new AIResponseParser($this->onTranslated);

        // Prepare request data
        $requestData = [
            'model' => $this->configModel,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->getSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->getUserPrompt(),
                ],
            ],
            'temperature' => config('ai-translator.ai.temperature', 0),
            'stream' => true,
        ];

        // Response text buffer
        $responseText = '';

        // Execute streaming request
        if (!config('ai-translator.ai.disable_stream', false)) {
            $response = $client->createChatStream(
                $requestData,
                function ($chunk, $data) use (&$responseText, $responseParser) {
                    // Extract text content
                    if (isset($data['choices'][0]['delta']['content'])) {
                        $content = $data['choices'][0]['delta']['content'];
                        $responseText .= $content;

                        // Parse response text to extract translated items
                        $responseParser->parse($responseText);

                        // Call progress callback with current response
                        if ($this->onProgress) {
                            ($this->onProgress)($content, $responseParser->getTranslatedItems());
                        }
                    }
                }
            );
        } else {
            $response = $client->createChatStream($requestData, null);
            $responseText = $response['choices'][0]['message']['content'];
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

            // 토큰 사용량 콜백 호출 (설정된 경우)
            if ($this->onTokenUsage) {
                ($this->onTokenUsage)($this->getTokenUsage());
            }
        }

        return $responseParser->getTranslatedItems();
    }

    protected function getTranslatedObjectsFromFake(): array
    {
        $items = [];
        foreach ($this->strings as $key => $value) {
            $localized = new LocalizedString();
            $localized->key = $key;
            $localized->translated = '[' . $this->targetLanguageObj->code . '] ' . $value;
            $items[] = $localized;
        }

        // Simulate minimal token usage for offline mode
        $this->inputTokens = count($this->strings);
        $this->outputTokens = count($this->strings);
        $this->totalTokens = $this->inputTokens + $this->outputTokens;

        if ($this->onTranslated) {
            foreach ($items as $item) {
                ($this->onTranslated)($item, TranslationStatus::STARTED, $items);
                ($this->onTranslated)($item, TranslationStatus::COMPLETED, $items);
            }
        }

        if ($this->onTokenUsage) {
            $tokenUsage = $this->getTokenUsage();
            $tokenUsage['final'] = true;
            ($this->onTokenUsage)($tokenUsage);
        }

        return $items;
    }

    protected function getTranslatedObjectsFromAnthropic(): array
    {
        $client = new AnthropicClient(config('ai-translator.ai.api_key'));
        $useExtendedThinking = config('ai-translator.ai.use_extended_thinking', false);
        $totalItems = count($this->strings);
        $debugMode = config('app.debug', false);

        // 토큰 사용량 초기화
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->totalTokens = 0;

        // Initialize response parser with debug mode enabled in development
        $responseParser = new AIResponseParser($this->onTranslated, $debugMode);

        if ($debugMode) {
            Log::debug('AIProvider: Starting translation with Anthropic', [
                'model' => $this->configModel,
                'source_language' => $this->sourceLanguageObj->name,
                'target_language' => $this->targetLanguageObj->name,
                'extended_thinking' => $useExtendedThinking,
            ]);
        }

        // Prepare request data
        $requestData = [
            'model' => $this->configModel,
            'messages' => [
                ['role' => 'user', 'content' => $this->getUserPrompt()],
            ],
            'system' => [
                [
                    'type' => 'text',
                    'text' => $this->getSystemPrompt(),
                    'cache_control' => [
                        'type' => 'ephemeral',
                    ],
                ]
            ],
        ];

        $defaultMaxTokens = 4096;

        if (preg_match('/^claude\-3\-5\-/', $this->configModel)) {
            $defaultMaxTokens = 8192;
        } elseif (preg_match('/^claude\-3\-7\-/', $this->configModel)) {
            // @TODO: if add betas=["output-128k-2025-02-19"], then 128000
            $defaultMaxTokens = 64000;
        }

        // Set up Extended Thinking
        if ($useExtendedThinking && preg_match('/^claude\-3\-7\-/', $this->configModel)) {
            $requestData['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => 10000,
            ];
        }

        $requestData['max_tokens'] = (int) config('ai-translator.ai.max_tokens', $defaultMaxTokens);

        // verify options before request
        if (isset($requestData['thinking']) && $requestData['max_tokens'] < $requestData['thinking']['budget_tokens']) {
            throw new \Exception("Max tokens is less than thinking budget tokens. Please increase max tokens. Current max tokens: {$requestData['max_tokens']}, Thinking budget tokens: {$requestData['thinking']['budget_tokens']}");
        }

        // Response text buffer
        $responseText = '';
        $detectedXml = '';
        $translatedItems = [];
        $processedKeys = [];
        $inThinkingBlock = false;
        $currentThinkingContent = '';

        // Execute streaming request
        if (!config('ai-translator.ai.disable_stream', false)) {
            $response = $client->messages()->createStream(
                $requestData,
                function ($chunk, $data) use (&$responseText, $responseParser, &$inThinkingBlock, &$currentThinkingContent, $debugMode, &$detectedXml, &$translatedItems, &$processedKeys, $totalItems) {
                    // 토큰 사용량 추적
                    $this->trackTokenUsage($data);

                    // Skip if data is null or not an array
                    if (!is_array($data)) {
                        return;
                    }

                    // Handle content_block_start event
                    if ($data['type'] === 'content_block_start') {
                        if (isset($data['content_block']['type']) && $data['content_block']['type'] === 'thinking') {
                            $inThinkingBlock = true;
                            $currentThinkingContent = '';

                            // Call thinking start callback
                            if ($this->onThinkingStart) {
                                ($this->onThinkingStart)();
                            }
                        }
                    }

                    // Process thinking delta
                    if (
                        $data['type'] === 'content_block_delta' &&
                        isset($data['delta']['type']) && $data['delta']['type'] === 'thinking_delta' &&
                        isset($data['delta']['thinking'])
                    ) {
                        $thinkingDelta = $data['delta']['thinking'];
                        $currentThinkingContent .= $thinkingDelta;

                        // Call thinking callback
                        if ($this->onThinking) {
                            ($this->onThinking)($thinkingDelta);
                        }
                    }

                    // Handle content_block_stop event
                    if ($data['type'] === 'content_block_stop') {
                        // If we're ending a thinking block
                        if ($inThinkingBlock) {
                            $inThinkingBlock = false;

                            // Call thinking end callback
                            if ($this->onThinkingEnd) {
                                ($this->onThinkingEnd)($currentThinkingContent);
                            }
                        }
                    }

                    // Extract text content (content_block_delta event with text_delta)
                    if (
                        $data['type'] === 'content_block_delta' &&
                        isset($data['delta']['type']) && $data['delta']['type'] === 'text_delta' &&
                        isset($data['delta']['text'])
                    ) {
                        $text = $data['delta']['text'];
                        $responseText .= $text;

                        // Parse XML
                        $previousItemCount = count($responseParser->getTranslatedItems());
                        $responseParser->parseChunk($text);
                        $currentItems = $responseParser->getTranslatedItems();
                        $currentItemCount = count($currentItems);

                        // 새로운 번역 항목이 추가됐는지 확인
                        if ($currentItemCount > $previousItemCount) {
                            $newItems = array_slice($currentItems, $previousItemCount);
                            $translatedItems = $currentItems; // 전체 번역 결과 업데이트
    
                            // 새 번역 항목 각각에 대해 콜백 호출
                            foreach ($newItems as $index => $newItem) {
                                // Skip already processed keys
                                if (isset($processedKeys[$newItem->key])) {
                                    continue;
                                }

                                $processedKeys[$newItem->key] = true;
                                $translatedCount = count($processedKeys);

                                if ($this->onTranslated) {
                                    // Only call with 'completed' status for completed translations
                                    if ($newItem->translated) {
                                        ($this->onTranslated)($newItem, TranslationStatus::COMPLETED, $translatedItems);
                                    }

                                    if ($debugMode) {
                                        Log::debug('AIProvider: Calling onTranslated callback', [
                                            'key' => $newItem->key,
                                            'status' => $newItem->translated ? TranslationStatus::COMPLETED : TranslationStatus::STARTED,
                                            'translated_count' => $translatedCount,
                                            'total_count' => $totalItems,
                                            'translated_text' => $newItem->translated
                                        ]);
                                    }
                                }
                            }
                        }

                        // Call progress callback with current response
                        if ($this->onProgress) {
                            ($this->onProgress)($responseText, $currentItems);
                        }
                    }

                    // Handle message_start event
                    if ($data['type'] === 'message_start' && isset($data['message']['content'])) {
                        // If there's initial content in the message
                        foreach ($data['message']['content'] as $content) {
                            if (isset($content['text'])) {
                                $text = $content['text'];
                                $responseText .= $text;

                                // Collect XML fragments in debug mode (without logging)
                                if (
                                    $debugMode && (
                                        strpos($text, '<translations') !== false ||
                                        strpos($text, '<item') !== false ||
                                        strpos($text, '<trx') !== false ||
                                        strpos($text, 'CDATA') !== false
                                    )
                                ) {
                                    $detectedXml .= $text;
                                }

                                $responseParser->parseChunk($text);

                                // Call progress callback with current response
                                if ($this->onProgress) {
                                    ($this->onProgress)($responseText, $responseParser->getTranslatedItems());
                                }
                            }
                        }
                    }
                }
            );

            // 토큰 사용량 최종 확인
            if (isset($response['usage'])) {
                if (isset($response['usage']['input_tokens'])) {
                    $this->inputTokens = (int) $response['usage']['input_tokens'];
                }

                if (isset($response['usage']['output_tokens'])) {
                    $this->outputTokens = (int) $response['usage']['output_tokens'];
                }

                $this->totalTokens = $this->inputTokens + $this->outputTokens;
            }

            // 디버깅: 최종 응답 구조 로깅
            if ($debugMode) {
                Log::debug("Final response structure", [
                    'has_usage' => isset($response['usage']),
                    'usage' => $response['usage'] ?? null,
                ]);
            }

            // 토큰 사용량 로깅
            $this->logTokenUsage();
        } else {
            $response = $client->messages()->create($requestData);

            // 토큰 사용량 추적 (스트리밍이 아닌 경우)
            if (isset($response['usage'])) {
                if (isset($response['usage']['input_tokens'])) {
                    $this->inputTokens = $response['usage']['input_tokens'];
                }
                if (isset($response['usage']['output_tokens'])) {
                    $this->outputTokens = $response['usage']['output_tokens'];
                }
                $this->totalTokens = $this->inputTokens + $this->outputTokens;
            }

            $responseText = $response['content'][0]['text'];
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

            // 토큰 사용량 콜백 호출 (설정된 경우)
            if ($this->onTokenUsage) {
                $tokenUsage = $this->getTokenUsage();
                $tokenUsage['final'] = false; // 중간 업데이트임을 표시
                ($this->onTokenUsage)($tokenUsage);
            }

            // 토큰 사용량 로깅
            $this->logTokenUsage();
        }

        // Process final response
        if (empty($responseParser->getTranslatedItems()) && !empty($responseText)) {
            if ($debugMode) {
                Log::debug('AIProvider: No items parsed from response, trying final parse', [
                    'response_length' => strlen($responseText),
                    'detected_xml_length' => strlen($detectedXml),
                    'response_text' => $responseText,
                    'detected_xml' => $detectedXml
                ]);
            }

            // Try parsing the entire response
            $responseParser->parse($responseText);
            $finalItems = $responseParser->getTranslatedItems();

            // Process last parsed items with callback
            if (!empty($finalItems) && $this->onTranslated) {
                foreach ($finalItems as $item) {
                    if (!isset($processedKeys[$item->key])) {
                        $processedKeys[$item->key] = true;
                        $translatedCount = count($processedKeys);

                        // Don't call completed status in final parsing
                        if ($translatedCount === 1) {
                            ($this->onTranslated)($item, TranslationStatus::STARTED, $finalItems);
                        }
                    }
                }
            }
        }

        return $responseParser->getTranslatedItems();
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
            'cache_creation_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'total_tokens' => $this->totalTokens
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
     * API 응답 데이터에서 토큰 사용량 정보를 추적합니다.
     *
     * @param array $data API 응답 데이터
     */
    protected function trackTokenUsage(array $data): void
    {
        // 디버그 모드인 경우 전체 이벤트 데이터 로깅
        if (config('app.debug', false) || config('ai-translator.debug', false)) {
            $eventType = $data['type'] ?? 'unknown';
            if (in_array($eventType, ['message_start', 'message_stop', 'message_delta'])) {
                Log::debug("Anthropic API Event: {$eventType}", json_decode(json_encode($data), true));
            }
        }

        // message_start 이벤트에서 토큰 정보 추출
        if (isset($data['type']) && $data['type'] === 'message_start') {
            // 유형 1: 루트 레벨에 usage가 있는 경우
            if (isset($data['usage'])) {
                $this->extractTokensFromUsage($data['usage']);
            }

            // 유형 2: message 안에 usage가 있는 경우
            if (isset($data['message']['usage'])) {
                $this->extractTokensFromUsage($data['message']['usage']);
            }

            // 유형 3: message.content_policy.input_tokens, output_tokens가 있는 경우
            if (isset($data['message']['content_policy'])) {
                if (isset($data['message']['content_policy']['input_tokens'])) {
                    $this->inputTokens = $data['message']['content_policy']['input_tokens'];
                }
                if (isset($data['message']['content_policy']['output_tokens'])) {
                    $this->outputTokens = $data['message']['content_policy']['output_tokens'];
                }
                $this->totalTokens = $this->inputTokens + $this->outputTokens;
            }

            // 토큰 사용량 정보를 실시간으로 업데이트하기 위한 콜백 호출
            if ($this->onTokenUsage) {
                $tokenUsage = $this->getTokenUsage();
                $tokenUsage['final'] = false; // 중간 업데이트임을 표시
                ($this->onTokenUsage)($tokenUsage);
            }
        }

        // message_stop 이벤트에서 토큰 정보 추출
        if (isset($data['type']) && $data['type'] === 'message_stop') {
            // 최종 토큰 사용량 정보 업데이트
            if (isset($data['usage'])) {
                $this->extractTokensFromUsage($data['usage']);
            }

            // 중간 업데이트이므로 토큰 사용량 콜백 호출
            if ($this->onTokenUsage) {
                $tokenUsage = $this->getTokenUsage();
                $tokenUsage['final'] = false; // 중간 업데이트임을 표시
                ($this->onTokenUsage)($tokenUsage);
            }
        }
    }
    /**
     * usage 객체에서 토큰 정보를 추출합니다.
     *
     * @param array $usage 토큰 사용량 정보
     */
    protected function extractTokensFromUsage(array $usage): void
    {
        if (isset($usage['input_tokens'])) {
            $this->inputTokens = (int) $usage['input_tokens'];
        }

        if (isset($usage['output_tokens'])) {
            $this->outputTokens = (int) $usage['output_tokens'];
        }

        $this->totalTokens = $this->inputTokens + $this->outputTokens;
    }

    /**
     * 현재 사용 중인 AI 모델을 반환합니다.
     */
    public function getModel(): string
    {
        return $this->configModel;
    }
}
