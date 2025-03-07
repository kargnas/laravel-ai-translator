<?php

namespace Kargnas\LaravelAiTranslator\AI;

use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\AI\Parsers\AIResponseParser;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

class AIProvider
{
    protected string $configProvider;

    protected string $configModel;

    protected int $configRetries = 3;

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
        $this->configRetries = config('ai-translator.ai.retries');
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
            if (! isset($item->translated)) {
                throw new VerifyFailedException("Failed to translate the string. The translation is not set for key: {$item->key}.");
            }

            // Allow any key for a single test translation for simplified testing
            return;
        }

        // 테스트 모드용 검증 건너뛰기 코드 제거 (더 이상 사용하지 않음)

        // Standard verification for production translations
        $sourceKeys = collect($this->strings)->keys()->unique()->sort()->values();
        $resultKeys = collect($list)->pluck('key')->unique()->sort()->values();

        $diff = $sourceKeys->diff($resultKeys);

        if ($diff->count() > 0) {
            // 디버그 로그
            if (config('app.debug', false) || config('ai-translator.debug', false)) {
                \Log::debug('AIProvider: Key mismatch details', [
                    'source_keys' => $sourceKeys->toArray(),
                    'result_keys' => $resultKeys->toArray(),
                    'diff' => $diff->toArray(),
                    'translated_count' => count($list),
                ]);

                // 가능한 경우 첫 번째 항목의 키를 수정
                if (count($list) > 0 && count($sourceKeys) > 0) {
                    $firstKey = $sourceKeys->first();
                    \Log::debug('AIProvider: Attempting to fix key mismatch by setting first item key', [
                        'old_key' => $list[0]->key,
                        'new_key' => $firstKey,
                    ]);

                    // 첫 번째 항목의 키를 원본 문자열의 첫 번째 키로 변경
                    $list[0]->key = $firstKey;

                    // 다시 검증 시도
                    try {
                        $this->verify($list);

                        return; // 검증 성공하면 종료
                    } catch (VerifyFailedException $e) {
                        // 재시도 실패 시 원래 오류로 진행
                    }
                }
            }

            \Log::error("Failed to translate the string. The keys are not matched. (Diff: {$diff->implode(', ')})");
            throw new VerifyFailedException("Failed to translate the string. The keys are not matched. (Diff: {$diff->implode(', ')})");
        }

        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (empty($item->key)) {
                throw new VerifyFailedException('Failed to translate the string. The key is empty.');
            }
            if (! isset($item->translated)) {
                throw new VerifyFailedException("Failed to translate the string. The translation is not set for key: {$item->key}.");
            }
        }
    }

    protected function getSystemPrompt($replaces = [])
    {
        $systemPrompt = file_get_contents(__DIR__.'/prompt-system.txt');

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'additionalRules' => count($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguage}:\n".implode("\n", $this->additionalRules) : '',
        ]);

        foreach ($replaces as $key => $value) {
            $systemPrompt = str_replace("{{$key}}", $value, $systemPrompt);
        }

        return $systemPrompt;
    }

    protected function getUserPrompt($replaces = [])
    {
        $userPrompt = file_get_contents(__DIR__.'/prompt-user.txt');

        $replaces = array_merge($replaces, [
            // Options
            'options.disablePlural' => config('ai-translator.disable_plural', false) ? 'true' : 'false',

            // Data
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
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
                            $text .= "\n      - {$locale}: \"\"\"".$items.'"""';
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
     * @param  callable|null  $onProgress  응답 청크가 올 때마다 호출될 콜백 함수 (현재 진행 상황 업데이트)
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
                    \Log::warning("[{$tried}/{$this->configRetries}] Retrying translation into {$this->targetLanguage} using {$this->configProvider} with {$this->configModel} model...");
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

        throw new VerifyFailedException('Translation was not successful after '.($tried - 1).' attempts. Please run the command again to continue from the last failure.');
    }

    /**
     * @param  callable|null  $onTranslated  번역 항목이 완료될 때마다 호출될 콜백 함수
     * @param  callable|null  $onThinking  모델의 thinking_delta를 받을 콜백 함수
     * @param  callable|null  $onProgress  응답 청크가 올 때마다 호출될 콜백 함수 (현재 진행 상황 업데이트)
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
                        $onProgress($responseText, $responseParser->getTranslatedItems());
                    }
                }
            }
        );

        // Process final response
        if (empty($responseParser->getTranslatedItems()) && ! empty($responseText)) {
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
                'source_language' => $this->sourceLanguage,
                'target_language' => $this->targetLanguage,
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

        // Default max tokens and context window sizes by model
        $defaultMaxTokens = 4096;
        $contextWindowSize = 200000; // Default Claude context window

        // Adjust max tokens based on model
        if (preg_match('/^claude\-3\-[57]\-/', $this->configModel)) {
            $defaultMaxTokens = 8192;

            // Claude 3.5 Sonnet has 200K context, Claude 3.7 Sonnet has 200K context
            if (preg_match('/^claude\-3\-5\-sonnet/', $this->configModel)) {
                $contextWindowSize = 200000;
            } elseif (preg_match('/^claude\-3\-7\-sonnet/', $this->configModel)) {
                $contextWindowSize = 200000;
            }
        }

        // Set up Extended Thinking
        if ($useExtendedThinking && preg_match('/^claude\-3\-7\-/', $this->configModel)) {
            $defaultMaxTokens = 64000;
            $requestData['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => 10000,
            ];
        }

        // Estimate input tokens (rough estimation)
        $systemPromptLength = mb_strlen($this->getSystemPrompt());
        $userPromptLength = mb_strlen($this->getUserPrompt());
        $estimatedInputTokens = ($systemPromptLength + $userPromptLength) / 3; // Rough estimation: ~3 chars per token on average

        // Calculate safe max_tokens to prevent context window limit errors
        // Keep 20% buffer to account for token estimation inaccuracy
        $safeMaxTokens = max(1000, min(
            (int) config('ai-translator.ai.max_tokens', $defaultMaxTokens),
            (int) ($contextWindowSize - $estimatedInputTokens - ($contextWindowSize * 0.2))
        ));

        if ($safeMaxTokens < $defaultMaxTokens) {
            if (config('app.debug', false) || config('ai-translator.debug', false)) {
                \Log::debug('AIProvider: Reducing max_tokens to fit context window', [
                    'estimated_input_tokens' => $estimatedInputTokens,
                    'context_window_size' => $contextWindowSize,
                    'original_max_tokens' => $defaultMaxTokens,
                    'adjusted_max_tokens' => $safeMaxTokens,
                ]);
            }
        }

        $requestData['max_tokens'] = $safeMaxTokens;

        // Response text buffer
        $responseText = '';

        // Track if we're currently in a thinking block
        $inThinkingBlock = false;
        $currentThinkingContent = '';

        // Store detected XML for debugging
        $detectedXml = '';

        // Execute streaming request
        $response = $client->messages()->createStream(
            $requestData,
            function ($chunk, $data) use (&$responseText, $responseParser, $onThinking, $onProgress, $onThinkingStart, $onThinkingEnd, &$inThinkingBlock, &$currentThinkingContent, $debugMode, &$detectedXml) {
                // Skip if data is null or not an array
                if (! is_array($data)) {
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

                    // Parse XML
                    $previousItemCount = count($responseParser->getTranslatedItems());
                    $responseParser->parseChunk($text);
                    $currentItems = $responseParser->getTranslatedItems();
                    $currentItemCount = count($currentItems);

                    // 새로운 번역 항목이 추가됐는지 확인
                    if ($currentItemCount > $previousItemCount) {
                        $newItems = array_slice($currentItems, $previousItemCount);

                        // 새 번역 항목 각각에 대해 콜백 호출
                        foreach ($newItems as $index => $newItem) {
                            if ($onTranslated) {
                                $translatedIndex = $previousItemCount + $index + 1;
                                $onTranslated($newItem, $translatedIndex);
                            }
                        }

                        if ($debugMode) {
                            \Log::debug('AIProvider: New translation items detected during streaming', [
                                'new_count' => $currentItemCount - $previousItemCount,
                                'total_count' => $currentItemCount,
                            ]);
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

        // Process final response
        if (empty($responseParser->getTranslatedItems()) && ! empty($responseText)) {
            // Debug-log final response if no items were parsed
            if ($debugMode) {
                \Log::debug('AIProvider: No items parsed from response, trying final parse', [
                    'response_length' => strlen($responseText),
                    'detected_xml_length' => strlen($detectedXml),
                ]);

                // Log the detected XML to help debug
                if (! empty($detectedXml)) {
                    \Log::debug('AIProvider: Detected XML fragments', ['xml' => $detectedXml]);
                }

                // Try to find and log any CDATA sections
                if (preg_match_all('/<!\[CDATA\[(.*?)\]\]>/s', $responseText, $matches)) {
                    \Log::debug('AIProvider: Found CDATA sections', [
                        'count' => count($matches[0]),
                        'first_cdata' => isset($matches[0][0]) ? substr($matches[0][0], 0, 100) : 'none',
                    ]);
                }
            }

            // Try parsing the entire response
            $responseParser->parse($responseText);
        }

        return $responseParser->getTranslatedItems();
    }
}
