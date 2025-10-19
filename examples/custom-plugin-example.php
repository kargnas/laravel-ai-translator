<?php

/**
 * Example: How to create and use custom plugins with Laravel AI Translator
 * 
 * This file demonstrates three ways to add custom functionality:
 * 1. Creating a full plugin class
 * 2. Using a simple closure
 * 3. Extending existing functionality
 */

use Kargnas\LaravelAiTranslator\TranslationBuilder;
use Kargnas\LaravelAiTranslator\Plugins\AbstractTranslationPlugin;
use Kargnas\LaravelAiTranslator\Plugins\AbstractMiddlewarePlugin;
use Kargnas\LaravelAiTranslator\Plugins\AbstractObserverPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// ============================================================================
// Example 1: Full Plugin Class
// ============================================================================

/**
 * Custom plugin that adds logging functionality
 */
class LoggingPlugin extends AbstractObserverPlugin
{
    protected string $name = 'custom_logger';
    
    public function subscribe(): array
    {
        return [
            'translation.started' => 'onTranslationStarted',
            'translation.completed' => 'onTranslationCompleted',
            'stage.translation.started' => 'onTranslationStageStarted',
        ];
    }
    
    public function onTranslationStarted(TranslationContext $context): void
    {
        Log::info('Translation started', [
            'source_locale' => $context->request->sourceLocale,
            'target_locales' => $context->request->targetLocales,
            'text_count' => count($context->texts),
        ]);
    }
    
    public function onTranslationCompleted(TranslationContext $context): void
    {
        Log::info('Translation completed', [
            'duration' => microtime(true) - ($context->metadata['start_time'] ?? 0),
            'translations' => array_sum(array_map('count', $context->translations)),
        ]);
    }
    
    public function onTranslationStageStarted(TranslationContext $context): void
    {
        Log::debug('Translation stage started', [
            'stage' => $context->currentStage,
        ]);
    }
}

/**
 * Custom plugin that adds rate limiting
 */
class RateLimitPlugin extends AbstractMiddlewarePlugin
{
    protected string $name = 'rate_limiter';
    
    protected function getStage(): string
    {
        return 'pre_process'; // Run early in the pipeline
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        $userId = $context->request->tenantId ?? 'default';
        $key = "translation_rate_limit:{$userId}";
        
        // Check rate limit (example: 100 requests per hour)
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= 100) {
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }
        
        Cache::increment($key);
        Cache::put($key, $attempts + 1, 3600); // 1 hour
        
        return $next($context);
    }
}

/**
 * Custom plugin that adds custom metadata
 */
class MetadataEnricherPlugin extends AbstractTranslationPlugin
{
    protected string $name = 'metadata_enricher';
    
    public function boot(TranslationPipeline $pipeline): void
    {
        // Add custom stage for metadata enrichment
        $pipeline->registerStage('enrich_metadata', function($context) {
            $context->metadata['processed_at'] = now()->toIso8601String();
            $context->metadata['server'] = gethostname();
            $context->metadata['php_version'] = PHP_VERSION;
            $context->metadata['app_version'] = config('app.version', '1.0.0');
            
            // Add word count statistics
            $wordCount = 0;
            foreach ($context->texts as $text) {
                $wordCount += str_word_count($text);
            }
            $context->metadata['total_words'] = $wordCount;
        });
    }
}

// ============================================================================
// Example 2: Using the plugins
// ============================================================================

// Method 1: Using a full plugin class
$translator = TranslationBuilder::make()
    ->from('en')
    ->to(['ko', 'ja'])
    ->withPlugin(new LoggingPlugin())
    ->withPlugin(new RateLimitPlugin(['max_requests' => 100]))
    ->withPlugin(new MetadataEnricherPlugin());

// Method 2: Using withPluginClass for simpler registration
$translator = TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withPluginClass(LoggingPlugin::class)
    ->withPluginClass(RateLimitPlugin::class, ['max_requests' => 50]);

