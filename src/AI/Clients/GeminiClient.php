<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

use Illuminate\Support\Facades\Http;

class GeminiClient
{
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function request(string $model, array $contents): array
    {
        $endpoint = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($endpoint, ['contents' => $contents]);

        if (!$response->successful()) {
            throw new \Exception('Gemini API error: ' . $response->body());
        }

        return $response->json();
    }

    public function createStream(string $model, array $contents, ?callable $onChunk = null): void
    {
        $url = "{$this->baseUrl}/models/{$model}:streamGenerateContent?key={$this->apiKey}";
        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];
        $data = json_encode(['contents' => $contents, 'stream' => true]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk) {
            if ($onChunk) {
                $onChunk($chunk);
            }
            return strlen($chunk);
        });

        curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Gemini API streaming error: {$error}");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            curl_close($ch);
            throw new \Exception("Gemini API streaming error: HTTP {$httpCode}");
        }

        curl_close($ch);
    }
}
