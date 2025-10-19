<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

/**
 * OpenAI GPT AI Provider using PrismPHP
 * 
 * Provides translation services using OpenAI's GPT models through PrismPHP.
 * Supports GPT-4, GPT-4 Turbo, and other OpenAI models with optimized prompting.
 */
class OpenAIProvider extends AbstractAIProvider
{
    /**
     * {@inheritDoc}
     * @throws \RuntimeException When translation fails
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale, array $metadata = []): array
    {
        try {
            $this->log('info', 'Starting OpenAI translation', [
                'model' => $this->getConfig('model'),
                'source' => $sourceLocale,
                'target' => $targetLocale,
                'text_count' => count($texts),
            ]);
            
            // Build the translation request content
            $content = $this->buildTranslationContent($texts, $sourceLocale, $targetLocale, $metadata);
            
            // Create the Prism request
            $response = Prism::text()
                ->withClientOptions($this->getClientOptions())
                ->using(Provider::OpenAI, $this->getConfig('model', 'gpt-4o'))
                ->withSystemPrompt($metadata['system_prompt'] ?? $this->getDefaultSystemPrompt($sourceLocale, $targetLocale))
                ->withPrompt($content)
                ->usingTemperature($this->getConfig('temperature', 0.3))
                ->withMaxTokens($this->getConfig('max_tokens', 4096))
                ->asText();
            
            // Parse the XML response
            $translations = $this->parseTranslationResponse($response->text, array_keys($texts));
            
            // Track token usage
            $tokenUsage = $this->formatTokenUsage(
                $response->usage->promptTokens ?? 0,
                $response->usage->completionTokens ?? 0
            );
            
            $this->log('info', 'OpenAI translation completed', [
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
            $this->log('info', 'Starting OpenAI completion', [
                'model' => $config['model'] ?? $this->getConfig('model'),
                'prompt_length' => strlen($prompt),
            ]);
            
            // Handle special case for gpt-5 with fixed temperature
            $temperature = $config['temperature'] ?? $this->getConfig('temperature', 0.3);
            $model = $config['model'] ?? $this->getConfig('model', 'gpt-4o');
            
            if ($model === 'gpt-5') {
                $temperature = 1.0; // Always fixed for gpt-5
            }
            
            $response = Prism::text()
                ->withClientOptions($this->getClientOptions())
                ->using(Provider::OpenAI, $model)
                ->withPrompt($prompt)
                ->usingTemperature($temperature)
                ->withMaxTokens($config['max_tokens'] ?? $this->getConfig('max_tokens', 4096))
                ->asText();
            
            $this->log('info', 'OpenAI completion finished', [
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
            $content .= "{$key}: {$text}\n";
        }
        $content .= "</content_to_translate>";
        
        return $content;
    }
    
    /**
     * Parse XML translation response from GPT
     * 
     * @param string $response Raw response from GPT
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
               "Preserve all variables, HTML tags, and formatting exactly as they appear in the source text. " .
               "Always respond in the specified XML format with proper CDATA tags for translations.";
    }
    
    /**
     * {@inheritDoc}
     */
    protected function validateConfig(array $config): void
    {
        parent::validateConfig($config);
        
        // Validate OpenAI-specific configuration
        $model = $this->getConfig('model');
        $validModels = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-5', 'o1', 'o1-mini', 'o3', 'o3-mini'];
        
        $isValidModel = false;
        foreach ($validModels as $validModel) {
            if (str_contains($model, $validModel)) {
                $isValidModel = true;
                break;
            }
        }
        
        if (!$isValidModel) {
            throw new \InvalidArgumentException("Invalid OpenAI model: {$model}");
        }
    }
}
