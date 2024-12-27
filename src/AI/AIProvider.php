<?php

namespace Kargnas\LaravelAiTranslator\AI;


use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use OpenAI;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

class AIProvider
{
    protected string $configProvider;
    protected string $configModel;
    protected int $configRetries = 3;

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
        $sourceKeys = collect($this->strings)->keys()->unique()->sort()->values();
        $resultKeys = collect($list)->pluck('key')->unique()->sort()->values();

        $diff = $sourceKeys->diff($resultKeys);

        if ($diff->count() > 0) {
            \Log::error("Failed to translate the string. The keys are not matched. (Diff: {$diff->implode(', ')})");
            throw new VerifyFailedException("Failed to translate the string. The keys are not matched. (Diff: {$diff->implode(', ')})");
        }

        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (empty($item->key)) {
                throw new VerifyFailedException("Failed to translate the string. The key is empty.");
            }
            if (!isset($item->translated)) {
                throw new VerifyFailedException("Failed to translate the string. The translation is not set for key: {$item->key}.");
            }
        }
    }

    protected function getSystemPrompt($replaces = [])
    {
        $systemPrompt = file_get_contents(__DIR__ . '/prompt-system.txt');

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'additionalRules' => sizeof($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguage}:\n" . implode("\n", $this->additionalRules) : '',
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
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'filename' => $this->filename,
            'parentKey' => basename($this->filename, '.php'),
            'keys' => collect($this->strings)->keys()->implode("`, `"),
            'strings' => collect($this->strings)->map(function ($string, $key) {
                if (is_string($string)) {
                    return "  - `{$key}`: \"\"\"{$string}\"\"\"";
                } else {
                    $text = "  - `{$key}`: \"\"\"{$string['text']}\"\"\"";
                    if (isset($string['context'])) {
                        $text .= "\n    - Context: \"\"\"{$string['context']}\"\"\"";
                    }
                    if (isset($string['references']) && sizeof($string['references']) > 0) {
                        $text .= "\n    - References:";
                        foreach ($string['references'] as $locale => $items) {
                            $text .= "\n      - {$locale}: \"\"\"" . $items . "\"\"\"";
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
     * @return LocalizedString[]
     * @throws \Exception
     */
    public function getTranslatedObjects(): array
    {
        return match ($this->configProvider) {
            'anthropic' => $this->getTranslatedObjectsFromAnthropic(),
            'openai' => $this->getTranslatedObjectsFromOpenAI(),
            default => throw new \Exception("Provider {$this->configProvider} is not supported."),
        };
    }

    protected function getTranslatedObjectsFromOpenAI(): array
    {
        $client = OpenAI::client(config('ai-translator.ai.api_key'));

        $response = $client->chat()->create([
            'model' => $this->configModel,
            'messages' => [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $this->getUserPrompt()],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'translations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'key' => ['type' => 'string', 'description' => 'The key of the string to translate'],
                                        'translated' => ['type' => 'string', 'description' => 'The translated string in ' . $this->targetLanguage . ' (Encode double quotes to avoid errors)'],
                                    ],
                                    'required' => ['key', 'translated'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['translations'],
                        'additionalProperties' => false,
                    ],
                    'strict' => true,
                ],
            ],
        ]);

        $content = json_decode($response->choices[0]->message->content, true);

        return array_map(function ($item) {
            $localizedString = new LocalizedString();
            $localizedString->key = $item['key'];
            $localizedString->translated = $item['translated'];
            return $localizedString;
        }, $content['translations']);
    }

    protected function getTranslatedObjectsFromAnthropic(): array
    {
        $client = new AnthropicClient(config('ai-translator.ai.api_key'));

        $response = $client->messages()->create([
            'model' => $this->configModel,
            'max_tokens' => (int) max(config('ai-translator.ai.max_tokens'), 4096),
            'messages' => [
                ['role' => 'user', 'content' => $this->getUserPrompt()],
            ],
            'system' => $this->getSystemPrompt(),
            'tools' => [
                [
                    'name' => 'translate',
                    'description' => 'Translate the given strings to the target language',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'translations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'key' => [
                                            'type' => 'string',
                                            'description' => 'The key of the string to translate',
                                        ],
                                        'translated' => [
                                            'type' => 'string',
                                            'description' => 'The translated string in ' . $this->targetLanguage . ' (Encode double quotes to avoid errors)',
                                        ],
                                    ],
                                    'required' => ['key', 'translated'],
                                ],
                            ],
                        ],
                        'required' => ['translations'],
                    ],
                ],
            ],
            'tool_choice' => ['type' => 'tool', 'name' => 'translate'],
        ]);

        $content = $response['content'][0]['input'];

        if (isset($content['translations'])) {
            $content = $content['translations'];
        }

        if (!isset($content['translations']) && is_string($content)) {
            $content = json_decode($content, true);
        }

        return array_map(function ($item) {
            $localizedString = new LocalizedString();
            $localizedString->key = $item['key'];
            $localizedString->translated = $item['translated'];
            return $localizedString;
        }, $content);
    }

    /**
     * @return LocalizedString[]
     * @throws VerifyFailedException
     */
    public function translate(): array
    {
        $tried = 1;
        do {
            try {
                if ($tried > 1) {
                    \Log::warning("[{$tried}/{$this->configRetries}] Retrying translation into {$this->targetLanguage} using {$this->configProvider} with {$this->configModel} model...");
                }

                $items = $this->getTranslatedObjects();
                $this->verify($items);
                return $items;
            } catch (VerifyFailedException $e) {
                \Log::error($e->getMessage());
            } catch (\Exception $e) {
                \Log::critical($e->getMessage());
            }
        } while (++$tried <= $this->configRetries);

        throw new VerifyFailedException("Translation was not successful after " . ($tried - 1) . " attempts. Please run the command again to continue from the last failure.");
    }
}
