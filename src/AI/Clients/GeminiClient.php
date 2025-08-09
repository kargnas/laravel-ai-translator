<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

class GeminiClient
{
    protected string $apiKey;

    protected string $model;

    protected $client;

    public function __construct(string $apiKey, string $model = 'gemini-pro')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->client = \Gemini::client($apiKey);
    }

    public function request(string $model, array $contents): array
    {
        try {
            $formattedContent = $this->formatRequestContent($contents);

            $response = $this->client->generativeModel(model: $model)->generateContent($formattedContent);

            return $this->formatResponse($response);
        } catch (\Throwable $e) {
            throw new \Exception("Gemini API error: {$e->getMessage()}");
        }
    }

    public function createStream(string $model, array $contents, ?callable $onChunk = null): void
    {
        try {
            $formattedContent = $this->formatRequestContent($contents);

            $stream = $this->client->generativeModel(model: $model)->streamGenerateContent($formattedContent);

            foreach ($stream as $response) {
                if ($onChunk) {
                    $chunk = json_encode([
                        'candidates' => [
                            [
                                'content' => [
                                    'parts' => [
                                        ['text' => $response->text()],
                                    ],
                                    'role' => 'model',
                                ],
                            ],
                        ],
                    ]);
                    $onChunk($chunk);
                }
            }
        } catch (\Throwable $e) {
            throw new \Exception("Gemini API streaming error: {$e->getMessage()}");
        }
    }

    /**
     * Format input content for the library
     */
    protected function formatRequestContent(array $contents): string
    {
        if (isset($contents[0]['parts'][0]['text'])) {
            return $contents[0]['parts'][0]['text'];
        }

        return json_encode($contents);
    }

    /**
     * Format response to match AIProvider's expected format
     */
    protected function formatResponse($response): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $response->text()],
                        ],
                        'role' => 'model',
                    ],
                ],
            ],
        ];
    }

    /**
     * Simple completion method for direct text generation
     */
    public function complete(string $system_prompt, string $user_prompt): string
    {
        try {
            $prompt = "{$system_prompt}\n\n{$user_prompt}";
            $response = $this->client->generativeModel(model: $this->model)->generateContent($prompt);
            return $response->text();
        } catch (\Throwable $e) {
            throw new \Exception("Gemini API error: {$e->getMessage()}");
        }
    }
}
