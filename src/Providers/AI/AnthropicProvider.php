<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude AI Provider using PrismPHP
 * 
 * Provides translation services using Anthropic's Claude models through PrismPHP.
 * Supports various Claude models with optimized prompting for translation tasks.
 */
class AnthropicProvider extends AbstractAIProvider
{
    /**
     * {@inheritDoc}
     * @throws \RuntimeException When translation fails
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale, array $metadata = []): array
    {
        try {
            $this->log('info', 'Starting Anthropic translation', [
                'model' => $this->getConfig('model'),
                'source' => $sourceLocale,
                'target' => $targetLocale,
                'text_count' => count($texts),
            ]);
            
            // Build the translation request content
            $content = $this->buildTranslationContent($texts, $sourceLocale, $targetLocale, $metadata);
            
            // Get system prompt
            $systemPrompt = $metadata['system_prompt'] ?? $this->getDefaultSystemPrompt($sourceLocale, $targetLocale);
            
            // Anthropic prompt caching is always enabled when requirements are met
            $systemPromptLength = strlen($systemPrompt);
            $userPromptLength = strlen($content);
            
            // Anthropic requires minimum 1024 tokens for system, 2048 for user (roughly 4 chars per token)
            $minSystemCacheLength = 1024 * 4; // ~1024 tokens for system message
            $minUserCacheLength = 2048 * 4; // ~2048 tokens for user message
            $shouldCacheSystem = $systemPromptLength >= $minSystemCacheLength;
            $shouldCacheUser = $userPromptLength >= $minUserCacheLength;
            
            // Debug: Log prompts and caching decision
            Log::debug('[AnthropicProvider] Prompt caching analysis', [
                'system_prompt_length' => $systemPromptLength,
                'user_prompt_length' => $userPromptLength,
                'will_cache_system' => $shouldCacheSystem,
                'will_cache_user' => $shouldCacheUser,
                'estimated_savings' => $shouldCacheUser ? '90% on user prompt' : 'none',
            ]);
            
            // For Anthropic, we cannot use SystemMessage in messages array
            // We must use withSystemPrompt() for system prompts
            // Caching only works with user messages
            if ($shouldCacheUser) {
                // Use messages array with caching for user content
                $messages = [
                    (new UserMessage($content))
                        ->withProviderOptions(['cacheType' => 'ephemeral'])
                ];
                
                $response = Prism::text()
                    ->withClientOptions($this->getClientOptions())
                    ->using(Provider::Anthropic, $this->getConfig('model'))
                    ->withSystemPrompt($systemPrompt)  // System prompt must use this method
                    ->withMessages($messages)
                    ->usingTemperature($this->getConfig('temperature', 0.3))
                    ->withMaxTokens($this->getConfig('max_tokens', 4096))
                    ->asText();
                    
                Log::info('[AnthropicProvider] Using cached user message', [
                    'user_content_length' => $userPromptLength,
                    'estimated_tokens' => intval($userPromptLength / 4),
                    'min_required' => intval($minUserCacheLength / 4),
                ]);
            } else {
                // Use standard approach without caching
                $response = Prism::text()
                    ->withClientOptions($this->getClientOptions())
                    ->using(Provider::Anthropic, $this->getConfig('model'))
                    ->withSystemPrompt($systemPrompt)
                    ->withPrompt($content)
                    ->usingTemperature($this->getConfig('temperature', 0.3))
                    ->withMaxTokens($this->getConfig('max_tokens', 4096))
                    ->asText();
                    
                Log::debug('[AnthropicProvider] Using standard API (no caching)', [
                    'user_content_too_short' => $userPromptLength < $minUserCacheLength,
                    'user_length' => $userPromptLength,
                    'user_needed' => $minUserCacheLength,
                ]);
            }
            
            // Parse the XML response
            $translations = $this->parseTranslationResponse($response->text, array_keys($texts));
            
            // Track token usage (including cache tokens if available)
            $usage = $response->usage;
            
            // Debug: Log raw usage data
            if ($usage) {
                Log::debug('[AnthropicProvider] Raw token usage from PrismPHP', [
                    'promptTokens' => $usage->promptTokens ?? null,
                    'completionTokens' => $usage->completionTokens ?? null,
                    'cacheCreationInputTokens' => $usage->cacheCreationInputTokens ?? null,
                    'cacheReadInputTokens' => $usage->cacheReadInputTokens ?? null,
                    'raw_usage' => json_encode($usage),
                ]);
            }
            
            $tokenUsage = $this->formatTokenUsage(
                $usage->promptTokens ?? 0,
                $usage->completionTokens ?? 0,
                $usage->cacheCreationInputTokens ?? 0,
                $usage->cacheReadInputTokens ?? 0
            );
            
            $this->log('info', 'Anthropic translation completed', [
                'translations_count' => count($translations),
                'token_usage' => $tokenUsage,
            ]);
            
            return [
                'translations' => $translations,
                'token_usage' => $tokenUsage,
            ];
            
        } catch (\Throwable $e) {
            $this->handleError($e, 'translate', [
                'source' => $sourceLocale,
                'target' => $targetLocale,
                'texts' => array_keys($texts),
            ]);
        }
    }
    
    /**
     * {@inheritDoc}
     * @throws \RuntimeException When completion fails
     */
    public function complete(string $prompt, array $config = []): string
    {
        try {
            $this->log('info', 'Starting Anthropic completion', [
                'model' => $config['model'] ?? $this->getConfig('model'),
                'prompt_length' => strlen($prompt),
            ]);
            
            // Anthropic prompt caching is always enabled when requirements are met
            $promptLength = strlen($prompt);
            
            // Anthropic requires minimum 1024 tokens (roughly 4 chars per token)
            $minCacheLength = 1024 * 4; // ~1024 tokens
            $shouldCache = $promptLength >= $minCacheLength;
            
            Log::debug('[AnthropicProvider] Complete method caching analysis', [
                'prompt_length' => $promptLength,
                'will_cache' => $shouldCache,
                'estimated_tokens' => intval($promptLength / 4),
                'min_required' => intval($minCacheLength / 4),
            ]);
            
            if ($shouldCache) {
                $response = Prism::text()
                    ->withClientOptions($this->getClientOptions())
                    ->using(Provider::Anthropic, $config['model'] ?? $this->getConfig('model'))
                    ->withMessages([
                        (new UserMessage($prompt))
                            ->withProviderOptions(['cacheType' => 'ephemeral'])
                    ])
                    ->usingTemperature($config['temperature'] ?? $this->getConfig('temperature', 0.3))
                    ->withMaxTokens($config['max_tokens'] ?? $this->getConfig('max_tokens', 4096))
                    ->asText();
                    
                Log::info('[AnthropicProvider] Used caching for complete method', [
                    'prompt_length' => $promptLength,
                    'estimated_tokens' => $promptLength / 4,
                ]);
            } else {
                $response = Prism::text()
                    ->withClientOptions($this->getClientOptions())
                    ->using(Provider::Anthropic, $config['model'] ?? $this->getConfig('model'))
                    ->withPrompt($prompt)
                    ->usingTemperature($config['temperature'] ?? $this->getConfig('temperature', 0.3))
                    ->withMaxTokens($config['max_tokens'] ?? $this->getConfig('max_tokens', 4096))
                    ->asText();
                    
                Log::debug('[AnthropicProvider] No caching for complete method', [
                    'reason' => 'prompt too short',
                    'prompt_length' => $promptLength,
                    'min_required' => $minCacheLength,
                ]);
            }
            
            $this->log('info', 'Anthropic completion finished', [
                'response_length' => strlen($response->text),
            ]);
            
            return $response->text;
            
        } catch (\Throwable $e) {
            $this->handleError($e, 'complete', ['prompt_length' => strlen($prompt)]);
        }
    }
    
