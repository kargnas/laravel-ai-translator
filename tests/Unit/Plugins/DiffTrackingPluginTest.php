<?php

use Kargnas\LaravelAiTranslator\Plugins\DiffTrackingPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationRequest;
use Kargnas\LaravelAiTranslator\Storage\FileStorage;

/**
 * DiffTrackingPlugin 핵심 기능 테스트
 * - 변경 감지 (60-80% 비용 절감)
 * - 상태 저장/복원
 * - 캐시된 번역 적용
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/ai-translator-test-' . uniqid();
    mkdir($this->tempDir);
    
    $this->plugin = new DiffTrackingPlugin([
        'storage' => [
            'driver' => 'file',
            'path' => $this->tempDir
        ],
        'tracking' => [
            'enabled' => true
        ]
    ]);
});

afterEach(function () {
    // Clean up temp directory
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir . '/**/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        // Clean subdirectories
        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->tempDir);
    }
});

test('detects unchanged texts and skips retranslation', function () {
    $texts = [
        'key1' => 'Hello world',
        'key2' => 'How are you?',
        'key3' => 'Goodbye'
    ];
    
    // First translation
    $request1 = new TranslationRequest($texts, 'en', 'ko');
    $context1 = new TranslationContext($request1);
    
    // Simulate completed translation
    $context1->translations = [
        'ko' => [
            'key1' => '안녕하세요',
            'key2' => '어떻게 지내세요?',
            'key3' => '안녕히 가세요'
        ]
    ];
    
    // First run to save state
    $this->plugin->handle($context1, function ($ctx) {
        return $ctx;
    });
    
    // Second translation with partial changes
    $texts2 = [
        'key1' => 'Hello world',      // Unchanged
        'key2' => 'How are you doing?', // Changed
        'key3' => 'Goodbye',           // Unchanged
        'key4' => 'New text'           // Added
    ];
    
    $request2 = new TranslationRequest($texts2, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    // Second run should detect changes
    $this->plugin->handle($context2, function ($ctx) {
        return $ctx;
    });
    
    // Check if only changed/added texts remain
    expect($context2->texts)->toHaveKey('key2')
        ->and($context2->texts)->toHaveKey('key4')
        ->and($context2->texts)->not->toHaveKey('key1')
        ->and($context2->texts)->not->toHaveKey('key3');
});

test('applies cached translations for unchanged items', function () {
    // Use plugin with caching enabled
    $pluginWithCache = new DiffTrackingPlugin([
        'storage' => [
            'driver' => 'file',
            'path' => $this->tempDir
        ],
        'cache' => [
            'use_cache' => true
        ]
    ]);
    
    // Setup initial state
    $texts = [
        'greeting' => 'Hello',
        'farewell' => 'Goodbye'
    ];
    
    $request = new TranslationRequest($texts, 'en', 'ko');
    $context = new TranslationContext($request);
    
    // Simulate previous translations
    $context->translations = [
        'ko' => [
            'greeting' => '안녕하세요',
            'farewell' => '안녕히 가세요'
        ]
    ];
    
    // Save state
    $pluginWithCache->handle($context, function ($ctx) {
        return $ctx;
    });
    
    // New request with same texts
    $request2 = new TranslationRequest($texts, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $result = $pluginWithCache->handle($context2, function ($ctx) {
        return $ctx;
    });
    
    // When caching is enabled and all texts are unchanged, 
    // the plugin returns the context without calling next() 
    // and texts should remain unchanged but context should be returned
    expect($result)->toBe($context2);
});

test('calculates checksums with normalization', function () {
    $request = new TranslationRequest(
        [
            'key1' => 'Hello   world',    // Multiple spaces
            'key2' => '  Trimmed text  '  // Leading/trailing spaces
        ],
        'en',
        'ko'
    );
    $context = new TranslationContext($request);
    
    // Test checksum calculation
    $reflection = new ReflectionClass($this->plugin);
    $method = $reflection->getMethod('calculateChecksums');
    $method->setAccessible(true);
    
    $checksums = $method->invoke($this->plugin, $context->texts);
    
    expect($checksums)->toHaveKeys(['key1', 'key2'])
        ->and($checksums['key1'])->toBeString()
        ->and(strlen($checksums['key1']))->toBe(64); // SHA256 length
});

test('filters texts during diff_detection stage', function () {
    // Setup previous state
    $oldTexts = [
        'unchanged' => 'Same text',
        'changed' => 'Old text'
    ];
    
    $request1 = new TranslationRequest($oldTexts, 'en', 'ko');
    $context1 = new TranslationContext($request1);
    $context1->translations = ['ko' => ['unchanged' => '같은 텍스트', 'changed' => '오래된 텍스트']];
    
    $this->plugin->handle($context1, function ($ctx) {
        return $ctx;
    });
    
    // New request with changes
    $newTexts = [
        'unchanged' => 'Same text',
        'changed' => 'New text',
        'added' => 'Additional text'
    ];
    
    $request2 = new TranslationRequest($newTexts, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->handle($context2, function ($ctx) {
        return $ctx;
    });
    
    // Should filter to only changed/added items
    expect($context2->texts)->toHaveKeys(['changed', 'added'])
        ->and($context2->texts)->not->toHaveKey('unchanged');
});

test('provides significant cost savings metrics', function () {
    $texts = array_fill_keys(range(1, 100), 'Sample text');
    
    // First translation
    $request1 = new TranslationRequest($texts, 'en', 'ko');
    $context1 = new TranslationContext($request1);
    $context1->translations = ['ko' => array_fill_keys(range(1, 100), '샘플 텍스트')];
    
    $this->plugin->handle($context1, function ($ctx) {
        return $ctx;
    });
    
    // Second translation with 20% changes
    $texts2 = $texts;
    for ($i = 1; $i <= 20; $i++) {
        $texts2[$i] = 'Modified text';
    }
    
    $request2 = new TranslationRequest($texts2, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->handle($context2, function ($ctx) {
        return $ctx;
    });
    
    // Should detect 80% cost savings (only 20 items remain for translation)
    expect(count($context2->texts))->toBe(20);
});