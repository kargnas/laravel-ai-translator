<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

abstract class AbstractAIProvider
{
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Translate texts
     */
    abstract public function translate(array $texts, string $from, string $to, array $metadata = []): array;
    
    /**
     * Complete a prompt (for judge functionality)
     */
    public function complete(string $prompt, array $config = []): string
    {
        throw new \RuntimeException('Complete method not implemented for this provider');
    }
    
    /**
     * Get the API key from config
     */
    protected function getApiKey(): string
    {
        return $this->config['api_key'] ?? '';
    }
    
    /**
     * Get the model from config
     */
    protected function getModel(): string
    {
        return $this->config['model'] ?? '';
    }
    
    /**
     * Get temperature from config
     */
    protected function getTemperature(): float
    {
        return (float) ($this->config['temperature'] ?? 0.3);
    }
    
    /**
     * Get max tokens from config
     */
    protected function getMaxTokens(): int
    {
        return (int) ($this->config['max_tokens'] ?? 4096);
    }
}