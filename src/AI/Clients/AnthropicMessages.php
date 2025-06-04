<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

class AnthropicMessages
{
    public function __construct(protected AnthropicClient $client) {}

    public function create(array $data)
    {
        return $this->client->request('post', 'messages', $data);
    }

    /**
     * Creates a streaming response.
     *
     * @param  array  $data  Request data
     * @param  callable  $onChunk  Callback function to be called for each chunk
     * @return array Final response data
     */
    public function createStream(array $data, callable $onChunk): array
    {
        return $this->client->createMessageStream($data, $onChunk);
    }
}
