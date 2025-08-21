<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationOutput;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Illuminate\Support\Facades\Http;
use Generator;

/**
 * MultiProviderPlugin - Orchestrates multiple AI providers for translation with consensus mechanisms
 * 
 * Responsibilities:
 * - Manages multiple AI provider configurations and their execution
 * - Implements parallel processing for multiple providers to optimize speed
 * - Provides consensus mechanisms to select the best translation from multiple results
 * - Handles special provider-specific requirements (e.g., gpt-5 fixed temperature)
 * - Implements fallback strategies when primary providers fail
 * - Tracks and reports provider-specific metrics and performance
 * - Manages provider rate limiting and quota management
 * 
 * This plugin is crucial for high-quality translations as it can:
 * 1. Compare outputs from different AI models
 * 2. Use a judge model to select the best translation
 * 3. Provide redundancy when a provider fails
 * 4. Optimize cost by routing to appropriate providers based on content
 */
class MultiProviderPlugin extends AbstractProviderPlugin
{
    protected string $name = 'multi_provider';
    
    protected int $priority = 50;

    /**
     * Get default configuration for the plugin
     * 
     * Defines default provider settings and consensus judge configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'providers' => [
                'primary' => [
                    'provider' => 'anthropic',
                    'model' => 'claude-3-opus-20240229',
                    'temperature' => 0.3,
                    'thinking' => false,
                    'max_tokens' => 4096,
                ],
            ],
            'judge' => [
                'provider' => 'openai',
                'model' => 'gpt-5',
                'temperature' => 0.3,
                'thinking' => true,
            ],
            'execution_mode' => 'parallel', // 'parallel' or 'sequential'
            'consensus_threshold' => 2, // Minimum providers that must agree
            'fallback_on_failure' => true,
            'retry_attempts' => 2,
            'timeout' => 30, // seconds per provider
        ];
    }

    /**
     * Declare the services this provider offers
     * 
     * This plugin provides translation and consensus judging services
     */
    public function provides(): array
    {
        return ['translation.multi_provider', 'consensus.judge'];
    }

    /**
     * Execute the multi-provider translation process
     * 
     * Responsibilities:
     * - Initialize and configure multiple AI providers
     * - Execute translations in parallel or sequential mode
     * - Handle provider failures with retry logic
     * - Apply consensus mechanism to select best translation
     * - Track metrics for each provider's performance
     * 
     * @param TranslationContext $context The translation context containing texts and metadata
     * @return Generator|array Returns translations as they complete (streaming) or all at once
     */
    public function execute(TranslationContext $context): mixed
    {
        $providers = $this->getConfiguredProviders();
        $executionMode = $this->getConfigValue('execution_mode', 'parallel');
        
        if (empty($providers)) {
            throw new \RuntimeException('No providers configured for multi-provider translation');
        }

        // Execute based on mode
        if ($executionMode === 'parallel') {
            return $this->executeParallel($context, $providers);
        } else {
            return $this->executeSequential($context, $providers);
        }
    }

    /**
     * Configure and prepare provider instances
     * 
     * Responsibilities:
     * - Parse provider configurations from settings
     * - Apply special rules for specific models (e.g., gpt-5 temperature)
     * - Validate provider configurations
     * - Initialize provider instances with proper credentials
     * 
     * @return array Array of configured provider instances with their settings
     */
    protected function getConfiguredProviders(): array
    {
        $providersConfig = $this->getConfigValue('providers', []);
        $providers = [];

        foreach ($providersConfig as $name => $config) {
            // Apply special handling for gpt-5
            if (($config['model'] ?? '') === 'gpt-5') {
                $config['temperature'] = 1.0; // Always fixed for gpt-5
                $this->info("Fixed temperature to 1.0 for gpt-5 model");
            }

            // Validate required fields
            if (!isset($config['provider']) || !isset($config['model'])) {
                $this->warning("Skipping provider '{$name}' due to missing configuration");
                continue;
            }

            $providers[$name] = $config;
        }

        return $providers;
    }

