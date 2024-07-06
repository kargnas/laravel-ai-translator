<?php

namespace Kargnas\LaravelAiTranslator\AI;


use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;

class AnthropicAIProvider extends AbstractAIProvider
{
    public function translate(): ?array {
        $starting = '{"key": "';

        $message = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'temperature' => 0,
            'system' => $this->systemPrompt(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->userPrompt(),
                ],
                [
                    'role' => 'assistant',
                    'content' => $starting,
                ],
            ],
        ];

        $client = new Client([
            'headers' => [
                'x-api-key' => $this->apiKey,
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
}
