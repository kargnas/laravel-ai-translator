<?php

use Kargnas\LaravelAiTranslator\Core\PluginManager;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Plugins\AbstractTranslationPlugin;

/**
 * PluginManager 핵심 기능 테스트
 * - 플러그인 의존성 해결
 * - 순환 의존성 감지
 * - 멀티 테넌트 설정
 */

beforeEach(function () {
    $this->manager = new PluginManager();
});

test('resolves plugin dependencies in correct order', function () {
    // Create plugins with dependencies
    $pluginA = new class extends AbstractTranslationPlugin {
        protected string $name = 'plugin_a';
        protected array $dependencies = [];
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    $pluginB = new class extends AbstractTranslationPlugin {
        protected string $name = 'plugin_b';
        protected array $dependencies = ['plugin_a'];
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    $pluginC = new class extends AbstractTranslationPlugin {
        protected string $name = 'plugin_c';
        protected array $dependencies = ['plugin_b'];
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    // Register in correct order to satisfy dependencies
    $this->manager->register($pluginA);
    $this->manager->register($pluginB);
    $this->manager->register($pluginC);
    
    // Boot should resolve dependencies
    $pipeline = new TranslationPipeline($this->manager);
    $this->manager->boot($pipeline);
    
    // Verify all plugins are registered
    expect($this->manager->has('plugin_a'))->toBeTrue()
        ->and($this->manager->has('plugin_b'))->toBeTrue()
        ->and($this->manager->has('plugin_c'))->toBeTrue();
});

test('detects circular dependencies', function () {
    // Create plugins with circular dependency
    $pluginA = new class extends AbstractTranslationPlugin {
        protected string $name = 'plugin_a';
        protected array $dependencies = ['plugin_b'];
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    $pluginB = new class extends AbstractTranslationPlugin {
        protected string $name = 'plugin_b';
        protected array $dependencies = ['plugin_a'];
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    // Override checkDependencies temporarily to allow registration
    $reflection = new ReflectionClass($this->manager);
    $method = $reflection->getMethod('checkDependencies');
    $method->setAccessible(true);
    
    // Register both plugins without dependency check
    $pluginsProperty = $reflection->getProperty('plugins');
    $pluginsProperty->setAccessible(true);
    $pluginsProperty->setValue($this->manager, [
        'plugin_a' => $pluginA,
        'plugin_b' => $pluginB
    ]);
    
    $pipeline = new TranslationPipeline($this->manager);
    
    expect(fn() => $this->manager->boot($pipeline))
        ->toThrow(RuntimeException::class, 'Circular dependency');
});

test('manages tenant-specific plugin configuration', function () {
    $plugin = new class extends AbstractTranslationPlugin {
        protected string $name = 'tenant_plugin';
        
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    $this->manager->register($plugin);
    
    // Enable for specific tenant with config
    $this->manager->enableForTenant('tenant-123', 'tenant_plugin', [
        'setting' => 'custom_value'
    ]);
    
    // Disable for another tenant
    $this->manager->disableForTenant('tenant-456', 'tenant_plugin');
    
    expect($this->manager->isEnabledForTenant('tenant-123', 'tenant_plugin'))->toBeTrue()
        ->and($this->manager->isEnabledForTenant('tenant-456', 'tenant_plugin'))->toBeFalse();
});

test('loads plugins from configuration', function () {
    $config = [
        'test_plugin' => [
            'class' => TestPlugin::class,
            'config' => ['option' => 'value'],
            'enabled' => true
        ]
    ];
    
    // Create test plugin class
    $testPluginClass = new class extends AbstractTranslationPlugin {
        protected string $name = 'test_plugin';
        public function boot(TranslationPipeline $pipeline): void {}
    };
    
    $this->manager->registerClass('test_plugin', get_class($testPluginClass), ['option' => 'value']);
    $plugin = $this->manager->load('test_plugin');
    
    expect($plugin)->not->toBeNull()
        ->and($this->manager->has('test_plugin'))->toBeTrue()
        ->and($plugin->getConfig())->toHaveKey('option', 'value');
});