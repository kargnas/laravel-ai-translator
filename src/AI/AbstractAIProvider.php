<?php

namespace Kargnas\LaravelAiTranslator\AI;


abstract class AbstractAIProvider
{
    protected string $apiKey;
    protected string $model;

    public function __construct(
        public string $key,
        public string $string,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array  $additionalRules = [],
    ) {
        $this->apiKey = config('ai-translator.ai.api_key');
        $this->model = config('ai-translator.ai.model');
    }

    protected function systemPrompt() {
        $systemPrompt = file_get_contents(__DIR__ . '/prompt-system.txt');

        $systemPrompt = str_replace('{sourceLanguage}', $this->sourceLanguage, $systemPrompt);
        $systemPrompt = str_replace('{targetLanguage}', $this->targetLanguage, $systemPrompt);

        $additionalRules = sizeof($this->additionalRules) > 0 ? "- " . implode("\n- ", $this->additionalRules) : '';
        $systemPrompt = str_replace('{additionalRules}', $additionalRules, $systemPrompt);

        return $systemPrompt;
    }

    protected function userPrompt() {
        $userPrompt = file_get_contents(__DIR__ . '/prompt-user.txt');

        $userPrompt = str_replace('{key}', $this->key, $userPrompt);
        $userPrompt = str_replace('{string}', $this->string, $userPrompt);
        return $userPrompt;
    }

    abstract public function translate(): ?array;
}
