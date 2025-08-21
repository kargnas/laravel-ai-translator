<?php

use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PluginManager;

/**
 * TranslationBuilder API 테스트
 * - Fluent 체이닝 인터페이스
 * - 플러그인 자동 로딩
 * - 설정 검증
 */

beforeEach(function () {
    $this->builder = new TranslationBuilder(
        new TranslationPipeline(new PluginManager()),
        new PluginManager()
    );
});

test('supports fluent chaining interface', function () {
    $result = $this->builder
        ->from('en')
        ->to('ko')
        ->withStyle('formal')
        ->trackChanges()
        ->secure();
    
    expect($result)->toBeInstanceOf(TranslationBuilder::class);
    
    $config = $result->getConfig();
    
    expect($config['config']['source_locale'])->toBe('en')
        ->and($config['config']['target_locales'])->toBe('ko')
        ->and($config['plugins'])->toContain('style')
        ->and($config['plugins'])->toContain('diff_tracking')
        ->and($config['plugins'])->toContain('pii_masking');
});

test('handles multiple target locales', function () {
    $builder = $this->builder
        ->from('en')
        ->to(['ko', 'ja', 'zh']);
    
    $config = $builder->getConfig();
    
    expect($config['config']['target_locales'])->toBeArray()
        ->and($config['config']['target_locales'])->toHaveCount(3)
        ->and($config['config']['target_locales'])->toContain('ko', 'ja', 'zh');
});

test('configures plugins with options', function () {
    $builder = $this->builder
        ->withTokenChunking(3000)
        ->withValidation(['html', 'variables'])
        ->withGlossary(['API' => 'API', 'SDK' => 'SDK']);
    
    $config = $builder->getConfig();
    
    expect($config['plugin_configs']['token_chunking']['max_tokens'])->toBe(3000)
        ->and($config['plugin_configs']['validation']['checks'])->toBe(['html', 'variables'])
        ->and($config['plugin_configs']['glossary']['terms'])->toHaveKey('API', 'API');
});

test('validates required configuration before translation', function () {
    // Missing source locale
    $builder = $this->builder->to('ko');
    
    expect(fn() => $builder->translate(['test' => 'text']))
        ->toThrow(InvalidArgumentException::class, 'Source locale is required');
    
    // Missing target locale
    $builder = $this->builder->from('en');
    
    expect(fn() => $builder->translate(['test' => 'text']))
        ->toThrow(InvalidArgumentException::class, 'Target locale(s) required');
});

test('supports multi-tenant configuration', function () {
    $builder = $this->builder
        ->forTenant('tenant-123')
        ->from('en')
        ->to('ko');
    
    $config = $builder->getConfig();
    
    expect($config['tenant_id'])->toBe('tenant-123');
});

test('allows custom plugin registration', function () {
    $customPlugin = new class extends \Kargnas\LaravelAiTranslator\Plugins\AbstractTranslationPlugin {
        protected string $name = 'custom_test';
        
        public function boot(\Kargnas\LaravelAiTranslator\Core\TranslationPipeline $pipeline): void {
            // Custom boot logic
        }
    };
    
    $builder = $this->builder->withPlugin($customPlugin);
    
    $config = $builder->getConfig();
    
    expect($config['plugins'])->toContain('custom_test');
});

test('provides streaming capability', function () {
    $builder = $this->builder
        ->from('en')
        ->to('ko');
    
    // Mock texts
    $texts = ['hello' => 'Hello', 'world' => 'World'];
    
    // Stream method should return generator
    $stream = $builder->stream($texts);
    
    expect($stream)->toBeInstanceOf(Generator::class);
});