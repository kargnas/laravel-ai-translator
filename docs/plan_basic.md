# Laravel AI Translator - Plugin-based Pipeline Architecture Implementation Plan

## Overview
This document outlines the complete implementation plan for Laravel AI Translator with a plugin-based pipeline architecture, providing both programmatic API for SaaS applications and maintaining full backward compatibility with existing commands.

## Core Architecture: Plugin System + Pipeline + Chaining API

### 1. Plugin Interface (`src/Contracts/TranslationPlugin.php`)

```php
interface TranslationPlugin {
    public function getName(): string;
    public function getVersion(): string;
    public function getDependencies(): array;
    public function getPriority(): int;
    public function boot(TranslationPipeline $pipeline): void;
    public function register(PluginRegistry $registry): void;
    public function isEnabledFor(?string $tenant = null): bool;
}

abstract class AbstractTranslationPlugin implements TranslationPlugin {
    protected array $config = [];
    protected array $hooks = [];
    
    public function hook(string $stage, callable $handler, int $priority = 0): void;
    public function configure(array $config): self;
}
```

### 2. Core Pipeline (`src/Core/TranslationPipeline.php`)

```php
class TranslationPipeline {
    protected array $stages = [
        'pre_process' => [],
        'diff_detection' => [],
        'preparation' => [],
        'chunking' => [],
        'translation' => [],
        'consensus' => [],
        'validation' => [],
        'post_process' => [],
        'output' => []
    ];
    
    protected PluginManager $pluginManager;
    protected TranslationContext $context;
    
    public function registerStage(string $name, callable $handler, int $priority = 0): void;
    public async function* process(TranslationRequest $request): AsyncGenerator;
}
```

### 3. Plugin Manager (`src/Plugins/PluginManager.php`)

```php
class PluginManager {
    protected array $plugins = [];
    protected array $tenantPlugins = [];
    
    public function register(TranslationPlugin $plugin): void;
    public function enableForTenant(string $tenant, string $pluginName, array $config = []): void;
    public function getEnabled(?string $tenant = null): array;
}
```

## Built-in Plugins

### 1. Style Plugin (`src/Plugins/StylePlugin.php`)
- Pre-configured language-specific styles (formal, casual, technical, marketing)
- Language defaults (e.g., Korean: 존댓말/반말, Japanese: 敬語/タメ口)
- Custom prompt injection support

### 2. Diff Tracking Plugin (`src/Plugins/DiffTrackingPlugin.php`)
- Tracks changes between translation sessions
- Stores state using Laravel Storage Facade
- Default path: `storage/app/ai-translator/states/`
- Supports file, database, and Redis adapters

### 3. Multi-Provider Plugin (`src/Plugins/MultiProviderPlugin.php`)
- Configurable providers with model, temperature, and thinking mode
- Special handling: gpt-5 always uses temperature 1.0
- Parallel execution for multiple providers
- Consensus selection using specified judge model (default: gpt-5 with temperature 0.3)

### 4. Annotation Context Plugin (`src/Plugins/AnnotationContextPlugin.php`)
- Extracts translation context from PHP docblocks
- Supports @translate-context, @translate-style, @translate-glossary annotations

### 5. Token Chunking Plugin (`src/Plugins/TokenChunkingPlugin.php`)
- Language-aware token estimation
- Dynamic chunk size based on token count (not item count)
- CJK languages: 1.5 tokens per character
- Latin languages: 0.25 tokens per character

### 6. Validation Plugin (`src/Plugins/ValidationPlugin.php`)
- HTML tag preservation check
- Variable/placeholder validation (`:var`, `{{var}}`, `%s`)
- Length ratio verification
- Optional back-translation

### 7. PII Masking Plugin (`src/Plugins/PIIMaskingPlugin.php`)
- Masks emails, phones, SSNs, credit cards
- Token-based replacement and restoration
- Configurable patterns

### 8. Streaming Output Plugin (`src/Plugins/StreamingOutputPlugin.php`)
- AsyncGenerator-based streaming
- Real-time translation output
- Cached vs. new translation differentiation

### 9. Glossary Plugin (`src/Plugins/GlossaryPlugin.php`)
- In-memory term management
- Domain-specific glossaries
- Auto-applied during preparation stage

## User API: TranslationBuilder (Chaining Interface)

### Core Builder Class (`src/TranslationBuilder.php`)

