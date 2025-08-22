<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Claude provider implementation
 */
class AnthropicProvider extends AbstractAIProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    
    public function translate(array $texts, string $from, string $to, array $metadata = []): array
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            throw new RuntimeException('Anthropic API key is not configured');
        }
        
        // Prepare the translation prompt
        $prompt = $this->buildTranslationPrompt($texts, $from, $to, $metadata);
        
        // Make API request
        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key' => $apiKey,
            'content-type' => 'application/json',
        ])->post(self::API_URL, [
            'model' => $this->getModel() ?: 'claude-3-haiku-20240307',
            'max_tokens' => $this->getMaxTokens(),
            'temperature' => $this->getTemperature(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);
        
        if (!$response->successful()) {
            throw new RuntimeException("Anthropic API error: {$response->body()}");
        }
        
        $result = $response->json();
        
        // Parse the response
        $content = $result['content'][0]['text'] ?? '';
        $translations = $this->parseTranslations($content, $texts);
        
        // Calculate token usage
        $tokenUsage = [
            'input' => $result['usage']['input_tokens'] ?? 0,
            'output' => $result['usage']['output_tokens'] ?? 0,
            'total' => ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0),
        ];
        
        return [
            'translations' => $translations,
            'token_usage' => $tokenUsage,
        ];
    }
    
    /**
     * Build the translation prompt
     */
    private function buildTranslationPrompt(array $texts, string $from, string $to, array $metadata): string
    {
        $systemPrompt = $metadata['system_prompt'] ?? '';
        $userPrompt = $metadata['user_prompt'] ?? '';
        
        // If no custom prompts provided, use default XML format
        if (empty($systemPrompt) && empty($userPrompt)) {
            $xmlContent = "<translations>\n";
            foreach ($texts as $key => $text) {
                $xmlContent .= "  <item key=\"{$key}\">{$text}</item>\n";
            }
            $xmlContent .= "</translations>";
            
            return "Translate the following from {$from} to {$to}. Return ONLY the XML structure with translated content:\n\n{$xmlContent}";
        }
        
        // Use custom prompts
        $prompt = '';
        if ($systemPrompt) {
            $prompt .= "{$systemPrompt}\n\n";
        }
        
        if ($userPrompt) {
            // Replace placeholders
            $userPrompt = str_replace('{{source_language}}', $from, $userPrompt);
            $userPrompt = str_replace('{{target_language}}', $to, $userPrompt);
            
            // Inject texts into prompt
            $textList = '';
            foreach ($texts as $key => $text) {
                $textList .= "<item key=\"{$key}\">{$text}</item>\n";
            }
            $userPrompt = str_replace('{{texts}}', $textList, $userPrompt);
            
            $prompt .= $userPrompt;
        }
        
        return $prompt;
    }
    
    /**
     * Parse translations from API response
     */
    private function parseTranslations(string $content, array $originalTexts): array
    {
        $translations = [];
        
        // Try to parse XML response
        if (strpos($content, '<translations>') !== false) {
            // Extract XML content
            preg_match('/<translations>(.*?)<\/translations>/s', $content, $matches);
            if (!empty($matches[1])) {
                $xmlContent = '<translations>' . $matches[1] . '</translations>';
                
                try {
                    $xml = simplexml_load_string($xmlContent);
                    foreach ($xml->item as $item) {
                        $key = (string) $item['key'];
                        $translations[$key] = (string) $item;
                    }
                } catch (\Exception $e) {
                    // Fall back to simple parsing
                }
            }
        }
        
        // If XML parsing failed or no translations found, try simple pattern matching
        if (empty($translations)) {
            foreach ($originalTexts as $key => $text) {
                // Try to find translated text in response
                if (preg_match('/key="' . preg_quote($key, '/') . '"[^>]*>([^<]+)</', $content, $matches)) {
                    $translations[$key] = $matches[1];
                } else {
                    // Fallback: use original text
                    $translations[$key] = $text;
                }
            }
        }
        
        return $translations;
    }
    
    public function complete(string $prompt, array $config = []): string
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            throw new RuntimeException('Anthropic API key is not configured');
        }
        
        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'x-api-key' => $apiKey,
            'content-type' => 'application/json',
        ])->post(self::API_URL, [
            'model' => $config['model'] ?? $this->getModel() ?? 'claude-3-haiku-20240307',
            'max_tokens' => $config['max_tokens'] ?? 100,
            'temperature' => $config['temperature'] ?? 0.3,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);
        
        if (!$response->successful()) {
            throw new RuntimeException("Anthropic API error: {$response->body()}");
        }
        
        $result = $response->json();
        return $result['content'][0]['text'] ?? '';
    }
}