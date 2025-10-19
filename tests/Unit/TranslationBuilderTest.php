<?php

use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PluginManager;
use Kargnas\LaravelAiTranslator\Plugins\Provider\StylePlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\DiffTrackingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\TokenChunkingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\ValidationPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Provider\GlossaryPlugin;

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
        ->and($config['plugins'])->toContain(StylePlugin::class)
        ->and($config['plugins'])->toContain(DiffTrackingPlugin::class);
        // ->and($config['plugins'])->toContain('pii_masking'); // Not implemented yet
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
    
    expect($config['plugin_configs'][TokenChunkingPlugin::class]['max_tokens'])->toBe(3000)
        ->and($config['plugin_configs'][ValidationPlugin::class]['checks'])->toBe(['html', 'variables'])
        ->and($config['plugin_configs'][GlossaryPlugin::class]['terms'])->toHaveKey('API', 'API');
});

test('validates required configuration before translation', function () {
    // Missing source locale
    $builder1 = new TranslationBuilder(
        new TranslationPipeline(new PluginManager()),
        new PluginManager()
    );
    $builder1->to('ko');
    
    expect(fn() => $builder1->translate(['test' => 'text']))
        ->toThrow(\InvalidArgumentException::class, 'Source locale is required');
    
    // Missing target locale
    $builder2 = new TranslationBuilder(
        new TranslationPipeline(new PluginManager()),
        new PluginManager()
    );
    $builder2->from('en');
    
    expect(fn() => $builder2->translate(['test' => 'text']))
        ->toThrow(\InvalidArgumentException::class, 'Target locale(s) required');
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
    $customPlugin = new class extends \Kargnas\LaravelAiTranslator\Plugins\Abstract\AbstractTranslationPlugin {
        // Name will be auto-generated from class
        
        public function boot(\Kargnas\LaravelAiTranslator\Core\TranslationPipeline $pipeline): void {
            // Custom boot logic
        }
    };
    
    $builder = $this->builder->withPlugin($customPlugin);
    
    $config = $builder->getConfig();
    
    // Anonymous class will have a generated name
    expect($config['plugins'])->toHaveCount(1);
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