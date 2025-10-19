<?php

namespace Kargnas\LaravelAiTranslator;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Kargnas\LaravelAiTranslator\Console\CleanCommand;
use Kargnas\LaravelAiTranslator\Console\FindUnusedTranslations;
use Kargnas\LaravelAiTranslator\Console\TestTranslateCommand;
use Kargnas\LaravelAiTranslator\Console\TranslateCrowdin;
use Kargnas\LaravelAiTranslator\Console\TranslateCrowdinParallel;
use Kargnas\LaravelAiTranslator\Console\TranslateFileCommand;
use Kargnas\LaravelAiTranslator\Console\TranslateJson;
use Kargnas\LaravelAiTranslator\Console\TranslateStrings;
use Kargnas\LaravelAiTranslator\Console\TranslateStringsParallel;
use Kargnas\LaravelAiTranslator\Core\PluginManager;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Plugins;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/ai-translator.php' => config_path('ai-translator.php'),
        ], 'ai-translator-config');
        
        // Publish plugin documentation
        if (file_exists(__DIR__ . '/../docs/plugins.md')) {
            $this->publishes([
                __DIR__ . '/../docs/plugins.md' => base_path('docs/ai-translator-plugins.md'),
            ], 'ai-translator-docs');
        }
        
        // Publish examples
        if (is_dir(__DIR__ . '/../examples/')) {
            $this->publishes([
                __DIR__ . '/../examples/' => base_path('examples/ai-translator/'),
            ], 'ai-translator-examples');
        }
        
        // Register custom plugins from app
        $this->registerCustomPlugins();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-translator.php',
            'ai-translator',
        );
        
        // Register core services as singletons
        $this->app->singleton(PluginManager::class, function ($app) {
            $manager = new PluginManager();
            
            // Register default plugins
            $this->registerDefaultPlugins($manager);
            
            // Load plugins from config
            if ($plugins = config('ai-translator.plugins.enabled', [])) {
                $this->loadConfiguredPlugins($manager, $plugins);
            }
            
            return $manager;
        });
        
        $this->app->singleton(TranslationPipeline::class, function ($app) {
            return new TranslationPipeline($app->make(PluginManager::class));
        });
        
        // Register TranslationBuilder
        $this->app->bind(TranslationBuilder::class, function ($app) {
            return new TranslationBuilder(
                $app->make(TranslationPipeline::class),
                $app->make(PluginManager::class)
            );
        });

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
    }
    
    /**
     * Register default plugins with the manager.
     */
    protected function registerDefaultPlugins(PluginManager $manager): void
    {
        // Core plugins with their default configurations
        $defaultPlugins = [
            'StylePlugin' => Plugins\Provider\StylePlugin::class,
            'GlossaryPlugin' => Plugins\Provider\GlossaryPlugin::class,
            'DiffTrackingPlugin' => Plugins\Middleware\DiffTrackingPlugin::class,
            'TokenChunkingPlugin' => Plugins\Middleware\TokenChunkingPlugin::class,
            'ValidationPlugin' => Plugins\Middleware\ValidationPlugin::class,
            'PIIMaskingPlugin' => Plugins\Middleware\PIIMaskingPlugin::class,
            'StreamingOutputPlugin' => Plugins\Observer\StreamingOutputPlugin::class,
            'MultiProviderPlugin' => Plugins\Middleware\MultiProviderPlugin::class,
            'AnnotationContextPlugin' => Plugins\Observer\AnnotationContextPlugin::class,
        ];
        
        foreach ($defaultPlugins as $name => $class) {
            if (class_exists($class)) {
                $defaultConfig = config("ai-translator.plugins.config.{$name}", []);
                $manager->registerClass($name, $class, $defaultConfig);
            }
        }
    }
    
    /**
     * Load plugins based on configuration.
     */
    protected function loadConfiguredPlugins(PluginManager $manager, array $plugins): void
    {
        foreach ($plugins as $name => $enabled) {
            if ($enabled === true || (is_array($enabled) && ($enabled['enabled'] ?? false))) {
                $config = is_array($enabled) ? ($enabled['config'] ?? []) : [];
                
                // Try to load the plugin
                try {
                    $manager->load($name, $config);
                } catch (\Exception $e) {
                    // Log error but don't fail boot
                    if (config('app.debug')) {
                        logger()->error("Failed to load plugin '{$name}'", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
    
    /**
     * Register custom plugins from the application.
     */
    protected function registerCustomPlugins(): void
    {
        // Check for custom plugin directory
        $customPluginPath = app_path('Plugins/Translation');
        
        if (!is_dir($customPluginPath)) {
            return;
        }
        
        $manager = $this->app->make(PluginManager::class);
        
        // Scan for plugin files
        $files = glob($customPluginPath . '/*Plugin.php');
        
        foreach ($files as $file) {
            $className = 'App\\Plugins\\Translation\\' . basename($file, '.php');
            
            if (class_exists($className)) {
                try {
                    $reflection = new \ReflectionClass($className);
                    
                    // Check if it's a valid plugin
                    if ($reflection->isSubclassOf(Contracts\TranslationPlugin::class) && 
                        !$reflection->isAbstract()) {
                        
                        // Get plugin name from class
                        $pluginName = $reflection->getShortName();
                        
                        // Register with manager
                        $manager->registerClass($pluginName, $className);
                        
                        // Auto-load if configured
                        if (config("ai-translator.plugins.custom.{$pluginName}.enabled", false)) {
                            $config = config("ai-translator.plugins.custom.{$pluginName}.config", []);
                            $manager->load($pluginName, $config);
                        }
                    }
                } catch (\Exception $e) {
                    if (config('app.debug')) {
                        logger()->error("Failed to register custom plugin '{$className}'", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }
    
    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            PluginManager::class,
            TranslationPipeline::class,
            TranslationBuilder::class,
        ];
    }
}
