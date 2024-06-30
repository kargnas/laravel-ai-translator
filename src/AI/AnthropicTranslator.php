<?php

namespace Kargnas\LaravelAiTranslator\AI;


use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;

class AnthropicTranslator
{
    public function __construct(
        public string $key,
        public string $string,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array $additionalRules = [],
        public int    $retries = 3,
    ) {
    }

    protected function translateInternally(): ?array {
        $systemPrompt = file_get_contents(__DIR__ . '/anthropic-prompt-system.txt');
        $userPrompt = file_get_contents(__DIR__ . '/anthropic-prompt-user.txt');

        $systemPrompt = str_replace('{sourceLanguage}', $this->sourceLanguage, $systemPrompt);
        $systemPrompt = str_replace('{targetLanguage}', $this->targetLanguage, $systemPrompt);

        $additionalRules = sizeof($this->additionalRules) > 0 ? "- " . implode("\n- ", $this->additionalRules) : '';
        $systemPrompt = str_replace('{additionalRules}', $additionalRules, $systemPrompt);

        $userPrompt = str_replace('{key}', $this->key, $userPrompt);
        $userPrompt = str_replace('{string}', $this->string, $userPrompt);

        $starting = '{"key": "';

        $message = [
            'model' => 'claude-3-5-sonnet-20240620',
            'max_tokens' => 4000,
            'temperature' => 0,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
                [
                    'role' => 'assistant',
                    'content' => $starting,
                ],
            ],
        ];

        $client = new Client([
            'headers' => [
                'x-api-key' => env('ANTHROPIC_API_KEY'),
                'anthropic-version' => '2023-06-01',
            ],
        ]);

        $response = $client->post('https://api.anthropic.com/v1/messages', [
            RequestOptions::JSON => $message,
        ]);

        $res = $response->getBody()->getContents();
        $responseText = json_decode($res, true)['content'][0]['text'];

        return json_decode($starting . $responseText, true);
    }

    public function verify($result): void {
        if (!$result) throw new VerifyFailedException('GPT did not return any content in JSON');

        $mandatoryKeys = [
            'key',
            'translated',
        ];

        foreach ($mandatoryKeys as $key) {
            if (!isset($result[$key])) {
                throw new VerifyFailedException("GPT did not return valid JSON. Missing key: $key");
            }
        }
    }

    public function translate(): array {
        $tried = 1;
        do {
            try {
                \Log::debug("Translating {$this->key} to {$this->targetLanguage} ({$tried}/{$this->retries})");

                $result = $this->translateInternally();
                $this->verify($result);
                return $result;
            } catch (VerifyFailedException $e) {
                \Log::debug($result);
                \Log::error($e->getMessage());
            }
        } while (++$tried <= $this->retries);

        throw new VerifyFailedException('Failed to translate the string.');
    }
}
