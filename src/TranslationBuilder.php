<?php

namespace Kargnas\LaravelAiTranslator;

use Generator;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\TranslationRequest;
use Kargnas\LaravelAiTranslator\Core\TranslationOutput;
use Kargnas\LaravelAiTranslator\Core\PluginManager;
use Kargnas\LaravelAiTranslator\Results\TranslationResult;
use Kargnas\LaravelAiTranslator\Contracts\TranslationPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Provider\StylePlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\MultiProviderPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Provider\GlossaryPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\DiffTrackingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\TokenChunkingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\ValidationPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Middleware\PIIMaskingPlugin;
use Kargnas\LaravelAiTranslator\Plugins\Abstract\AbstractTranslationPlugin;

/**
 * TranslationBuilder - Fluent API for constructing and executing translations
 * 
 * Core Responsibilities:
 * - Provides an intuitive, chainable interface for translation configuration
 * - Manages plugin selection and configuration through method chaining
 * - Handles translation execution with both synchronous and streaming modes
 * - Validates configuration before execution to prevent runtime errors
 * - Integrates with Laravel's service container for dependency injection
 * 
 * Design Pattern:
 * Implements the Builder pattern with a fluent interface, allowing
 * developers to construct complex translation configurations through
 * simple, readable method chains.
 * 
 * Usage Example:
 * ```php
 * $result = TranslationBuilder::make()
 *     ->from('en')->to('ko')
 *     ->withStyle('formal')
 *     ->withProviders(['gpt-4', 'claude'])
 *     ->trackChanges()
 *     ->translate($texts);
 * ```
 * 
 * Plugin Management:
 * The builder automatically loads and configures plugins based on
 * the methods called, hiding the complexity of plugin management
 * from the end user.
 */
class TranslationBuilder
{
    /**
     * @var TranslationPipeline The translation pipeline
     */
    protected TranslationPipeline $pipeline;

    /**
     * @var PluginManager The plugin manager
     */
    protected PluginManager $pluginManager;

    /**
     * @var array Configuration
     */
    protected array $config = [];

    /**
     * @var array<string> Enabled plugins
     */
    protected array $plugins = [];

    /**
     * @var array<string, array> Plugin configurations
     */
    protected array $pluginConfigs = [];

    /**
     * @var callable|null Progress callback
     */
    protected $progressCallback = null;

    /**
     * @var string|null Tenant ID
     */
    protected ?string $tenantId = null;

    /**
     * @var array Request metadata
     */
    protected array $metadata = [];

    /**
     * @var array Request options
     */
    protected array $options = [];

    public function __construct(?TranslationPipeline $pipeline = null, ?PluginManager $pluginManager = null)
    {
        $this->pipeline = $pipeline ?? app(TranslationPipeline::class);
        $this->pluginManager = $pluginManager ?? app(PluginManager::class);
    }

    /**
     * Create a new builder instance.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Set the source locale.
     */
    public function from(string $locale): self
    {
        $this->config['source_locale'] = $locale;
        return $this;
    }

    /**
     * Set the target locale(s).
     */
    public function to(string|array $locales): self
    {
        $this->config['target_locales'] = $locales;
        return $this;
    }

    /**
     * Set translation style.
     */
    public function withStyle(string $style, ?string $customPrompt = null): self
    {
        $this->plugins[] = StylePlugin::class;
        $this->pluginConfigs[StylePlugin::class] = [
            'style' => $style,
            'custom_prompt' => $customPrompt,
        ];
        return $this;
    }

    /**
     * Configure AI providers.
     */
    public function withProviders(array $providers): self
    {
        $this->plugins[] = MultiProviderPlugin::class;
        $this->pluginConfigs[MultiProviderPlugin::class] = [
            'providers' => $providers,
        ];
        return $this;
    }

    /**
     * Set glossary terms.
     */
    public function withGlossary(array $terms): self
    {
        $this->plugins[] = GlossaryPlugin::class;
        $this->pluginConfigs[GlossaryPlugin::class] = [
            'terms' => $terms,
        ];
        return $this;
    }