```php
class TranslationBuilder {
    protected TranslationPipeline $pipeline;
    protected array $config = [];
    protected array $plugins = [];
    
    // Basic chaining methods
    public static function make(): self;
    public function from(string $locale): self;
    public function to(string|array $locales): self;
    
    // Plugin configuration methods
    public function withStyle(string $style, ?string $customPrompt = null): self;
    public function withProviders(array $providers): self;
    public function withGlossary(array $terms): self;
    public function trackChanges(bool $enable = true): self;
    public function withContext(string $description = null, string $screenshot = null): self;
    public function withPlugin(TranslationPlugin $plugin): self;
    public function withTokenChunking(int $maxTokens = 2000): self;
    public function withValidation(array $checks = ['all']): self;
    public function secure(): self; // Enables PII masking
    
    // Execution with async/promise support
    public async function translate(array $texts): TranslationResult;
    public function onProgress(callable $callback): self;
}
```

### TranslationResult Class (`src/Results/TranslationResult.php`)

```php
class TranslationResult {
    public function __construct(
        protected array $translations,
        protected array $tokenUsage,
        protected string $sourceLocale,
        protected string|array $targetLocales,
        protected array $metadata = []
    );
    
    public function getTranslations(): array;
    public function getTranslation(string $key): ?string;
    public function getTokenUsage(): array;
    public function getCost(): float;
    public function getDiff(): array; // Changed items only
    public function toArray(): array;
    public function toJson(): string;
}
```

### Laravel Facade (`src/Facades/Translate.php`)

```php
class Translate extends Facade {
    public static function text(string $text, string $from, string $to): string;
    public static function array(array $texts, string $from, string $to): array;
    public static function builder(): TranslationBuilder;
}
```

## Usage Examples

### Basic Usage
```php
// Simple translation
$result = await TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->translate(['hello' => 'Hello World']);

// Using Facade
$translated = Translate::text('Hello World', 'en', 'ko');
```

### Full-Featured Translation
```php
$result = await TranslationBuilder::make()
    ->from('en')
    ->to(['ko', 'ja', 'zh'])
    ->withStyle('formal', 'Use professional business tone')
    ->withProviders([
        'claude' => [
            'provider' => 'anthropic',
            'model' => 'claude-opus-4-1-20250805',
            'temperature' => 0.3,
            'thinking' => true
        ],
        'gpt' => [
            'provider' => 'openai',
            'model' => 'gpt-5',
            'temperature' => 1.0, // Auto-fixed for gpt-5
            'thinking' => false
        ],
        'gemini' => [
            'provider' => 'google',
            'model' => 'gemini-2.5-pro',
            'temperature' => 0.5,
            'thinking' => false
        ]
    ])
    ->withGlossary(['login' => '로그인', 'password' => '비밀번호'])
    ->withContext(
        description: 'Mobile app login screen for banking application',
        screenshot: '/path/to/screenshot.png'
    )
    ->withTokenChunking(2000) // Max 2000 tokens per chunk
    ->withValidation(['html', 'variables', 'length'])
    ->trackChanges() // Only translate changed items
    ->secure() // Enable PII masking
    ->onProgress(fn($output) => echo "{$output->key}: {$output->value}\n")
    ->translate($texts);
```

### With PHP Annotations
```php
// In language file:
/**
 * @translate-context Button for user authentication
 * @translate-style formal
 * @translate-glossary authenticate => 인증하기
 */
'login_button' => 'Login',

// Annotations are automatically extracted by AnnotationContextPlugin
```

## Command Integration Strategy

### Backward Compatibility Wrapper

```php
class TranslateStrings extends Command {
    public function handle() {
        $transformer = new PHPLangTransformer($file);
        $strings = $transformer->flatten();
        
        // Convert old options to new API
        $builder = TranslationBuilder::make()
            ->from($this->sourceLocale)
            ->to($this->targetLocale)
            ->trackChanges(); // Use diff tracking
        
        // Configure providers from config
        if ($provider = config('ai-translator.ai.provider')) {
            $builder->withProviders([
                'default' => [
                    'provider' => $provider,
                    'model' => config('ai-translator.ai.model'),
                    'temperature' => config('ai-translator.ai.temperature', 0.3),
                    'thinking' => config('ai-translator.ai.use_extended_thinking', false)
                ]
            ]);
        }
        
        // Handle chunk option (convert to tokens)
        if ($chunkSize = $this->option('chunk')) {
            $builder->withTokenChunking($chunkSize * 40); // Approximate
        }
        
        // Handle reference locales
        if ($this->referenceLocales) {
            $builder->withReference($this->referenceLocales);
        }
        
        // Execute translation
        $result = await $builder
            ->onProgress([$this, 'displayProgress'])
            ->translate($strings);
        
        // Save results to file
        foreach ($result->getTranslations() as $key => $value) {
            $transformer->updateString($key, $value);
        }
    }
}
```

