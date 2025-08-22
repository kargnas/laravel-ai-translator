<?php

namespace Kargnas\LaravelAiTranslator\Providers\AI;

/**
 * Mock provider for testing
 */
class MockProvider extends AbstractAIProvider
{
    public function translate(array $texts, string $from, string $to, array $metadata = []): array
    {
        $translations = [];
        
        // Simple mock translations
        $mockTranslations = [
            'en' => [
                'ko' => [
                    'Hello World' => '안녕하세요 세계',
                    'Hello' => '안녕하세요',
                    'World' => '세계',
                    'test' => '테스트',
                ],
                'ja' => [
                    'Hello World' => 'こんにちは世界',
                    'Hello' => 'こんにちは',
                    'World' => '世界',
                    'test' => 'テスト',
                ],
            ],
        ];
        
        foreach ($texts as $key => $text) {
            // Try to find mock translation
            $translated = $mockTranslations[$from][$to][$text] ?? null;
            
            if (!$translated) {
                // Fallback: just prepend target language code
                $translated = "[{$to}] " . $text;
            }
            
            $translations[$key] = $translated;
        }
        
        return [
            'translations' => $translations,
            'token_usage' => [
                'input' => 200,
                'output' => 300,
                'total' => 500,
            ],
        ];
    }
    
    public function complete(string $prompt, array $config = []): string
    {
        // Mock judge response
        return "1";
    }
}