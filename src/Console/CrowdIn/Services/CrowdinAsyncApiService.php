<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Illuminate\Console\Command;

class CrowdinAsyncApiService
{
    protected CrowdinApiService $apiService;

    protected ProjectService $projectService;

    protected Command $command;

    protected ?Client $client = null;

    public function __construct(
        CrowdinApiService $apiService,
        ProjectService $projectService,
        Command $command
    ) {
        $this->apiService = $apiService;
        $this->projectService = $projectService;
        $this->command = $command;
    }

    /**
     * Get Guzzle client instance
     */
    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri' => 'https://api.crowdin.com/api/v2/',
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiService->getClient()->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * Post translations asynchronously
     *
     * @param  array  $translations  Array of translations to save
     * @param  string  $languageId  Target language ID
     * @return array Array of promises
     */
    public function postTranslationsAsync(array $translations, string $languageId): array
    {
        $promises = [];
        $projectId = $this->projectService->getSelectedProject()['id'];

        foreach ($translations as $translation) {
            $promises[$translation['key']] = $this->getClient()->postAsync(
                "projects/{$projectId}/translations",
                [
                    'json' => [
                        'stringId' => $translation['stringId'],
                        'languageId' => $languageId,
                        'text' => $translation['text'],
                    ],
                ]
            );
        }

        return $promises;
    }

    /**
     * Execute promises and handle results
     *
     * @param  array  $promises  Array of promises to execute
     * @return array Results of the promises
     */
    public function executePromises(array $promises): array
    {
        $results = [];
        $addedKeys = [];

        Promise\Utils::settle($promises)->wait();

        foreach ($promises as $key => $promise) {
            try {
                $promise->then(
                    function ($response) use ($key, &$results, &$addedKeys) {
                        $results[$key] = true;
                        $addedKeys[] = $key;
                    },
                    function ($e) use ($key, &$results) {
                        $results[$key] = false;
                        $this->command->error("    ✗ Failed: {$key} - {$e->getMessage()}");
                    }
                );
            } catch (\Exception $e) {
                $results[$key] = false;
                $this->command->error("    ✗ Failed: {$key} - {$e->getMessage()}");
            }
        }

        if (! empty($addedKeys)) {
            foreach ($addedKeys as $key) {
                $this->command->line('    ✓ Added: '.preg_replace('/^.*\./', '', $key));
            }
        }

        return $results;
    }

    /**
     * Process translations in chunks with parallel execution
     *
     * @param  array  $translations  Array of translations to save
     * @param  string  $languageId  Target language ID
     * @param  int  $chunkSize  Size of each chunk
     * @return array Array of results
     *
     * @throws \RuntimeException
     */
    public function processTranslationsInChunks(array $translations, string $languageId, int $chunkSize = 10): array
    {
        $chunks = array_chunk($translations, $chunkSize);
        $results = [];

        foreach ($chunks as $index => $chunk) {
            try {
                $response = $this->client->post(
                    "projects/{$this->projectService->getProjectId()}/translations",
                    [
                        'json' => [
                            'languageId' => $languageId,
                            'strings' => array_map(fn ($t) => [
                                'stringId' => $t['stringId'],
                                'text' => $t['text'],
                            ], $chunk),
                        ],
                    ]
                );

                $results = array_merge($results, array_fill(0, count($chunk), true));
            } catch (\Exception $e) {
                $errorBody = json_decode($e->getResponse()->getBody(), true);

                // 동일 번역이 있는 경우 스킵 처리
                if (
                    isset($errorBody['errors']) &&
                    str_contains($errorBody['errors'][0]['error']['errors'][0]['message'] ?? '', 'identical translation')
                ) {
                    $results = array_merge($results, array_fill(0, count($chunk), true));

                    continue;
                }

                \Log::error('Failed to process translations chunk', [
                    'error' => $e->getMessage(),
                    'chunk' => $chunk,
                ]);
                $results = array_merge($results, array_fill(0, count($chunk), false));
            }
        }

        return $results;
    }

    /**
     * Get current user ID from Crowdin API
     */
    public function getCurrentUserId(): int
    {
        try {
            $response = $this->client->get('user');
            $userData = json_decode($response->getBody(), true);

            return $userData['data']['id'] ?? throw new \RuntimeException('Failed to get user ID from response');
        } catch (\Exception $e) {
            \Log::error('Failed to get current user ID', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to get current user ID: '.$e->getMessage());
        }
    }
}