### Parallel Command Support

```php
class TranslateStringsParallel extends Command {
    public function handle() {
        $locales = $this->option('locale');
        
        // Use Laravel Jobs for parallel processing
        foreach ($locales as $locale) {
            TranslateLocaleJob::dispatch(
                $this->sourceLocale,
                $locale,
                $this->options()
            );
        }
        
        // Or use async/await with promises
        $promises = [];
        foreach ($locales as $locale) {
            $promises[] = TranslationBuilder::make()
                ->from($this->sourceLocale)
                ->to($locale)
                ->trackChanges()
                ->translate($strings);
        }
        
        $results = await Promise::all($promises);
    }
}
```

## Configuration

### Extended config/ai-translator.php

```php
return [
    // Existing configuration maintained
    'source_directory' => 'lang',
    'source_locale' => 'en',
    'ai' => [...], // Existing AI config
    
    // New plugin configuration
    'plugins' => [
        'enabled' => [
            'style',
            'diff_tracking',
            'multi_provider',
            'token_chunking',
            'validation',
            'pii_masking',
            'streaming',
            'glossary',
            'annotation_context'
        ],
        
        'config' => [
            'diff_tracking' => [
                'storage' => [
                    'driver' => env('AI_TRANSLATOR_STATE_DRIVER', 'file'),
                    'path' => 'ai-translator/states',
                ]
            ],
            
            'multi_provider' => [
                'providers' => [
                    'primary' => [
                        'provider' => env('AI_TRANSLATOR_PROVIDER', 'anthropic'),
                        'model' => env('AI_TRANSLATOR_MODEL'),
                        'temperature' => 0.3,
                        'thinking' => false,
                    ]
                ],
                'judge' => [
                    'provider' => 'openai',
                    'model' => 'gpt-5',
                    'temperature' => 0.3, // Fixed for consensus
                    'thinking' => true
                ]
            ],
            
            'token_chunking' => [
                'max_tokens_per_chunk' => 2000,
                'estimation_multipliers' => [
                    'cjk' => 1.5,
                    'arabic' => 0.8,
                    'cyrillic' => 0.7,
                    'latin' => 0.25
                ]
            ]
        ]
    ],
    
    // State storage configuration
    'state_storage' => [
        'driver' => env('AI_TRANSLATOR_STATE_DRIVER', 'file'),
        'drivers' => [
            'file' => [
                'disk' => 'local',
                'path' => 'ai-translator/states',
            ],
            'database' => [
                'table' => 'translation_states',
            ],
            'redis' => [
                'connection' => 'default',
                'prefix' => 'ai_translator_state',
            ]
        ]
    ]
];
```

## ServiceProvider Updates

```php
class ServiceProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void {
        // Keep existing commands
        $this->commands([
            CleanCommand::class,
            FindUnusedTranslations::class,
            TranslateStrings::class,
            TranslateStringsParallel::class,
            TranslateCrowdinParallel::class,
            TranslateCrowdin::class,
            TestTranslateCommand::class,
            TranslateFileCommand::class,
            TranslateJson::class,
        ]);
        
        // Register new services
        $this->app->singleton(TranslationPipeline::class);
        $this->app->singleton(PluginManager::class);
        $this->app->bind('translator', TranslationBuilder::class);
        
        // Auto-register plugins
        $this->registerPlugins();
    }
    
    public function boot(): void {
        // Existing publishes
        $this->publishes([
            __DIR__.'/../config/ai-translator.php' => config_path('ai-translator.php'),
        ]);
        
        // Register Facade
        $this->app->booting(function () {
            $loader = AliasLoader::getInstance();
            $loader->alias('Translate', Translate::class);
        });
    }
    
    protected function registerPlugins(): void {
        $pluginManager = $this->app->make(PluginManager::class);
        
        // Register built-in plugins
        $enabledPlugins = config('ai-translator.plugins.enabled', []);
        
        foreach ($enabledPlugins as $pluginName) {
            $plugin = $this->createPlugin($pluginName);
            if ($plugin) {
                $pluginManager->register($plugin);
            }
        }
    }
}
```

## Multi-tenant SaaS Support