    /**
     * Enable change tracking.
     */
    public function trackChanges(bool $enable = true): self
    {
        if ($enable) {
            $this->plugins[] = DiffTrackingPlugin::class;
        } else {
            $this->plugins = array_filter($this->plugins, fn($p) => $p !== DiffTrackingPlugin::class);
        }
        return $this;
    }

    /**
     * Set translation context.
     */
    public function withContext(?string $description = null, ?string $screenshot = null): self
    {
        $this->metadata['context'] = [
            'description' => $description,
            'screenshot' => $screenshot,
        ];
        return $this;
    }

    /**
     * Add a custom plugin instance.
     * 
     * Example:
     * $plugin = new MyCustomPlugin(['option' => 'value']);
     * $builder->withPlugin($plugin);
     */
    public function withPlugin(TranslationPlugin $plugin): self
    {
        $this->pluginManager->register($plugin);
        $this->plugins[] = $plugin->getName();
        return $this;
    }
    
    /**
     * Add a plugin by class name with optional config.
     * 
     * Example:
     * $builder->withPluginClass(MyCustomPlugin::class, ['option' => 'value']);
     */
    public function withPluginClass(string $class, array $config = []): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Plugin class {$class} not found");
        }
        
        $plugin = new $class($config);
        
        if (!$plugin instanceof TranslationPlugin) {
            throw new \InvalidArgumentException("Class {$class} must implement TranslationPlugin interface");
        }
        
        return $this->withPlugin($plugin);
    }
    
    /**
     * Add a simple closure-based plugin for quick customization.
     * 
     * Example:
     * $builder->withClosure('my_logger', function($pipeline) {
     *     $pipeline->registerStage('logging', function($context) {
     *         logger()->info('Processing', ['count' => count($context->texts)]);
     *     });
     * });
     */
    public function withClosure(string $name, callable $closure): self
    {
        $plugin = new class($name, $closure) extends AbstractTranslationPlugin {
            private $closure;
            
            public function __construct(string $name, callable $closure)
            {
                parent::__construct();
                $this->name = $name;
                $this->closure = $closure;
            }
            
            public function boot(TranslationPipeline $pipeline): void
            {
                ($this->closure)($pipeline);
            }
        };
        
        return $this->withPlugin($plugin);
    }

    /**
     * Configure token chunking.
     */
    public function withTokenChunking(int $maxTokens = 2000): self
    {
        $this->plugins[] = TokenChunkingPlugin::class;
        $this->pluginConfigs[TokenChunkingPlugin::class] = [
            'max_tokens' => $maxTokens,
        ];
        return $this;
    }

    /**
     * Configure validation checks.
     */
    public function withValidation(array $checks = ['all']): self
    {
        $this->plugins[] = ValidationPlugin::class;
        $this->pluginConfigs[ValidationPlugin::class] = [
            'checks' => $checks,
        ];
        return $this;
    }

    /**
     * Enable PII masking for security.
     */
    public function secure(): self
    {
        $this->plugins[] = PIIMaskingPlugin::class;
        return $this;
    }

    /**
     * Set tenant ID for multi-tenant support.
     */
    public function forTenant(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Set reference locales for context.
     */
    public function withReference(array $referenceLocales): self
    {
        $this->metadata['reference_locales'] = $referenceLocales;
        return $this;
    }

    /**
     * Set additional metadata.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Set progress callback.
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Set a specific option.
     */
    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    /**
     * Set multiple options.
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Execute the translation synchronously
     * 
     * Processes the entire translation pipeline and returns a complete
     * result object. This method blocks until all translations are complete.
     * 
     * @param array $texts Key-value pairs of texts to translate
     * @return TranslationResult Complete translation results with metadata
     * @throws \InvalidArgumentException If configuration is invalid
     * @throws \RuntimeException If translation fails
     */
    public function translate(array $texts): TranslationResult
    {
        // Validate configuration
        $this->validate();

        // Create translation request
        $request = new TranslationRequest(
            $texts,
            $this->config['source_locale'],
            $this->config['target_locales'],
            $this->metadata,
            $this->options,
            $this->tenantId,
            array_unique($this->plugins),
            $this->pluginConfigs
        );

        // Load and configure plugins
        $this->loadPlugins();

        // Boot plugins with pipeline
        $this->pluginManager->boot($this->pipeline);

        // Process translation
        $outputs = [];
        $generator = $this->pipeline->process($request);

        foreach ($generator as $output) {
            if ($output instanceof TranslationOutput) {
                $outputs[] = $output;
                
                // Call progress callback if set
                if ($this->progressCallback) {
                    ($this->progressCallback)($output);
                }
            }
        }

        // Get final context
        $context = $this->pipeline->getContext();

        // Create and return result
        return new TranslationResult(
            $context->translations,
            $context->tokenUsage,
            $request->sourceLocale,
            $request->targetLocales,
            [
                'errors' => $context->errors,
                'warnings' => $context->warnings,
                'duration' => $context->getDuration(),
                'outputs' => $outputs,
                'plugin_data' => $context->pluginData,  // Include plugin data for access to prompts
            ]
        );
    }

    /**
     * Execute translation with streaming output
     * 
     * Returns a generator that yields translation outputs as they become
     * available, enabling real-time UI updates and reduced memory usage
     * for large translation batches.
     * 
     * @param array $texts Key-value pairs of texts to translate
     * @return Generator<TranslationOutput> Stream of translation outputs
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public function stream(array $texts): Generator
    {
        // Validate configuration
        $this->validate();

        // Create translation request
        $request = new TranslationRequest(
            $texts,
            $this->config['source_locale'],
            $this->config['target_locales'],
            $this->metadata,
            $this->options,
            $this->tenantId,
            array_unique($this->plugins),
            $this->pluginConfigs
        );

        // Load and configure plugins
        $this->loadPlugins();

        // Boot plugins with pipeline
        $this->pluginManager->boot($this->pipeline);

        // Process and yield outputs
        yield from $this->pipeline->process($request);
    }

    /**
     * Validate configuration.
     */
    protected function validate(): void
    {
        if (!isset($this->config['source_locale'])) {
            throw new \InvalidArgumentException('Source locale is required');
        }

        if (!isset($this->config['target_locales'])) {
            throw new \InvalidArgumentException('Target locale(s) required');
        }
    }

    /**
     * Load configured plugins.
     */
    protected function loadPlugins(): void
    {
        foreach ($this->plugins as $pluginIdentifier) {
            // Determine if it's a class name or a plugin name
            $pluginName = $pluginIdentifier;
            
            // If it's a class name, get the short name for the plugin identifier
            if (class_exists($pluginIdentifier)) {
                $pluginName = (new \ReflectionClass($pluginIdentifier))->getShortName();
            }
            
            // Skip if already registered
            if ($this->pluginManager->has($pluginName)) {
                // Update configuration if provided
                if (isset($this->pluginConfigs[$pluginIdentifier])) {
                    $plugin = $this->pluginManager->get($pluginName);
                    if ($plugin) {
                        $plugin->configure($this->pluginConfigs[$pluginIdentifier]);
                    }
                }
                continue;
            }

            // Try to create the plugin if it's a class
            $config = $this->pluginConfigs[$pluginIdentifier] ?? [];
            
            if (class_exists($pluginIdentifier)) {
                // Instantiate the plugin directly
                $plugin = new $pluginIdentifier($config);
                if ($plugin instanceof TranslationPlugin) {
                    $this->pluginManager->register($plugin);
                }
            } else {
                // Try to load from registry (backward compatibility)
                $plugin = $this->pluginManager->load($pluginIdentifier, $config);
            }

            if (!$plugin) {
                // Plugin not found, skip
                continue;
            }

            // Enable for tenant if specified
            if ($this->tenantId) {
                $this->pluginManager->enableForTenant($this->tenantId, $pluginName, $config);
            }
        }
    }

    /**
     * Clone the builder.
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->config = [];
        $this->plugins = [];
        $this->pluginConfigs = [];
        $this->progressCallback = null;
        $this->tenantId = null;
        $this->metadata = [];
        $this->options = [];
        
        return $this;
    }

    /**
     * Get the current configuration.
     */
    public function getConfig(): array
    {
        return [
            'config' => $this->config,
            'plugins' => $this->plugins,
            'plugin_configs' => $this->pluginConfigs,
            'tenant_id' => $this->tenantId,
            'metadata' => $this->metadata,
            'options' => $this->options,
        ];
    }
}