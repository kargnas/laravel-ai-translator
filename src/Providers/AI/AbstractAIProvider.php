<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for AI translation providers
 * 
 * Provides common functionality for AI-powered translation services including:
 * - Standard translation method signature
 * - Token usage tracking
 * - Error handling and logging
 * - Configuration management
 */
abstract class AbstractAIProvider
{
    protected array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->validateConfig($config);
    }
    
    /**
     * Translate texts using the AI provider
     * 
     * @param array $texts Array of key-value pairs to translate
     * @param string $sourceLocale Source language code (e.g., 'en')
     * @param string $targetLocale Target language code (e.g., 'ko')
     * @param array $metadata Translation metadata including prompts and context
     * @return array Returns ['translations' => array, 'token_usage' => array]
     */
    abstract public function translate(array $texts, string $sourceLocale, string $targetLocale, array $metadata = []): array;
    
    /**
     * Complete a text prompt (for judge models)
     * 
     * @param string $prompt The prompt to complete
     * @param array $config Provider configuration
     * @return string The completed text
     */
    abstract public function complete(string $prompt, array $config = []): string;
    
    /**
     * Validate provider-specific configuration
     * 
     * @param array $config Configuration to validate
     * @throws \InvalidArgumentException If configuration is invalid
     */
    protected function validateConfig(array $config): void
    {
        // Default validation - subclasses can override
        if (empty($config['model'])) {
            throw new \InvalidArgumentException('Model is required for AI provider');
        }
    }
    
    /**
     * Get configuration value with default
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Log provider activity for debugging
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::log($level, "[{$this->getProviderName()}] {$message}", $context);
    }
    
    /**
     * Get the provider name for logging
     * 
     * @return string Provider name
     */
    protected function getProviderName(): string
    {
        return class_basename(static::class);
    }
    
    /**
     * Format token usage for consistent tracking
     * 
     * @param int $inputTokens Input tokens used
     * @param int $outputTokens Output tokens generated
     * @return array Formatted token usage
     */
    protected function formatTokenUsage(int $inputTokens, int $outputTokens): array
    {
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'provider' => $this->getProviderName(),
        ];
    }
    
    /**
     * Handle provider-specific errors with context
     * 
     * @param \Throwable $exception The exception that occurred
     * @param string $operation The operation that failed
     * @param array $context Additional context for debugging
     * @throws \RuntimeException Re-thrown with enhanced context
     * @return never
     */
    protected function handleError(\Throwable $exception, string $operation, array $context = []): never
    {
        $this->log('error', "Failed to {$operation}: {$exception->getMessage()}", [
            'exception' => $exception,
            'context' => $context,
        ]);
        
        throw new \RuntimeException(
            "AI Provider [{$this->getProviderName()}] failed to {$operation}: {$exception->getMessage()}",
            $exception->getCode(),
            $exception
        );
    }
}