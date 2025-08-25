<?php

use Kargnas\LaravelAiTranslator\Plugins\Middleware\TokenChunkingPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationRequest;

/**
 * TokenChunkingPlugin 핵심 기능 테스트
 * - 언어별 토큰 추정
 * - 청크 분할 로직
 * - 대용량 텍스트 처리
 */

beforeEach(function () {
    $this->plugin = new TokenChunkingPlugin([
        'max_tokens_per_chunk' => 100,
        'buffer_percentage' => 0.9
    ]);
});

test('estimates tokens correctly for different languages', function () {
    $request = new TranslationRequest(
        [
            'english' => 'Hello world this is a test',
            'chinese' => '你好世界这是一个测试',
            'korean' => '안녕하세요 세계 이것은 테스트입니다',
            'arabic' => 'مرحبا بالعالم هذا اختبار'
        ],
        'en',
        'ko'
    );
    
    $context = new TranslationContext($request);
    
    // Use reflection to test private method
    $reflection = new ReflectionClass($this->plugin);
    $method = $reflection->getMethod('estimateTokensForText');
    $method->setAccessible(true);
    
    // English (Latin) should use ~0.25 multiplier
    // "Hello world this is a test" = 26 chars * 0.25 + 20 overhead = ~26 tokens
    $englishTokens = $method->invoke($this->plugin, $request->texts['english']);
    expect($englishTokens)->toBeLessThan(30);
    
    // Chinese (CJK) should use ~1.5 multiplier
    // "你好世界这是一个测试" = 10 chars * 1.5 + 20 overhead = ~35 tokens
    $chineseTokens = $method->invoke($this->plugin, $request->texts['chinese']);
    expect($chineseTokens)->toBeGreaterThan(30);
    
    // Korean (CJK) should use ~1.5 multiplier
    $koreanTokens = $method->invoke($this->plugin, $request->texts['korean']);
    expect($koreanTokens)->toBeGreaterThan(35);
});

test('splits texts into chunks based on token limit', function () {
    // Create texts that will exceed token limit
    $texts = [];
    for ($i = 1; $i <= 10; $i++) {
        $texts["key{$i}"] = str_repeat("This is text number {$i}. ", 10);
    }
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    // Test chunk creation
    $reflection = new ReflectionClass($this->plugin);
    $method = $reflection->getMethod('createChunks');
    $method->setAccessible(true);
    
    $chunks = $method->invoke($this->plugin, $texts, 90); // 90 tokens max (100 * 0.9)
    
    expect($chunks)->toBeArray()
        ->and(count($chunks))->toBeGreaterThan(1)
        ->and(array_sum(array_map('count', $chunks)))->toBe(count($texts));
});

test('handles single text exceeding token limit', function () {
    $longText = str_repeat('This is a very long sentence. ', 100);
    
    $request = new TranslationRequest(
        ['long_text' => $longText],
        'en',
        'ko'
    );
    $context = new TranslationContext($request);
    
    $reflection = new ReflectionClass($this->plugin);
    $method = $reflection->getMethod('createChunks');
    $method->setAccessible(true);
    
    $chunks = $method->invoke($this->plugin, $request->texts, 50);
    
    // Should split the long text into multiple chunks
    expect($chunks)->toBeArray()
        ->and(count($chunks))->toBeGreaterThan(1);
    
    // Check that keys are properly suffixed
    $firstChunk = $chunks[0];
    expect(array_keys($firstChunk)[0])->toContain('long_text_part_');
});

test('preserves text keys across chunks', function () {
    $texts = [
        'key1' => 'Short text',
        'key2' => 'Another short text',
        'key3' => 'Yet another text'
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    $reflection = new ReflectionClass($this->plugin);
    $method = $reflection->getMethod('createChunks');
    $method->setAccessible(true);
    
    $chunks = $method->invoke($this->plugin, $texts, 1000); // High limit = single chunk
    
    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toHaveKeys(['key1', 'key2', 'key3']);
});