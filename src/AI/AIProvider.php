<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\AI\Parsers\AIResponseParser;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
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

    public function __construct(
        public string $filename,
        public array $strings,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array $additionalRules = [],
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

        // Create Language objects
        $this->sourceLanguageObj = Language::fromCode($this->sourceLanguage);
        $this->targetLanguageObj = Language::fromCode($this->targetLanguage);

        // Get additional rules from LanguageRules
        $this->additionalRules = array_merge(
            $this->additionalRules,
            LanguageRules::getAdditionalRules($this->targetLanguageObj)
        );

        \Log::info("AIProvider initiated: Source language = {$this->sourceLanguageObj->name} ({$this->sourceLanguageObj->code}), Target language = {$this->targetLanguageObj->name} ({$this->targetLanguageObj->code})");
        \Log::info("AIProvider additional rules: " . json_encode($this->additionalRules));
    }

    protected function getFilePrefix(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }

    protected function verify(array $list): void
    {
        // For test-translate command with a single string, we'll be more lenient
        // as this is for testing only rather than production translation
        if (count($this->strings) === 1 && count($list) === 1) {
            $item = $list[0];
            /** @var LocalizedString $item */
            if (empty($item->key)) {
                throw new VerifyFailedException('Failed to translate the string. The key is empty.');
            }
            if (!isset($item->translated)) {
                throw new VerifyFailedException("Failed to translate the string. The translation is not set for key: {$item->key}.");
            }

            // Allow any key for a single test translation for simplified testing
            return;
        }

        // Standard verification for production translations
        $sourceKeys = collect($this->strings)->keys()->unique()->sort()->values();
        $resultKeys = collect($list)->pluck('key')->unique()->sort()->values();

        $missingKeys = $sourceKeys->diff($resultKeys);
        $extraKeys = $resultKeys->diff($sourceKeys);
        $hasValidTranslations = false;

        // 번역된 항목들 중에서 유효한 번역이 하나라도 있는지 확인
        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (!empty($item->key) && isset($item->translated) && $sourceKeys->contains($item->key)) {
                $hasValidTranslations = true;

                // 코멘트가 있는 경우 경고 로그 출력
                if (!empty($item->comment)) {
                    \Log::warning("Translation comment for key '{$item->key}': {$item->comment}");
                }

                break;
            }
        }

        // 유효한 번역이 하나도 없는 경우에만 예외 발생
        if (!$hasValidTranslations) {
            throw new VerifyFailedException('No valid translations found in the response.');
        }

        // 누락된 키가 있는 경우 경고
        if ($missingKeys->count() > 0) {
            \Log::warning("Some keys were not translated: {$missingKeys->implode(', ')}");
        }

        // 추가로 생성된 키가 있는 경우 경고
        if ($extraKeys->count() > 0) {
            \Log::warning("Found unexpected translation keys: {$extraKeys->implode(', ')}");
        }

        // 검증이 완료된 후 원래 키로 복원
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
        $systemPrompt = file_get_contents(__DIR__ . '/prompt-system.txt');

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguageObj->name,
            'targetLanguage' => $this->targetLanguageObj->name,
            'additionalRules' => count($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguageObj->name}:\n" . implode("\n", $this->additionalRules) : '',
        ]);

        foreach ($replaces as $key => $value) {
            $systemPrompt = str_replace("{{$key}}", $value, $systemPrompt);
        }

        return $systemPrompt;
    }

    protected function getUserPrompt($replaces = [])
    {
        $userPrompt = file_get_contents(__DIR__ . '/prompt-user.txt');

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
                    if (isset($string['references']) && count($string['references']) > 0) {
                        $text .= "\n    - References:";
                        foreach ($string['references'] as $locale => $items) {
                            $text .= "\n      - {$locale}: \"\"\"" . $items . '"""';
                        }
                    }

                    return $text;
                }
            })->implode("\n"),
        ]);

        foreach ($replaces as $key => $value) {
            $userPrompt = str_replace("{{$key}}", $value, $userPrompt);
        }

        return $userPrompt;
    }

    /**
     * @param  callable|null  $onTranslated  번역 항목이 완료될 때마다 호출될 콜백 함수
     * @param  callable|null  $onThinking  모델의 thinking_delta를 받을 콜백 함수
     * @param  callable|null  $onProgress  응답 청크가 올 때마다 호출될 콜백 함수
     * @param  callable|null  $onThinkingStart  모델의 thinking 블록이 시작될 때 호출될 콜백 함수
     * @param  callable|null  $onThinkingEnd  모델의 thinking 블록이 끝날 때 호출될 콜백 함수
     * @return LocalizedString[]
     *
     * @throws VerifyFailedException
     */
    public function translate(
        ?callable $onTranslated = null,
        ?callable $onThinking = null,
        ?callable $onProgress = null,
        ?callable $onThinkingStart = null,
        ?callable $onThinkingEnd = null
    ): array {
        $tried = 1;
        do {
            try {
                if ($tried > 1) {
                    \Log::warning("[{$tried}/{$this->configRetries}] Retrying translation into {$this->targetLanguageObj->name} using {$this->configProvider} with {$this->configModel} model...");
                }

                $items = $this->getTranslatedObjects($onTranslated, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd);
                $this->verify($items);

                return $items;
            } catch (VerifyFailedException $e) {
                \Log::error($e->getMessage());
            } catch (\Exception $e) {
                \Log::critical($e->getMessage());
            }
        } while (++$tried <= $this->configRetries);

        throw new VerifyFailedException('Translation was not successful after ' . ($tried - 1) . ' attempts. Please run the command again to continue from the last failure.');
    }

    /**
     * @param  callable|null  $onTranslated  번역 항목이 완료될 때마다 호출될 콜백 함수
     * @param  callable|null  $onThinking  모델의 thinking_delta를 받을 콜백 함수
     * @param  callable|null  $onProgress  응답 청크가 올 때마다 호출될 콜백 함수
     * @param  callable|null  $onThinkingStart  모델의 thinking 블록이 시작될 때 호출될 콜백 함수
     * @param  callable|null  $onThinkingEnd  모델의 thinking 블록이 끝날 때 호출될 콜백 함수
     * @return LocalizedString[]
     *
     * @throws \Exception
     */
    public function getTranslatedObjects(
        ?callable $onTranslated = null,
        ?callable $onThinking = null,
        ?callable $onProgress = null,
        ?callable $onThinkingStart = null,
        ?callable $onThinkingEnd = null
    ): array {
        return match ($this->configProvider) {
            'anthropic' => $this->getTranslatedObjectsFromAnthropic($onTranslated, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd),
            'openai' => $this->getTranslatedObjectsFromOpenAI($onTranslated, $onProgress),
            default => throw new \Exception("Provider {$this->configProvider} is not supported."),
        };
    }

    protected function getTranslatedObjectsFromOpenAI(?callable $onTranslated = null, ?callable $onProgress = null): array
    {
        $client = new OpenAIClient(config('ai-translator.ai.api_key'));
        $totalItems = count($this->strings);

        // Initialize response parser
        $responseParser = new AIResponseParser($onTranslated);

        // Prepare request data
        $requestData = [
            'model' => $this->configModel,
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $this->getUserPrompt()],
            ],
            'max_tokens' => (int) max(config('ai-translator.ai.max_tokens'), 4096),
        ];

        // Response text buffer
        $responseText = '';

        // Execute streaming request
        $response = $client->createChatStream(
            $requestData,
            function ($chunk, $data) use (&$responseText, $responseParser, $onProgress) {
                // Extract text content
                if (isset($data['choices'][0]['delta']['content'])) {
                    $content = $data['choices'][0]['delta']['content'];
                    $responseText .= $content;

                    // Parse XML
                    $responseParser->parseChunk($content);

                    // Call progress callback with current response
                    if ($onProgress) {
                        $onProgress($content, $responseParser->getTranslatedItems());
                    }
                }
            }
        );

        // Process final response
        if (empty($responseParser->getTranslatedItems()) && !empty($responseText)) {
            // Try parsing the entire response
            $responseParser->parse($responseText);
        }

        return $responseParser->getTranslatedItems();
    }

    protected function getTranslatedObjectsFromAnthropic(
        ?callable $onTranslated = null,
        ?callable $onThinking = null,
        ?callable $onProgress = null,
        ?callable $onThinkingStart = null,
        ?callable $onThinkingEnd = null
    ): array {
        $client = new AnthropicClient(config('ai-translator.ai.api_key'));
        $useExtendedThinking = config('ai-translator.ai.use_extended_thinking', false);
        $totalItems = count($this->strings);
        $debugMode = config('app.debug', false) || config('ai-translator.debug', false);

        // Initialize response parser with debug mode enabled in development
        $responseParser = new AIResponseParser($onTranslated, $debugMode);

        if ($debugMode) {
            \Log::debug('AIProvider: Starting translation with Anthropic', [
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
            'system' => $this->getSystemPrompt(),
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
                function ($chunk, $data) use (&$responseText, $responseParser, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd, &$inThinkingBlock, &$currentThinkingContent, $debugMode, &$detectedXml, $onTranslated, &$translatedItems, &$processedKeys, $totalItems) {
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
                            if ($onThinkingStart) {
                                $onThinkingStart();
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
                        if ($onThinking) {
                            $onThinking($thinkingDelta);
                        }
                    }

                    // Handle content_block_stop event
                    if ($data['type'] === 'content_block_stop') {
                        // If we're ending a thinking block
                        if ($inThinkingBlock) {
                            $inThinkingBlock = false;

                            // Call thinking end callback
                            if ($onThinkingEnd) {
                                $onThinkingEnd($currentThinkingContent);
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
                                // 이미 처리된 키는 건너뛰기
                                if (isset($processedKeys[$newItem->key])) {
                                    continue;
                                }

                                $processedKeys[$newItem->key] = true;
                                $translatedCount = count($processedKeys);

                                if ($onTranslated) {
                                    // 번역이 완료된 항목에 대해서만 'completed' 상태로 호출
                                    if ($newItem->translated) {
                                        $onTranslated($newItem, TranslationStatus::COMPLETED, $translatedItems);
                                    }

                                    if ($debugMode) {
                                        \Log::debug('AIProvider: Calling onTranslated callback', [
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
                        if ($onProgress) {
                            $onProgress($responseText, $currentItems);
                        }
                    }

                    // Handle message_start event
                    if ($data['type'] === 'message_start' && isset($data['message']['content'])) {
                        // If there's initial content in the message
                        foreach ($data['message']['content'] as $content) {
                            if (isset($content['text'])) {
                                $text = $content['text'];
                                $responseText .= $text;

                                // 디버그 모드에서 XML 조각 수집 (로그 출력 없이)
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
                                if ($onProgress) {
                                    $onProgress($responseText, $responseParser->getTranslatedItems());
                                }
                            }
                        }
                    }
                }
            );
        } else {
            $response = $client->messages()->create($requestData);
            $responseText = $response['content'][0]['text'];
            $responseParser->parse($responseText);
            $onProgress($responseText, $responseParser->getTranslatedItems());
            foreach ($responseParser->getTranslatedItems() as $item) {
                $onTranslated($item, TranslationStatus::STARTED, $responseParser->getTranslatedItems());
                $onTranslated($item, TranslationStatus::COMPLETED, $responseParser->getTranslatedItems());
            }
        }

        // Process final response
        if (empty($responseParser->getTranslatedItems()) && !empty($responseText)) {
            if ($debugMode) {
                \Log::debug('AIProvider: No items parsed from response, trying final parse', [
                    'response_length' => strlen($responseText),
                    'detected_xml_length' => strlen($detectedXml),
                    'response_text' => $responseText,
                    'detected_xml' => $detectedXml
                ]);
            }

            // Try parsing the entire response
            $responseParser->parse($responseText);
            $finalItems = $responseParser->getTranslatedItems();

            // 마지막으로 파싱된 항목들에 대해 콜백 호출
            if (!empty($finalItems) && $onTranslated) {
                foreach ($finalItems as $item) {
                    if (!isset($processedKeys[$item->key])) {
                        $processedKeys[$item->key] = true;
                        $translatedCount = count($processedKeys);
                        // 마지막 파싱에서는 completed 상태를 호출하지 않음
                        if ($translatedCount === 1) {
                            $onTranslated($item, TranslationStatus::STARTED, $finalItems);
                        }
                    }
                }
            }
        }

        return $responseParser->getTranslatedItems();
    }
}
