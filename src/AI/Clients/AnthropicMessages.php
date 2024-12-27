<?php

namespace Kargnas\LaravelAiTranslator\AI\Clients;

class AnthropicMessages
{
    public function __construct(protected AnthropicClient $client)
    {
    }

    public function create(array $data)
    {
        return $this->client->request('post', 'messages', $data);
    }
}