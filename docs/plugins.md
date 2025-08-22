# Laravel AI Translator - Plugin Documentation

## Overview

Laravel AI Translator uses a powerful plugin-based architecture that allows you to extend and customize the translation pipeline. Plugins can modify translation behavior, add new features, and integrate with external services.

## Table of Contents

1. [Available Plugins](#available-plugins)
2. [Using Plugins](#using-plugins)
3. [Creating Custom Plugins](#creating-custom-plugins)
4. [Plugin Examples](#plugin-examples)

## Available Plugins

### Core Plugins

#### 1. **StylePlugin**
Applies custom translation styles to maintain consistent tone and voice.

```php
TranslationBuilder::make()
    ->withStyle('formal', 'Use professional language suitable for business')
    ->translate($texts);
```

**Options:**
- `style`: Style name (e.g., 'formal', 'casual', 'technical')
- `custom_prompt`: Additional instructions for the AI

#### 2. **GlossaryPlugin**
Ensures consistent translation of specific terms across your application.

```php
TranslationBuilder::make()
    ->withGlossary([
        'API' => 'API',  // Keep as-is
        'Laravel' => '라라벨',  // Force specific translation
        'framework' => '프레임워크',
    ])
    ->translate($texts);
```

#### 3. **DiffTrackingPlugin**
Tracks changes between translation sessions to avoid retranslating unchanged content.

```php
TranslationBuilder::make()
    ->trackChanges()  // Enable diff tracking
    ->translate($texts);
```

**Benefits:**
- Reduces API costs by 60-80% for unchanged content
- Maintains translation consistency
- Speeds up translation process

#### 4. **TokenChunkingPlugin**
Automatically splits large texts into optimal chunks for AI processing.

```php
TranslationBuilder::make()
    ->withTokenChunking(3000)  // Max tokens per chunk
    ->translate($texts);
```

**Options:**
- `max_tokens_per_chunk`: Maximum tokens per API call (default: 2000)

#### 5. **ValidationPlugin**
Validates translations to ensure quality and accuracy.

```php
TranslationBuilder::make()
    ->withValidation(['html', 'variables', 'punctuation'])
    ->translate($texts);
```

**Available Checks:**
- `html`: Validates HTML tag preservation
- `variables`: Ensures variable placeholders are maintained
- `punctuation`: Checks punctuation consistency
- `length`: Warns about significant length differences

#### 6. **PIIMaskingPlugin**
Protects sensitive information during translation.

```php
TranslationBuilder::make()
    ->secure()  // Enable PII masking
    ->translate($texts);
```

**Protected Data:**
- Email addresses
- Phone numbers
- Credit card numbers
- Social Security Numbers
- IP addresses
- Custom patterns

#### 7. **StreamingOutputPlugin**
Provides real-time translation progress updates.

```php
TranslationBuilder::make()
    ->onProgress(function($output) {
        echo "Translated: {$output->key}\n";
    })
    ->translate($texts);
```

#### 8. **MultiProviderPlugin**
Uses multiple AI providers for consensus-based translation.

```php
TranslationBuilder::make()
    ->withProviders(['gpt-4', 'claude-3', 'gemini'])
    ->translate($texts);
```

#### 9. **AnnotationContextPlugin**
Adds contextual information from code comments and annotations.

```php
TranslationBuilder::make()
    ->withContext('User dashboard messages', '/screenshots/dashboard.png')
    ->translate($texts);
```

## Using Plugins

### Basic Usage

```php
use Kargnas\LaravelAiTranslator\TranslationBuilder;

$result = TranslationBuilder::make()
    ->from('en')
    ->to(['ko', 'ja'])
    ->withStyle('friendly')
    ->withGlossary(['brand' => 'MyApp'])
    ->trackChanges()
    ->secure()
    ->translate($texts);
```

### Advanced Configuration

```php
// Custom plugin instance
use Kargnas\LaravelAiTranslator\Plugins\PIIMaskingPlugin;

$piiPlugin = new PIIMaskingPlugin([
    'mask_emails' => true,
    'mask_phones' => true,
    'mask_custom_patterns' => [
        '/EMP-\d{6}/' => 'EMPLOYEE_ID',
    ],
]);

$result = TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withPlugin($piiPlugin)
    ->translate($texts);
```

### Plugin Chaining

Plugins work together seamlessly:

```php
$result = TranslationBuilder::make()
    ->from('en')
    ->to(['ko', 'ja', 'zh'])
    // Performance optimization
    ->trackChanges()
    ->withTokenChunking(2500)
    
    // Quality assurance
    ->withStyle('professional')
    ->withGlossary($companyTerms)
    ->withValidation(['all'])
    
    // Security
    ->secure()
    
    // Progress tracking
    ->onProgress(function($output) {
        $this->updateProgressBar($output);
    })
    ->translate($texts);
```

## Creating Custom Plugins

### Plugin Types

#### 1. Middleware Plugin
Modifies data as it flows through the pipeline.

```php
use Kargnas\LaravelAiTranslator\Plugins\AbstractMiddlewarePlugin;

class CustomFormatterPlugin extends AbstractMiddlewarePlugin
{
    protected function getStage(): string
    {
        return 'post_process';
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        // Pre-processing
        foreach ($context->texts as $key => $text) {
            // Modify texts before next stage
        }
        
        // Continue pipeline
        $result = $next($context);
        
        // Post-processing
        foreach ($context->translations as $locale => &$translations) {
            // Modify translations after
        }
        
        return $result;
    }
}
```

#### 2. Provider Plugin
Provides services to the pipeline.

```php
use Kargnas\LaravelAiTranslator\Plugins\AbstractProviderPlugin;

class CustomTranslationProvider extends AbstractProviderPlugin
{
    public function provides(): array
    {
        return ['custom_translation'];
    }
    
    public function when(): array
    {
        return ['translation'];
    }
    
    public function execute(TranslationContext $context): mixed
    {
        // Your translation logic
        $translations = $this->callCustomAPI($context->texts);
        
        foreach ($translations as $locale => $items) {
            foreach ($items as $key => $value) {
                $context->addTranslation($locale, $key, $value);
            }
        }
        
        return $translations;
    }
}
```

#### 3. Observer Plugin
Monitors events without modifying data.

```php
use Kargnas\LaravelAiTranslator\Plugins\AbstractObserverPlugin;

class MetricsCollectorPlugin extends AbstractObserverPlugin
{
    public function subscribe(): array
    {
        return [
            'translation.started' => 'onStart',
            'translation.completed' => 'onComplete',
            'translation.failed' => 'onError',
        ];
    }
    
    public function onStart(TranslationContext $context): void
    {
        $this->startTimer();
        $this->logMetric('translation.started', [
            'text_count' => count($context->texts),
            'target_locales' => $context->request->targetLocales,
        ]);
    }
    
    public function onComplete(TranslationContext $context): void
    {
        $duration = $this->stopTimer();
        $this->logMetric('translation.completed', [
            'duration' => $duration,
            'token_usage' => $context->tokenUsage,
        ]);
    }
}
```

### Custom Stage Plugin

Add entirely new stages to the pipeline:

```php
class QualityReviewPlugin extends AbstractObserverPlugin
{
    const REVIEW_STAGE = 'quality_review';
    
    public function boot(TranslationPipeline $pipeline): void
    {
        // Register custom stage
        $pipeline->registerStage(self::REVIEW_STAGE, [$this, 'reviewTranslations'], 150);
        
        parent::boot($pipeline);
    }
    
    public function reviewTranslations(TranslationContext $context): void
    {
        foreach ($context->translations as $locale => $translations) {
            foreach ($translations as $key => $translation) {
                $score = $this->calculateQualityScore($translation);
                
                if ($score < 0.7) {
                    $context->addWarning("Low quality score for {$key} in {$locale}");
                }
            }
        }
    }
}
```

### Using Custom Plugins

```php
// Method 1: Plugin instance
$customPlugin = new CustomFormatterPlugin(['option' => 'value']);
$builder->withPlugin($customPlugin);

// Method 2: Plugin class
$builder->withPluginClass(CustomFormatterPlugin::class, ['option' => 'value']);

// Method 3: Inline closure
$builder->withClosure('quick_modifier', function($pipeline) {
    $pipeline->registerStage('custom', function($context) {
        // Quick modification logic
    });
});
```

## Plugin Examples

### Example 1: Multi-Tenant Configuration

```php
class TenantTranslationService
{
    public function translateForTenant(string $tenantId, array $texts)
    {
        $builder = TranslationBuilder::make()
            ->from('en')
            ->to($this->getTenantLocales($tenantId))
            ->forTenant($tenantId);
        
        // Apply tenant-specific configuration
        if ($this->tenantRequiresFormalStyle($tenantId)) {
            $builder->withStyle('formal');
        }
        
        if ($glossary = $this->getTenantGlossary($tenantId)) {
            $builder->withGlossary($glossary);
        }
        
        if ($this->tenantRequiresSecurity($tenantId)) {
            $builder->secure();
        }
        
        return $builder->translate($texts);
    }
}
```

### Example 2: Batch Processing with Progress

```php
class BatchTranslationJob
{
    public function handle()
    {
        $texts = $this->loadTexts();
        $progress = 0;
        
        $result = TranslationBuilder::make()
            ->from('en')
            ->to(['es', 'fr', 'de'])
            ->trackChanges()  // Skip unchanged
            ->withTokenChunking(3000)  // Optimize API calls
            ->onProgress(function($output) use (&$progress) {
                $progress++;
                $this->updateJobProgress($progress);
                
                // Log milestone progress
                if ($progress % 100 === 0) {
                    Log::info("Processed {$progress} translations");
                }
            })
            ->translate($texts);
        
        $this->saveResults($result);
    }
}
```

### Example 3: Custom API Integration

```php
class DeepLProvider extends AbstractProviderPlugin
{
    public function provides(): array
    {
        return ['deepl_translation'];
    }
    
    public function execute(TranslationContext $context): mixed
    {
        $client = new DeepLClient($this->getConfigValue('api_key'));
        
        foreach ($context->request->targetLocales as $locale) {
            $response = $client->translate(
                $context->texts,
                $context->request->sourceLocale,
                $locale
            );
            
            foreach ($response->getTranslations() as $key => $translation) {
                $context->addTranslation($locale, $key, $translation);
            }
        }
        
        return $context->translations;
    }
}

// Usage
$translator = TranslationBuilder::make()
    ->from('en')->to('de')
    ->withPlugin(new DeepLProvider(['api_key' => env('DEEPL_KEY')]))
    ->translate($texts);
```

### Example 4: Content Moderation

```php
class ContentModerationPlugin extends AbstractMiddlewarePlugin
{
    protected function getStage(): string
    {
        return 'pre_process';
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        foreach ($context->texts as $key => $text) {
            if ($this->containsInappropriateContent($text)) {
                // Flag for review
                $context->addWarning("Content flagged for review: {$key}");
                
                // Optionally skip translation
                unset($context->texts[$key]);
            }
        }
        
        return $next($context);
    }
    
    private function containsInappropriateContent(string $text): bool
    {
        // Your moderation logic
        return false;
    }
}
```

## Best Practices

1. **Plugin Order Matters**: Plugins execute in the order they're registered. Place security plugins early, formatting plugins late.

2. **Use Appropriate Plugin Type**: 
   - Middleware for data transformation
   - Provider for service integration
   - Observer for monitoring/logging

3. **Handle Errors Gracefully**: Always provide fallback behavior when your plugin encounters errors.

4. **Optimize Performance**: 
   - Use `trackChanges()` to avoid retranslating unchanged content
   - Use `withTokenChunking()` for large datasets
   - Cache plugin results when appropriate

5. **Test Your Plugins**: Write unit tests for custom plugins to ensure reliability.

6. **Document Configuration**: Clearly document all configuration options for your custom plugins.

## Plugin Configuration Reference

### Global Plugin Settings

```php
// config/ai-translator.php
return [
    'plugins' => [
        'enabled' => [
            'style' => true,
            'glossary' => true,
            'diff_tracking' => true,
        ],
        
        'config' => [
            'diff_tracking' => [
                'storage_path' => storage_path('translations/cache'),
                'ttl' => 86400, // 24 hours
            ],
            
            'pii_masking' => [
                'mask_emails' => true,
                'mask_phones' => true,
                'mask_credit_cards' => true,
            ],
        ],
    ],
];
```

### Per-Request Configuration

```php
$result = TranslationBuilder::make()
    ->option('plugin.diff_tracking.ttl', 3600)
    ->option('plugin.validation.strict', true)
    ->translate($texts);
```

## Troubleshooting

### Plugin Not Loading

```php
// Check if plugin is registered
$pluginManager = app(PluginManager::class);
if (!$pluginManager->has('my_plugin')) {
    $pluginManager->register(new MyPlugin());
}
```

### Plugin Conflicts

```php
// Disable conflicting plugin
$builder = TranslationBuilder::make()
    ->withPlugin(new PluginA())
    ->withPlugin(new PluginB())
    ->option('disable_plugins', ['conflicting_plugin']);
```

### Performance Issues

```php
// Profile plugin execution
$builder->withClosure('profiler', function($pipeline) {
    $pipeline->on('stage.*.started', function($context) {
        Log::debug("Stage started: {$context->currentStage}");
    });
});
```

## Further Resources

- [Plugin Architecture Overview](./architecture.md)
- [API Reference](./api-reference.md)
- [Example Projects](./examples/)
- [Contributing Guide](../CONTRIBUTING.md)