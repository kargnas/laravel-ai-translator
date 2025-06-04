<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

class GeminiClient
{
    protected string $apiKey;

    protected $client;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
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
     * 입력 콘텐츠를 라이브러리에 맞게 변환
     */
    protected function formatRequestContent(array $contents): string
    {
        if (isset($contents[0]['parts'][0]['text'])) {
            return $contents[0]['parts'][0]['text'];
        }

        return json_encode($contents);
    }

    /**
     * 응답을 AIProvider가 기대하는 형식으로 변환
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
}
