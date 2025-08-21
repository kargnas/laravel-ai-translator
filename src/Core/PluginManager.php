<?php

namespace Kargnas\LaravelAiTranslator\Core;

use Kargnas\LaravelAiTranslator\Contracts\TranslationPlugin;
use Illuminate\Support\Collection;

class PluginManager
{
    /**
     * @var array<string, TranslationPlugin> Registered plugins
     */
    protected array $plugins = [];

    /**
     * @var array<string, array> Tenant-specific plugin configurations
     */
    protected array $tenantPlugins = [];

    /**
     * @var array<string, string> Plugin class mappings
     */
    protected array $pluginClasses = [];

    /**
     * @var array<string, array> Plugin default configurations
     */
    protected array $defaultConfigs = [];

    /**
     * @var bool Whether plugins are booted
     */
    protected bool $booted = false;

    /**
     * Register a plugin.
     */
    public function register(TranslationPlugin $plugin): void
    {
        $name = $plugin->getName();
        
        // Check dependencies
        $this->checkDependencies($plugin);
        
        $this->plugins[$name] = $plugin;
        
        // Register with registry
        $plugin->register($this->getRegistry());
    }

    /**
     * Register a plugin class.
     */
    public function registerClass(string $name, string $class, array $defaultConfig = []): void
    {
        $this->pluginClasses[$name] = $class;
        $this->defaultConfigs[$name] = $defaultConfig;
    }

    /**
     * Enable a plugin for a specific tenant.
     */
    public function enableForTenant(string $tenant, string $pluginName, array $config = []): void
    {
        if (!isset($this->tenantPlugins[$tenant])) {
            $this->tenantPlugins[$tenant] = [];
        }

        $this->tenantPlugins[$tenant][$pluginName] = [
            'enabled' => true,
            'config' => $config,
        ];

        // Update plugin if already registered
        if (isset($this->plugins[$pluginName])) {
            $this->plugins[$pluginName]->enableForTenant($tenant);
            if (!empty($config)) {
                $this->plugins[$pluginName]->configure($config);
            }
        }
    }

    /**
     * Disable a plugin for a specific tenant.
     */
    public function disableForTenant(string $tenant, string $pluginName): void
    {
        if (!isset($this->tenantPlugins[$tenant])) {
            $this->tenantPlugins[$tenant] = [];
        }

        $this->tenantPlugins[$tenant][$pluginName] = [
            'enabled' => false,
            'config' => [],
        ];

        // Update plugin if already registered
        if (isset($this->plugins[$pluginName])) {
            $this->plugins[$pluginName]->disableForTenant($tenant);
        }
    }

    /**
     * Get enabled plugins for a tenant.
     */
    public function getEnabled(?string $tenant = null): array
    {
        if ($tenant === null) {
            return $this->plugins;
        }

        $enabledPlugins = [];
        
        foreach ($this->plugins as $name => $plugin) {
            if ($this->isEnabledForTenant($tenant, $name)) {
                $enabledPlugins[$name] = $plugin;
            }
        }

        return $enabledPlugins;
    }

    /**
     * Check if a plugin is enabled for a tenant.
     */
    public function isEnabledForTenant(string $tenant, string $pluginName): bool
    {
        // Check tenant-specific configuration
        if (isset($this->tenantPlugins[$tenant][$pluginName])) {
            return $this->tenantPlugins[$tenant][$pluginName]['enabled'] ?? false;
        }

        // Check plugin's own tenant status
        if (isset($this->plugins[$pluginName])) {
            return $this->plugins[$pluginName]->isEnabledFor($tenant);
        }

        return false;
    }

    /**
     * Get a specific plugin.
     */
    public function get(string $name): ?TranslationPlugin
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Check if a plugin is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Get all registered plugins.
     */
    public function all(): array
    {
        return $this->plugins;
    }

