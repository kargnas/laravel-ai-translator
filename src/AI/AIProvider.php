<?php

namespace Kargnas\LaravelAiTranslator\AI;


use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;

class AIProvider
{
    public function __construct(
        public string $key,
        public string $string,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array  $additionalRules = [],
        public int    $retries = 3,
    ) {

    }

    protected function verify($result): void {
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
        $configProvider = config('ai-translator.ai.provider');

        /** @var AIProvider $provider */
        $provider = match ($configProvider) {
            'anthropic' => new AnthropicAIProvider($this->key, $this->string, $this->sourceLanguage, $this->targetLanguage, $this->additionalRules),
            'openai' => new OpenAbstractAIProvider($this->key, $this->string, $this->sourceLanguage, $this->targetLanguage, $this->additionalRules),
            default => throw new \Exception("Provider {$configProvider} is not supported."),
        };

        $tried = 1;
        do {
            try {
                \Log::debug("Translating {$this->key} to {$this->targetLanguage} ({$tried}/{$this->retries}) using {$configProvider}");

                $result = $provider->translate();
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
