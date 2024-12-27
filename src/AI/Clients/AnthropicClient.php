<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

use Illuminate\Support\Facades\Http;

class AnthropicClient
{
    protected string $baseUrl = 'https://api.anthropic.com/v1';
    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function messages()
    {
        return new AnthropicMessages($this);
    }

    public function request(string $method, string $endpoint, array $data = [])
    {
        $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->$method("{$this->baseUrl}/{$endpoint}", $data);

        if (!$response->successful()) {
            throw new \Exception("Anthropic API error: {$response->body()}");
        }

        return $response->json();
    }
}