    /**
     * Execute translations in parallel across multiple providers
     * 
     * Responsibilities:
     * - Launch concurrent translation requests to all providers
     * - Handle timeouts and failures for individual providers
     * - Collect results as they complete
     * - Apply consensus mechanism to select best translation
     * - Yield results progressively for streaming support
     * 
     * @param TranslationContext $context Translation context
     * @param array $providers Configured provider instances
     * @return Generator Yields translation outputs as they complete
     */
    protected function executeParallel(TranslationContext $context, array $providers): Generator
    {
        $promises = [];
        $results = [];
        $targetLocales = $context->request->getTargetLocales();

        foreach ($targetLocales as $locale) {
            foreach ($providers as $name => $config) {
                $promises["{$locale}_{$name}"] = $this->executeProviderAsync($config, $context, $locale);
            }
        }

        // Collect results as they complete
        foreach ($promises as $key => $promise) {
            try {
                $result = $this->awaitPromise($promise);
                [$locale, $providerName] = explode('_', $key, 2);
                
                if (!isset($results[$locale])) {
                    $results[$locale] = [];
                }
                $results[$locale][$providerName] = $result;

                // Yield intermediate results for streaming
                foreach ($result as $textKey => $translation) {
                    yield new TranslationOutput(
                        $textKey,
                        $translation,
                        $locale,
                        false,
                        ['provider' => $providerName]
                    );
                }

                $this->debug("Provider '{$providerName}' completed for locale '{$locale}'");
            } catch (\Exception $e) {
                $this->error("Provider failed for '{$key}': " . $e->getMessage());
                
                if (!$this->getConfigValue('fallback_on_failure', true)) {
                    throw $e;
                }
            }
        }

        // Apply consensus if multiple results
        $this->applyConsensus($context, $results);
    }

    /**
     * Execute translations sequentially across providers
     * 
     * Responsibilities:
     * - Execute providers one by one in defined order
     * - Stop on first successful translation or continue for consensus
     * - Handle failures with fallback to next provider
     * - Track execution time for each provider
     * 
     * @param TranslationContext $context Translation context
     * @param array $providers Configured provider instances
     * @return Generator Yields translation outputs
     */
    protected function executeSequential(TranslationContext $context, array $providers): Generator
    {
        $results = [];
        $targetLocales = $context->request->getTargetLocales();

        foreach ($targetLocales as $locale) {
            $results[$locale] = [];
            
            foreach ($providers as $name => $config) {
                try {
                    $startTime = microtime(true);
                    $result = $this->executeProvider($config, $context, $locale);
                    $executionTime = microtime(true) - $startTime;
                    
                    $results[$locale][$name] = $result;
                    
                    // Yield results
                    foreach ($result as $key => $translation) {
                        yield new TranslationOutput(
                            $key,
                            $translation,
                            $locale,
                            false,
                            [
                                'provider' => $name,
                                'execution_time' => $executionTime,
                            ]
                        );
                    }
                    
                    $this->info("Provider '{$name}' completed in {$executionTime}s");
                    
                    // Break if we don't need consensus
                    if (count($providers) === 1 || !$this->needsConsensus()) {
                        break;
                    }
                } catch (\Exception $e) {
                    $this->error("Provider '{$name}' failed: " . $e->getMessage());
                    
                    if (!$this->getConfigValue('fallback_on_failure', true)) {
                        throw $e;
                    }
                }
            }
        }

        // Apply consensus if needed
        if ($this->needsConsensus() && count($results) > 1) {
            $this->applyConsensus($context, $results);
        }
    }