```php
class TenantTranslationService {
    protected PluginManager $pluginManager;
    
    public function translateForTenant(string $tenantId, array $texts, array $options = []) {
        // Configure tenant-specific plugins
        $this->pluginManager->enableForTenant($tenantId, 'rate_limit', [
            'max_requests' => 100,
            'per_minute' => 10
        ]);
        
        $this->pluginManager->enableForTenant($tenantId, 'style', [
            'default' => $options['style'] ?? 'formal'
        ]);
        
        // Execute translation with tenant context
        return TranslationBuilder::make()
            ->forTenant($tenantId)
            ->from($options['source'] ?? 'en')
            ->to($options['target'] ?? 'ko')
            ->trackChanges()
            ->translate($texts);
    }
}
```

## Storage Locations (Laravel Standard)

- **State files**: `storage/app/ai-translator/states/`
- **Cache**: Laravel Cache (Redis/Memcached/File)
- **Logs**: `storage/logs/ai-translator.log`
- **Temp files**: `storage/app/temp/ai-translator/`

## Implementation Order

1. **Core Pipeline** (Week 1)
   - TranslationPipeline class
   - PluginManager class
   - PipelineStage interface
   - TranslationContext class

2. **Essential Plugins** (Week 2)
   - DiffTrackingPlugin (change detection)
   - TokenChunkingPlugin (token-based chunking)
   - MultiProviderPlugin (multiple AI providers)
   - StreamingOutputPlugin (streaming support)

3. **Builder API** (Week 3)
   - TranslationBuilder (chaining interface)
   - TranslationResult class
   - Promise/Async support
   - Laravel Facade

4. **Additional Plugins** (Week 4)
   - StylePlugin (pre-prompted styles)
   - ValidationPlugin (quality checks)
   - PIIMaskingPlugin (security)
   - GlossaryPlugin (terminology)
   - AnnotationContextPlugin (PHP annotations)

5. **Command Wrappers** (Week 5)
   - Update existing commands
   - Ensure backward compatibility
   - Add new options support

6. **Testing & Documentation** (Week 6)
   - Unit tests for plugins
   - Integration tests
   - API documentation
   - Migration guide

## Key Features

1. **Plugin Architecture**: All features as modular plugins
2. **Chaining API**: User-friendly fluent interface
3. **Pipeline Stages**: Clear processing steps
4. **Streaming by Default**: AsyncGenerator for real-time output
5. **Token-based Chunking**: Language-aware token estimation
6. **Multi-provider Consensus**: Multiple AI results with judge selection
7. **Change Tracking**: Avoid unnecessary retranslation
8. **Context Awareness**: Screenshots, descriptions, annotations
9. **Promise Pattern**: Modern async handling
10. **Full Backward Compatibility**: Existing commands work unchanged

## Migration Guide

### From Existing Code
```php
// Old way
$translator = new AIProvider(...);
$result = $translator->translate();

// New way (simple)
$result = await TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->translate($strings);

// New way (with features)
$result = await TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withStyle('formal')
    ->trackChanges()
    ->secure()
    ->translate($strings);
```

### Custom Plugin Development
```php
class MyCustomPlugin extends AbstractTranslationPlugin {
    public function getName(): string {
        return 'my_custom_plugin';
    }
    
    public function boot(TranslationPipeline $pipeline): void {
        $pipeline->registerStage('preparation', [$this, 'process'], 100);
    }
    
    public function process(TranslationContext $context): void {
        // Custom processing logic
    }
}

// Usage
$result = await TranslationBuilder::make()
    ->withPlugin(new MyCustomPlugin())
    ->translate($strings);
```

## Performance Considerations

- **Streaming**: Reduces memory usage for large translations
- **Token-based chunking**: Optimizes API calls
- **Diff tracking**: Reduces unnecessary translations by 60-80%
- **Parallel processing**: Multi-locale support via Jobs
- **Plugin priority**: Ensures optimal execution order

## Security & Compliance

- **PII Masking**: Automatic sensitive data protection
- **Audit logging**: Complete translation history
- **Rate limiting**: Per-tenant/user limits
- **Input sanitization**: XSS and injection prevention
- **Token budget management**: Cost control per tenant

## Future Enhancements

- WebSocket support for real-time collaboration
- GraphQL API endpoint
- Translation memory integration
- Machine learning for quality improvement
- Custom model fine-tuning support
- Real-time collaborative editing
- Version control for translations
- A/B testing for translation variations