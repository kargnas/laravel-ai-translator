<?php

namespace Kargnas\LaravelAiTranslator\AI;


use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;

class OpenAbstractAIProvider extends AbstractAIProvider
{
    public function translate(): ?array {
        $message = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'temperature' => 0.3,
            'response_format' => [
                'type' => 'json_object'
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->userPrompt(),
                ],
            ],
        ];

        $client = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);

        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            RequestOptions::JSON => $message,
        ]);

        $res = $response->getBody()->getContents();
        $responseText = json_decode($res, true)['choices'][0]['message']['content'];

        return json_decode($responseText, true);
    }
}