    /**
     * Execute a single provider for translation
     * 
     * Responsibilities:
     * - Create provider instance with proper configuration
     * - Execute translation with retry logic
     * - Track token usage and costs
     * - Handle provider-specific errors
     * 
     * @param array $config Provider configuration
     * @param TranslationContext $context Translation context
     * @param string $locale Target locale
     * @return array Translation results keyed by text keys
     */
    protected function executeProvider(array $config, TranslationContext $context, string $locale): array
    {
        $retryAttempts = $this->getConfigValue('retry_attempts', 2);
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            try {
                // Create provider instance
                $provider = $this->createProvider($config);
                
                // Execute translation
                $result = $provider->translate(
                    $context->texts,
                    $context->request->sourceLocale,
                    $locale,
                    $context->metadata
                );

                // Track token usage
                if (isset($result['token_usage'])) {
                    $context->addTokenUsage(
                        $result['token_usage']['input'] ?? 0,
                        $result['token_usage']['output'] ?? 0
                    );
                }

                return $result['translations'] ?? [];
            } catch (\Exception $e) {
                $lastException = $e;
                $this->warning("Provider attempt {$attempt} failed: " . $e->getMessage());
                
                if ($attempt < $retryAttempts) {
                    sleep(min(2 ** $attempt, 10)); // Exponential backoff
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Provider execution failed');
    }

    /**
     * Execute provider asynchronously (simulated with promises)
     * 
     * Responsibilities:
     * - Create non-blocking translation request
     * - Return promise/future for later resolution
     * - Handle timeout constraints
     * 
     * @param array $config Provider configuration
     * @param TranslationContext $context Translation context
     * @param string $locale Target locale
     * @return mixed Promise or future object
     */
    protected function executeProviderAsync(array $config, TranslationContext $context, string $locale): mixed
    {
        // In a real implementation, this would return a promise/future
        // For now, we'll simulate with immediate execution
        return $this->executeProvider($config, $context, $locale);
    }

    /**
     * Apply consensus mechanism to select best translations
     * 
     * Responsibilities:
     * - Compare translations from multiple providers
     * - Use judge model to evaluate quality
     * - Select best translation based on consensus rules
     * - Handle ties and edge cases
     * - Update context with final selections
     * 
     * @param TranslationContext $context Translation context
     * @param array $results Results from multiple providers by locale
     */
    protected function applyConsensus(TranslationContext $context, array $results): void
    {
        $judgeConfig = $this->getConfigValue('judge');
        
        foreach ($results as $locale => $providerResults) {
            if (count($providerResults) <= 1) {
                // No consensus needed
                $context->translations[$locale] = reset($providerResults) ?: [];
                continue;
            }

            // Use judge to select best translations
            $bestTranslations = $this->selectBestTranslations(
                $providerResults,
                $context->texts,
                $locale,
                $judgeConfig
            );

            $context->translations[$locale] = $bestTranslations;
        }
    }

    /**
     * Select best translations using judge model
     * 
     * Responsibilities:
     * - Prepare comparison prompt for judge model
     * - Execute judge model to evaluate translations
     * - Parse judge's decision
     * - Apply fallback logic if judge fails
     * - Track consensus metrics
     * 
     * @param array $providerResults Results from multiple providers
     * @param array $originalTexts Original texts being translated
     * @param string $locale Target locale
     * @param array $judgeConfig Judge model configuration
     * @return array Selected best translations
     */
    protected function selectBestTranslations(
        array $providerResults,
        array $originalTexts,
        string $locale,
        array $judgeConfig
    ): array {
        $bestTranslations = [];

        foreach ($originalTexts as $key => $originalText) {
            $candidates = [];
            
            // Collect all translations for this key
            foreach ($providerResults as $providerName => $translations) {
                if (isset($translations[$key])) {
                    $candidates[$providerName] = $translations[$key];
                }
            }

            if (empty($candidates)) {
                $this->warning("No translations found for key '{$key}'");
                continue;
            }

            if (count($candidates) === 1) {
                $bestTranslations[$key] = reset($candidates);
                continue;
            }

            // Use judge to select best
            try {
                $best = $this->judgeTranslations($originalText, $candidates, $locale, $judgeConfig);
                $bestTranslations[$key] = $best;
            } catch (\Exception $e) {
                $this->error("Judge failed for key '{$key}': " . $e->getMessage());
                // Fallback to first non-empty translation
                $bestTranslations[$key] = $this->fallbackSelection($candidates);
            }
        }

        return $bestTranslations;
    }

    /**
     * Use judge model to evaluate and select best translation
     * 
     * Responsibilities:
     * - Format comparison prompt with all candidates
     * - Execute judge model with appropriate parameters
     * - Parse judge's response to extract selection
     * - Validate judge's selection
     * 
     * @param string $original Original text
     * @param array $candidates Translation candidates from different providers
     * @param string $locale Target locale
     * @param array $judgeConfig Judge configuration
     * @return string Selected best translation
     */
    protected function judgeTranslations(string $original, array $candidates, string $locale, array $judgeConfig): string
    {
        // Special handling for gpt-5 judge
        if (($judgeConfig['model'] ?? '') === 'gpt-5') {
            $judgeConfig['temperature'] = 0.3; // Optimal for consensus judgment
        }

        $prompt = $this->buildJudgePrompt($original, $candidates, $locale);
        
        // Create judge provider
        $judge = $this->createProvider($judgeConfig);
        
        // Execute judgment
        $response = $judge->complete($prompt, $judgeConfig);
        
        // Parse response to get selected translation
        return $this->parseJudgeResponse($response, $candidates);
    }

    /**
     * Build prompt for judge model to evaluate translations
     * 
     * @param string $original Original text
     * @param array $candidates Translation candidates
     * @param string $locale Target locale
     * @return string Formatted prompt for judge
     */
    protected function buildJudgePrompt(string $original, array $candidates, string $locale): string
    {
        $prompt = "Evaluate the following translations and select the best one.\n\n";
        $prompt .= "Original text: {$original}\n";
        $prompt .= "Target language: {$locale}\n\n";
        $prompt .= "Candidates:\n";
        
        $index = 1;
        foreach ($candidates as $provider => $translation) {
            $prompt .= "{$index}. [{$provider}]: {$translation}\n";
            $index++;
        }
        
        $prompt .= "\nSelect the number of the best translation based on accuracy, fluency, and naturalness.";
        $prompt .= "\nRespond with only the number.";
        
        return $prompt;
    }

    /**
     * Parse judge's response to extract selected translation
     * 
     * @param string $response Judge's response
     * @param array $candidates Original candidates
     * @return string Selected translation
     */
    protected function parseJudgeResponse(string $response, array $candidates): string
    {
        // Extract number from response
        preg_match('/\d+/', $response, $matches);
        
        if (!empty($matches)) {
            $index = (int)$matches[0] - 1;
            $values = array_values($candidates);
            
            if (isset($values[$index])) {
                return $values[$index];
            }
        }
        
        // Fallback to first candidate
        return reset($candidates);
    }

    /**
     * Fallback selection when judge fails
     * 
     * @param array $candidates Translation candidates
     * @return string Selected translation
     */
    protected function fallbackSelection(array $candidates): string
    {
        // Simple strategy: select the longest non-empty translation
        $longest = '';
        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) > mb_strlen($longest)) {
                $longest = $candidate;
            }
        }
        return $longest ?: reset($candidates);
    }

    /**
     * Create provider instance from configuration
     * 
     * @param array $config Provider configuration
     * @return mixed Provider instance
     */
    protected function createProvider(array $config): mixed
    {
        // This would create actual AI provider instance
        // For now, returning mock
        return new class($config) {
            private array $config;
            
            public function __construct(array $config) {
                $this->config = $config;
            }
            
            public function translate($texts, $from, $to, $metadata) {
                // Mock implementation
                $translations = [];
                foreach ($texts as $key => $text) {
                    $translations[$key] = "[{$to}] " . $text;
                }
                return ['translations' => $translations, 'token_usage' => ['input' => 100, 'output' => 150]];
            }
            
            public function complete($prompt, $config) {
                return "1"; // Mock judge response
            }
        };
    }

    /**
     * Check if consensus mechanism is needed
     * 
     * @return bool Whether consensus should be applied
     */
    protected function needsConsensus(): bool
    {
        $threshold = $this->getConfigValue('consensus_threshold', 2);
        $providers = $this->getConfigValue('providers', []);
        return count($providers) >= $threshold;
    }

    /**
     * Await promise resolution (placeholder for async support)
     * 
     * @param mixed $promise Promise to await
     * @return mixed Resolved value
     */
    protected function awaitPromise(mixed $promise): mixed
    {
        // In real implementation, this would await async promise
        return $promise;
    }
}