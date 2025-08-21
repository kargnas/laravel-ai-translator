<?php

namespace Kargnas\LaravelAiTranslator\Core;

use Generator;
use Closure;
use Kargnas\LaravelAiTranslator\Contracts\MiddlewarePlugin;
use Kargnas\LaravelAiTranslator\Contracts\ProviderPlugin;
use Kargnas\LaravelAiTranslator\Contracts\ObserverPlugin;
use Kargnas\LaravelAiTranslator\Contracts\TranslationPlugin;
use Illuminate\Support\Collection;

/**
 * TranslationPipeline - Core execution engine for the translation process
 * 
 * Primary Responsibilities:
 * - Orchestrates the entire translation workflow through defined stages
 * - Manages plugin lifecycle and execution order based on priorities
 * - Implements middleware chain pattern for request/response transformation
 * - Provides service registry for plugin-provided capabilities
 * - Handles event emission and listener management throughout the pipeline
 * - Supports streaming output via PHP Generators for memory efficiency
 * - Ensures proper error handling and cleanup via termination handlers
 * 
 * Architecture Pattern:
 * The pipeline follows a multi-stage processing model where each stage
 * can have multiple handlers. Plugins can register handlers for specific
 * stages, and the pipeline ensures they execute in the correct order.
 * 
 * Execution Flow:
 * 1. Pre-process -> 2. Diff Detection -> 3. Preparation -> 4. Chunking
 * 5. Translation -> 6. Consensus -> 7. Validation -> 8. Post-process -> 9. Output
 * 
 * Plugin Integration:
 * - Middleware: Wraps the entire pipeline for transformation
 * - Providers: Supply services at specific stages
 * - Observers: React to events without modifying data flow
 */
class TranslationPipeline
{
    /**
     * @var array<string, array> Pipeline stages and their handlers
     */
    protected array $stages = [];
    
    /**
     * @var array<string, array> Stage-specific middleware
     */
    protected array $stageMiddlewares = [];

    /**
     * @var array<MiddlewarePlugin> Registered middleware plugins
     */
    protected array $middlewares = [];

    /**
     * @var array<string, ProviderPlugin> Registered provider plugins
     */
    protected array $providers = [];

    /**
     * @var array<ObserverPlugin> Registered observer plugins
     */
    protected array $observers = [];

    /**
     * @var array<string, callable> Registered services
     */
    protected array $services = [];

    /**
     * @var array<callable> Termination handlers
     */
    protected array $terminators = [];

    /**
     * @var array<string, array<callable>> Event listeners
     */
    protected array $eventListeners = [];

    /**
     * @var PluginManager Plugin manager instance
     */
    protected PluginManager $pluginManager;

    /**
     * @var TranslationContext Current translation context
     */
    protected ?TranslationContext $context = null;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
        
