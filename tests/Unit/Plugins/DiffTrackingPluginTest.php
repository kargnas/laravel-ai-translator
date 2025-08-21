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
    
    $this->plugin->onTranslationStarted($context1);
    $this->plugin->onTranslationCompleted($context1);
    
    // Second translation with partial changes
    $texts2 = [
        'key1' => 'Hello world',      // Unchanged
        'key2' => 'How are you doing?', // Changed
        'key3' => 'Goodbye',           // Unchanged
        'key4' => 'New text'           // Added
    ];
    
    $request2 = new TranslationRequest($texts2, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->onTranslationStarted($context2);
    
    $pluginData = $context2->getPluginData('DiffTrackingPlugin');
    $changes = $pluginData['changes'];
    
    expect($changes['unchanged'])->toHaveKeys(['key1', 'key3'])
        ->and($changes['changed'])->toHaveKey('key2')
        ->and($changes['added'])->toHaveKey('key4')
        ->and(count($changes['unchanged']))->toBe(2);
});

test('applies cached translations for unchanged items', function () {
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
    $this->plugin->onTranslationStarted($context);
    $this->plugin->onTranslationCompleted($context);
    
    // New request with same texts
    $request2 = new TranslationRequest($texts, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->onTranslationStarted($context2);
    
    // Check cached translations were applied
    expect($context2->translations['ko'])->toHaveKey('greeting', '안녕하세요')
        ->and($context2->translations['ko'])->toHaveKey('farewell', '안녕히 가세요')
        ->and($context2->metadata)->toHaveKey('cached_translations');
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
    
    $this->plugin->onTranslationStarted($context1);
    $this->plugin->onTranslationCompleted($context1);
    
    // New request with changes
    $newTexts = [
        'unchanged' => 'Same text',
        'changed' => 'New text',
        'added' => 'Additional text'
    ];
    
    $request2 = new TranslationRequest($newTexts, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->onTranslationStarted($context2);
    $this->plugin->performDiffDetection($context2);
    
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
    
    $this->plugin->onTranslationStarted($context1);
    $this->plugin->onTranslationCompleted($context1);
    
    // Second translation with 20% changes
    $texts2 = $texts;
    for ($i = 1; $i <= 20; $i++) {
        $texts2[$i] = 'Modified text';
    }
    
    $request2 = new TranslationRequest($texts2, 'en', 'ko');
    $context2 = new TranslationContext($request2);
    
    $this->plugin->onTranslationStarted($context2);
    
    $pluginData = $context2->getPluginData('DiffTrackingPlugin');
    $changes = $pluginData['changes'];
    
    // Should detect 80% unchanged (80% cost savings)
    expect(count($changes['unchanged']))->toBe(80)
        ->and(count($changes['changed']))->toBe(20);
});