    /**
     * Create a plugin instance from class name.
     */
    public function create(string $name, array $config = []): ?TranslationPlugin
    {
        if (!isset($this->pluginClasses[$name])) {
            return null;
        }

        $class = $this->pluginClasses[$name];
        $defaultConfig = $this->defaultConfigs[$name] ?? [];
        $mergedConfig = array_merge($defaultConfig, $config);

        if (!class_exists($class)) {
            throw new \RuntimeException("Plugin class '{$class}' not found");
        }

        return new $class($mergedConfig);
    }

    /**
     * Load and register a plugin by name.
     */
    public function load(string $name, array $config = []): ?TranslationPlugin
    {
        if ($this->has($name)) {
            return $this->get($name);
        }

        $plugin = $this->create($name, $config);
        
        if ($plugin) {
            $this->register($plugin);
        }

        return $plugin;
    }

    /**
     * Load plugins from configuration.
     */
    public function loadFromConfig(array $config): void
    {
        foreach ($config as $name => $pluginConfig) {
            if (is_string($pluginConfig)) {
                // Simple class name
                $this->registerClass($name, $pluginConfig);
            } elseif (is_array($pluginConfig)) {
                // Class with configuration
                $class = $pluginConfig['class'] ?? null;
                $defaultConfig = $pluginConfig['config'] ?? [];
                
                if ($class) {
                    $this->registerClass($name, $class, $defaultConfig);
                }

                // Auto-load if enabled
                if ($pluginConfig['enabled'] ?? false) {
                    $this->load($name);
                }
            }
        }
    }

    /**
     * Boot all registered plugins with a pipeline.
     */
    public function boot(TranslationPipeline $pipeline): void
    {
        if ($this->booted) {
            return;
        }

        // Sort plugins by priority and dependencies
        $sorted = $this->sortByDependencies($this->plugins);

        foreach ($sorted as $plugin) {
            $pipeline->registerPlugin($plugin);
        }

        $this->booted = true;
    }

    /**
     * Check plugin dependencies.
     */
    protected function checkDependencies(TranslationPlugin $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency) {
            if (!$this->has($dependency)) {
                throw new \RuntimeException(
                    "Plugin '{$plugin->getName()}' requires '{$dependency}' which is not registered"
                );
            }
        }
    }

    /**
     * Sort plugins by dependencies.
     */
    protected function sortByDependencies(array $plugins): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach ($plugins as $name => $plugin) {
            if (!isset($visited[$name])) {
                $this->visitPlugin($name, $plugins, $visited, $visiting, $sorted);
            }
        }

        return $sorted;
    }

    /**
     * Visit plugin for dependency sorting (DFS).
     */
    protected function visitPlugin(
        string $name,
        array $plugins,
        array &$visited,
        array &$visiting,
        array &$sorted
    ): void {
        if (isset($visiting[$name])) {
            throw new \RuntimeException("Circular dependency detected for plugin '{$name}'");
        }

        if (isset($visited[$name])) {
            return;
        }

        $visiting[$name] = true;
        $plugin = $plugins[$name];

        // Visit dependencies first
        foreach ($plugin->getDependencies() as $dependency) {
            if (isset($plugins[$dependency])) {
                $this->visitPlugin($dependency, $plugins, $visited, $visiting, $sorted);
            }
        }

        $visited[$name] = true;
        unset($visiting[$name]);
        $sorted[] = $plugin;
    }

    /**
     * Get the plugin registry.
     */
    protected function getRegistry(): PluginRegistry
    {
        return new PluginRegistry($this);
    }

    /**
     * Reset the manager.
     */
    public function reset(): void
    {
        $this->plugins = [];
        $this->tenantPlugins = [];
        $this->booted = false;
    }

    /**
     * Get plugin statistics.
     */
    public function getStats(): array
    {
        return [
            'total' => count($this->plugins),
            'registered_classes' => count($this->pluginClasses),
            'tenants' => count($this->tenantPlugins),
            'booted' => $this->booted,
        ];
    }
}