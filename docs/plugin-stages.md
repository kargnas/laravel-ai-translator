# Plugin Stage Architecture

## Overview

The Laravel AI Translator pipeline architecture provides both **core stages** and **dynamic stage registration**, allowing maximum flexibility for plugin developers.

## Core Stages

Core stages are defined as constants in `PipelineStages` class for consistency:

```php
use Kargnas\LaravelAiTranslator\Core\PipelineStages;

// Core stages
PipelineStages::PRE_PROCESS      // Initial validation and setup
PipelineStages::DIFF_DETECTION   // Detect changes from previous translations
PipelineStages::PREPARATION      // Prepare texts for translation
PipelineStages::CHUNKING         // Split texts into optimal chunks
PipelineStages::TRANSLATION      // Perform actual translation
PipelineStages::CONSENSUS        // Resolve conflicts between providers
PipelineStages::VALIDATION       // Validate translation quality
PipelineStages::POST_PROCESS     // Final processing and cleanup
PipelineStages::OUTPUT           // Format and return results
```

## Using Core Stages

### Option 1: Use Constants (Recommended for Core Stages)

```php
use Kargnas\LaravelAiTranslator\Core\PipelineStages;

class MyPlugin extends AbstractMiddlewarePlugin
{
    protected function getStage(): string
    {
        return PipelineStages::VALIDATION;
    }
}
```

### Option 2: Use String Literals (More Flexible)

```php
class MyPlugin extends AbstractMiddlewarePlugin
{
    protected function getStage(): string
    {
        return 'validation';  // Works fine, but no IDE autocomplete
    }
}
```

## Custom Stages

Plugins can define their own stages dynamically:

### Example 1: Simple Custom Stage

```php
class MetricsPlugin extends AbstractTranslationPlugin
{
    public function boot(TranslationPipeline $pipeline): void
    {
        // Register a completely custom stage
        $pipeline->registerStage('metrics_collection', function($context) {
            // Collect metrics
            $context->metadata['metrics'] = [
                'start_time' => microtime(true),
                'text_count' => count($context->texts),
            ];
        });
    }
}
```

### Example 2: Custom Stage with Constants

```php
class NotificationPlugin extends AbstractTranslationPlugin
{
    // Define your own stage constant
    const NOTIFICATION_STAGE = 'notification';
    
    public function boot(TranslationPipeline $pipeline): void
    {
        $pipeline->registerStage(self::NOTIFICATION_STAGE, [$this, 'sendNotifications']);
    }
    
    public function sendNotifications(TranslationContext $context): void
    {
        // Send progress notifications
    }
}
```

### Example 3: Custom Middleware Stage

```php
class CacheMiddleware extends AbstractMiddlewarePlugin
{
    // Custom stage that doesn't exist in core
    protected function getStage(): string
    {
        return 'cache_lookup';  // This stage will be created dynamically
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        // Check cache before proceeding
        if ($cached = $this->getCached($context)) {
            return $cached;
        }
        
        return $next($context);
    }
}
```

## Stage Execution Order

1. **Core stages** execute in the order defined in `PipelineStages::all()`
2. **Custom stages** execute in the order they are registered
3. **Priority** determines order within each stage (higher priority = earlier execution)

### Controlling Execution Order

```php
// Register with priority
$pipeline->registerStage('my_stage', $handler, priority: 100);  // Runs first
$pipeline->registerStage('my_stage', $handler2, priority: 50);  // Runs second

// Custom stages can be inserted between core stages by timing
class MyPlugin extends AbstractTranslationPlugin
{
    public function boot(TranslationPipeline $pipeline): void
    {
        // This will execute when the pipeline runs through all stages
        $pipeline->registerStage('between_prep_and_chunk', $this->handler);
    }
}
```

## Best Practices

### When to Use Core Stage Constants

✅ **DO use constants when:**
- Working with core framework stages
- You want IDE autocomplete and type safety
- You're building plugins that integrate with core functionality

### When to Use Custom Stages

✅ **DO use custom stages when:**
- Your plugin provides unique functionality
- You need stages that don't fit core concepts
- You're building domain-specific extensions
- You want complete control over stage naming

### Naming Conventions

For custom stages, use descriptive names:

```php
// Good custom stage names
'rate_limiting'
'quota_check'
'audit_logging'
'quality_scoring'
'ab_testing'

// Avoid generic names that might conflict
'process'  // Too generic
'handle'   // Too generic
'execute'  // Too generic
```

## Checking Available Stages

```php
// Get all registered stages (core + custom)
$stages = $pipeline->getStages();

// Check if a stage exists
if ($pipeline->hasStage('my_custom_stage')) {
    // Stage is available
}
```

## Migration Guide

If you have existing plugins using string literals:

```php
// Old way (still works!)
public function when(): array
{
    return ['preparation'];  // Still works fine
}

// New way (with constants)
public function when(): array
{
    return [PipelineStages::PREPARATION];  // IDE support + type safety
}

// Custom stages (no change needed)
public function when(): array
{
    return ['my_custom_stage'];  // Perfect for custom stages
}
```

## Summary

The pipeline architecture is designed for maximum flexibility:

1. **Core stages** provide consistency for common operations
2. **Constants** offer IDE support and prevent typos
3. **Dynamic registration** allows unlimited extensibility
4. **String literals** still work for backward compatibility
5. **Custom stages** enable domain-specific workflows

This design ensures that the framework remains extensible while providing helpful constants for common use cases.