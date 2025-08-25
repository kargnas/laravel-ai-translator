<?php

namespace Kargnas\LaravelAiTranslator\Core;

use Kargnas\LaravelAiTranslator\Contracts\TranslationPlugin;

class PluginRegistry
{
    /**
     * @var PluginManager The plugin manager
     */
    protected PluginManager $manager;

    /**
     * @var array<string, mixed> Registry data
     */
    protected array $data = [];

    /**
     * @var array<string, array> Plugin metadata
     */
    protected array $metadata = [];

    public function __construct(PluginManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Register a plugin.
     */
    public function register(TranslationPlugin $plugin): void
    {
        $name = $plugin->getName();
        
        $this->metadata[$name] = [
            'name' => $name,
            'version' => $plugin->getVersion(),
            'priority' => $plugin->getPriority(),
            'dependencies' => $plugin->getDependencies(),
            'class' => get_class($plugin),
            'registered_at' => microtime(true),
        ];
    }

    /**
     * Get plugin metadata.
     */
    public function getMetadata(string $pluginName): ?array
    {
        return $this->metadata[$pluginName] ?? null;
    }

    /**
     * Get all metadata.
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set registry data.
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Get registry data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if registry has data.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Remove registry data.
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Get the plugin manager.
     */
    public function getManager(): PluginManager
    {
        return $this->manager;
    }

    /**
     * Get plugin dependency graph.
     */
    public function getDependencyGraph(): array
    {
        $graph = [];
        
        foreach ($this->metadata as $name => $meta) {
            $graph[$name] = $meta['dependencies'] ?? [];
        }

        return $graph;
    }

    /**
     * Check if all dependencies for a plugin are satisfied.
     */
    public function areDependenciesSatisfied(string $pluginName): bool
    {
        $metadata = $this->getMetadata($pluginName);
        
        if (!$metadata) {
            return false;
        }

        foreach ($metadata['dependencies'] as $dependency) {
            if (!isset($this->metadata[$dependency])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get plugins sorted by priority.
     */
    public function getByPriority(): array
    {
        $sorted = $this->metadata;
        
        uasort($sorted, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return array_keys($sorted);
    }

    /**
     * Get plugin statistics.
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_plugins' => count($this->metadata),
            'average_dependencies' => 0,
            'max_dependencies' => 0,
            'plugins_by_priority' => [],
        ];

        if (count($this->metadata) > 0) {
            $totalDeps = 0;
            $maxDeps = 0;

            foreach ($this->metadata as $meta) {
                $depCount = count($meta['dependencies']);
                $totalDeps += $depCount;
                $maxDeps = max($maxDeps, $depCount);

                $priority = $meta['priority'];
                if (!isset($stats['plugins_by_priority'][$priority])) {
                    $stats['plugins_by_priority'][$priority] = 0;
                }
                $stats['plugins_by_priority'][$priority]++;
            }

            $stats['average_dependencies'] = $totalDeps / count($this->metadata);
            $stats['max_dependencies'] = $maxDeps;
        }

        return $stats;
    }

    /**
     * Export registry data.
     */
    public function export(): array
    {
        return [
            'metadata' => $this->metadata,
            'data' => $this->data,
            'statistics' => $this->getStatistics(),
        ];
    }

    /**
     * Clear the registry.
     */
    public function clear(): void
    {
        $this->metadata = [];
        $this->data = [];
    }
}