        // Initialize stages using constants
        foreach (PipelineStages::all() as $stage) {
            $this->stages[$stage] = [];
            $this->stageMiddlewares[$stage] = [];
        }
    }

    /**
     * Register a plugin with the pipeline.
     */
    public function registerPlugin(TranslationPlugin $plugin): void
    {
        // Detect plugin type and register appropriately
        if ($plugin instanceof MiddlewarePlugin) {
            $this->middlewares[] = $plugin;
        }

        if ($plugin instanceof ProviderPlugin) {
            foreach ($plugin->provides() as $service) {
                $this->providers[$service] = $plugin;
            }
        }

        if ($plugin instanceof ObserverPlugin) {
            $this->observers[] = $plugin;
        }

        // Boot the plugin
        $plugin->boot($this);
    }

    /**
     * Register a handler for a specific stage.
     */
    public function registerStage(string $stage, callable $handler, int $priority = 0): void
    {
        if (!isset($this->stages[$stage])) {
            $this->stages[$stage] = [];
        }

        $this->stages[$stage][] = [
            'handler' => $handler,
            'priority' => $priority,
        ];

        // Sort by priority (higher priority first)
        usort($this->stages[$stage], fn($a, $b) => $b['priority'] <=> $a['priority']);
    }
    
    /**
     * Register middleware for a specific stage.
     */
    public function registerMiddleware(string $stage, callable $middleware, int $priority = 0): void
    {
        if (!isset($this->stageMiddlewares[$stage])) {
            $this->stageMiddlewares[$stage] = [];
        }

        $this->stageMiddlewares[$stage][] = [
            'handler' => $middleware,
            'priority' => $priority,
        ];

        // Sort by priority (higher priority first)
        usort($this->stageMiddlewares[$stage], fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register a service.
     */
    public function registerService(string $name, callable $service): void
    {
        $this->services[$name] = $service;
    }

    /**
     * Register a termination handler.
     */
    public function registerTerminator(callable $terminator, int $priority = 0): void
    {
        $this->terminators[] = [
            'handler' => $terminator,
            'priority' => $priority,
        ];

        // Sort by priority
        usort($this->terminators, fn($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register an event listener.
     */
    public function on(string $event, callable $listener): void
    {
        if (!isset($this->eventListeners[$event])) {
            $this->eventListeners[$event] = [];
        }

        $this->eventListeners[$event][] = $listener;
    }

    /**
     * Emit an event.
     */
    public function emit(string $event, TranslationContext $context): void
    {
        if (isset($this->eventListeners[$event])) {
            foreach ($this->eventListeners[$event] as $listener) {
                $listener($context);
            }
        }
    }

    /**
     * Process a translation request through the pipeline.
     * 
     * @return Generator<TranslationOutput>
     */
    public function process(TranslationRequest $request): Generator
    {
        $this->context = new TranslationContext($request);

        try {
            // Emit translation started event
            $this->emit('translation.started', $this->context);

            // Execute middleware chain
            yield from $this->executeMiddlewares($this->context);

            // Mark as complete
            $this->context->complete();

            // Emit translation completed event
            $this->emit('translation.completed', $this->context);

        } catch (\Throwable $e) {
            $this->context->addError($e->getMessage());
            $this->emit('translation.failed', $this->context);
            throw $e;
        } finally {
            // Execute terminators
            $this->executeTerminators($this->context);
        }
    }

    /**
     * Execute middleware chain.
     * 
     * @return Generator<TranslationOutput>
     */
    protected function executeMiddlewares(TranslationContext $context): Generator
    {
        // Build middleware pipeline
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function ($next, $middleware) {
                return function ($context) use ($middleware, $next) {
                    return $middleware->handle($context, $next);
                };
            },
            function ($context) {
                // Core translation logic
                return $this->executeStages($context);
            }
        );

        // Execute pipeline and yield results
        $result = $pipeline($context);
        
        if ($result instanceof Generator) {
            yield from $result;
        } elseif (is_iterable($result)) {
            foreach ($result as $output) {
                yield $output;
            }
        }
    }

    /**
     * Execute pipeline stages.
     * 
     * @return Generator<TranslationOutput>
     */
    protected function executeStages(TranslationContext $context): Generator
    {
        foreach ($this->stages as $stage => $handlers) {
            $context->currentStage = $stage;
            $this->emit("stage.{$stage}.started", $context);

            // Build middleware chain for this stage
            $stageExecution = function($context) use ($stage, $handlers) {
                $results = [];
                foreach ($handlers as $handlerData) {
                    $handler = $handlerData['handler'];
                    $result = $handler($context);
                    
                    if ($result !== null) {
                        $results[] = $result;
                    }
                }
                return $results;
            };
            
            // Wrap with stage-specific middleware
            if (isset($this->stageMiddlewares[$stage]) && !empty($this->stageMiddlewares[$stage])) {
                $pipeline = array_reduce(
                    array_reverse($this->stageMiddlewares[$stage]),
                    function ($next, $middlewareData) {
                        $middleware = $middlewareData['handler'];
                        return function ($context) use ($middleware, $next) {
                            return $middleware($context, $next);
                        };
                    },
                    $stageExecution
                );
                $results = $pipeline($context);
            } else {
                $results = $stageExecution($context);
            }

            // Yield results
            foreach ($results as $result) {
                if ($result instanceof Generator) {
                    yield from $result;
                } elseif ($result instanceof TranslationOutput) {
                    yield $result;
                } elseif (is_array($result)) {
                    foreach ($result as $output) {
                        if ($output instanceof TranslationOutput) {
                            yield $output;
                        }
                    }
                }
            }

            $this->emit("stage.{$stage}.completed", $context);
        }
    }

    /**
     * Execute provider for a service.
     */
    public function executeService(string $service, TranslationContext $context): mixed
    {
        if (isset($this->services[$service])) {
            return ($this->services[$service])($context);
        }

        if (isset($this->providers[$service])) {
            return $this->providers[$service]->execute($context);
        }

        throw new \RuntimeException("Service '{$service}' not found");
    }

    /**
     * Execute termination handlers.
     */
    protected function executeTerminators(TranslationContext $context): void
    {
        $response = $context->snapshot();

        foreach ($this->terminators as $terminatorData) {
            $terminator = $terminatorData['handler'];
            $terminator($context, $response);
        }
    }

    /**
     * Get available stages.
     */
    public function getStages(): array
    {
        return array_keys($this->stages);
    }

    /**
     * Get registered services.
     */
    public function getServices(): array
    {
        return array_keys($this->services);
    }

    /**
     * Get current context.
     */
    public function getContext(): ?TranslationContext
    {
        return $this->context;
    }

    /**
     * Check if a service is available.
     */
    public function hasService(string $service): bool
    {
        return isset($this->services[$service]) || isset($this->providers[$service]);
    }

    /**
     * Get stage handlers.
     */
    public function getStageHandlers(string $stage): array
    {
        return $this->stages[$stage] ?? [];
    }

    /**
     * Clear all registered plugins and handlers.
     */
    public function clear(): void
    {
        $this->middlewares = [];
        $this->providers = [];
        $this->observers = [];
        $this->services = [];
        $this->terminators = [];
        $this->eventListeners = [];
        
        foreach (array_keys($this->stages) as $stage) {
            $this->stages[$stage] = [];
        }
    }
}