    /**
     * Build translation content for the AI request
     * 
     * @param array $texts Texts to translate
     * @param string $sourceLocale Source language
     * @param string $targetLocale Target language
     * @param array $metadata Translation metadata
     * @return string Formatted content
     */
    protected function buildTranslationContent(array $texts, string $sourceLocale, string $targetLocale, array $metadata): string
    {
        // Use user prompt from metadata if available
        if (!empty($metadata['user_prompt'])) {
            return $metadata['user_prompt'];
        }
        
        // Build basic translation request
        $content = "<translation_request>\n";
        $content .= "  <source_language>{$sourceLocale}</source_language>\n";
        $content .= "  <target_language>{$targetLocale}</target_language>\n";
        $content .= "</translation_request>\n\n";
        
        $content .= "<content_to_translate>\n";
        foreach ($texts as $key => $text) {
            // Handle array values (like nested translations)
            if (is_array($text)) {
                $text = json_encode($text, JSON_UNESCAPED_UNICODE);
            }
            $content .= "{$key}: {$text}\n";
        }
        $content .= "</content_to_translate>";
        
        return $content;
    }
    
    /**
     * Parse XML translation response from Claude
     * 
     * @param string $response Raw response from Claude
     * @param array $expectedKeys Expected translation keys
     * @return array Parsed translations
     */
    protected function parseTranslationResponse(string $response, array $expectedKeys): array
    {
        $translations = [];
        
        // Try to extract translations from XML format
        if (preg_match('/<translations>(.*?)<\/translations>/s', $response, $matches)) {
            $translationsXml = $matches[1];
            
            // Extract each translation item
            if (preg_match_all('/<item>(.*?)<\/item>/s', $translationsXml, $itemMatches)) {
                foreach ($itemMatches[1] as $item) {
                    // Extract key
                    if (preg_match('/<key>(.*?)<\/key>/s', $item, $keyMatch)) {
                        $key = trim($keyMatch[1]);
                        
                        // Extract translation with CDATA support
                        if (preg_match('/<trx><!\[CDATA\[(.*?)\]\]><\/trx>/s', $item, $trxMatch)) {
                            $translations[$key] = $trxMatch[1];
                        } elseif (preg_match('/<trx>(.*?)<\/trx>/s', $item, $trxMatch)) {
                            $translations[$key] = trim($trxMatch[1]);
                        }
                    }
                }
            }
        }
        
        // Fallback: if XML parsing fails, try simple key:value format
        if (empty($translations)) {
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                if (preg_match('/^(.+?):\s*(.+)$/', trim($line), $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    if (in_array($key, $expectedKeys)) {
                        $translations[$key] = $value;
                    }
                }
            }
        }
        
        return $translations;
    }
    
    /**
     * Get default system prompt for translation
     * 
     * @param string $sourceLocale Source language
     * @param string $targetLocale Target language
     * @return string System prompt
     */
    protected function getDefaultSystemPrompt(string $sourceLocale, string $targetLocale): string
    {
        return "You are a professional translator specializing in {$sourceLocale} to {$targetLocale} translations for web applications. " .
               "Provide natural, contextually appropriate translations that maintain the original meaning while feeling native to {$targetLocale} speakers. " .
               "Preserve all variables, HTML tags, and formatting exactly as they appear in the source text.";
    }
    
    /**
     * {@inheritDoc}
     */
    protected function validateConfig(array $config): void
    {
        parent::validateConfig($config);
        
        // Validate Anthropic-specific configuration
        $model = $this->getConfig('model');
        if (!str_contains($model, 'claude')) {
            throw new \InvalidArgumentException("Invalid Anthropic model: {$model}");
        }
    }
}