// Method 3: Using closure for simple functionality
$translator = TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withClosure('simple_logger', function($pipeline) {
        // Register a simple logging stage
        $pipeline->registerStage('log_start', function($context) {
            logger()->info('Starting translation of ' . count($context->texts) . ' texts');
        });
    })
    ->withClosure('add_timestamp', function($pipeline) {
        // Add timestamp to metadata
        $pipeline->on('translation.started', function($context) {
            $context->metadata['timestamp'] = time();
        });
    });

// ============================================================================
// Example 3: Using CustomStageExamplePlugin (from src/Plugins)
// ============================================================================

use Kargnas\LaravelAiTranslator\Plugins\CustomStageExamplePlugin;

// This plugin adds a custom 'custom_processing' stage to the pipeline
$translator = TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withPlugin(new CustomStageExamplePlugin());

// The custom stage will automatically be executed in the pipeline
// and you can see logs for 'custom_processing' stage events

// ============================================================================
// Example 4: Advanced - Custom Provider Plugin
// ============================================================================

use Kargnas\LaravelAiTranslator\Plugins\AbstractProviderPlugin;

/**
 * Custom translation provider that uses a different API
 */
class CustomApiProvider extends AbstractProviderPlugin
{
    protected string $name = 'custom_api_provider';
    
    public function provides(): array
    {
        return ['custom_translation'];
    }
    
    public function when(): array
    {
        return ['translation']; // Active during translation stage
    }
    
    public function execute(TranslationContext $context): mixed
    {
        // Your custom API logic here
        $apiKey = $this->getConfigValue('api_key');
        $endpoint = $this->getConfigValue('endpoint', 'https://api.example.com/translate');
        
        // Make API call
        $response = Http::post($endpoint, [
            'api_key' => $apiKey,
            'source' => $context->request->sourceLocale,
            'target' => $context->request->targetLocales,
            'texts' => $context->texts,
        ]);
        
        // Process response
        $translations = $response->json('translations');
        
        // Add to context
        foreach ($translations as $locale => $items) {
            foreach ($items as $key => $translation) {
                $context->addTranslation($locale, $key, $translation);
            }
        }
        
        return $translations;
    }
}

// Using the custom provider
$translator = TranslationBuilder::make()
    ->from('en')
    ->to('ko')
    ->withPlugin(new CustomApiProvider([
        'api_key' => env('CUSTOM_API_KEY'),
        'endpoint' => 'https://my-translation-api.com/v1/translate',
    ]));

// ============================================================================
// Example 5: Combining multiple plugins for complex workflow
// ============================================================================

$translator = TranslationBuilder::make()
    ->from('en')
    ->to(['ko', 'ja', 'zh'])
    // Core functionality
    ->trackChanges()  // Enable diff tracking
    ->withTokenChunking(2000)  // Chunk large texts
    ->withValidation(['html', 'variables'])  // Validate translations
    
    // Custom plugins
    ->withPlugin(new LoggingPlugin())
    ->withPlugin(new RateLimitPlugin())
    ->withPlugin(new MetadataEnricherPlugin())
    
    // Quick customizations with closures
    ->withClosure('performance_timer', function($pipeline) {
        $startTime = null;
        
        $pipeline->on('translation.started', function() use (&$startTime) {
            $startTime = microtime(true);
        });
        
        $pipeline->on('translation.completed', function() use (&$startTime) {
            $duration = microtime(true) - $startTime;
            logger()->info("Translation took {$duration} seconds");
        });
    })
    ->withClosure('error_notifier', function($pipeline) {
        $pipeline->on('translation.failed', function($context) {
            // Send notification on failure
            // Example: Mail::to('admin@example.com')->send(new TranslationFailedMail($context));
            logger()->error('Translation failed', ['error' => $context->errors ?? []]);
        });
    });

// Execute translation
$texts = [
    'welcome' => 'Welcome to our application',
    'goodbye' => 'Thank you for using our service',
];

$result = $translator->translate($texts);

// Access results
foreach ($result->getTranslations() as $locale => $translations) {
    echo "Translations for {$locale}:\n";
    foreach ($translations as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
}

// Access metadata
$metadata = $result->getMetadata();
echo "Total words processed: " . ($metadata['total_words'] ?? 0) . "\n";
echo "Processing time: " . ($metadata['duration'] ?? 0) . " seconds\n";