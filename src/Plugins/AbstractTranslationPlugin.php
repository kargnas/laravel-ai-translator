<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Contracts\TranslationPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PluginRegistry;

abstract class AbstractTranslationPlugin implements TranslationPlugin
{
    /**
     * @var array Plugin configuration
     */
    protected array $config = [];

    /**
     * @var array Registered hooks
     */
    protected array $hooks = [];

    /**
     * @var string Plugin name
     */
    protected string $name;

    /**
     * @var string Plugin version
     */
    protected string $version = '1.0.0';

    /**
     * @var int Plugin priority
     */
    protected int $priority = 0;

    /**
     * @var array Plugin dependencies
     */
    protected array $dependencies = [];

    /**
     * @var array<string, bool> Tenant enablement status
     */
    protected array $tenantStatus = [];

    /**
     * @var array<string, array> Tenant-specific configurations
     */
    protected array $tenantConfigs = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->name = $this->name ?? static::class;
    }

    /**
     * Get default configuration.
     */
    protected function getDefaultConfig(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * {@inheritDoc}
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * {@inheritDoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritDoc}
     */
    public function register(PluginRegistry $registry): void
    {
        $registry->register($this);
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabledFor(?string $tenant = null): bool
    {
        if ($tenant === null) {
            return true; // Enabled for all by default
        }

        return $this->tenantStatus[$tenant] ?? true;
    }

    /**
     * {@inheritDoc}
     */
    public function enableForTenant(string $tenant, array $config = []): void
    {
        $this->tenantStatus[$tenant] = true;
        if (!empty($config)) {
            $this->tenantConfigs[$tenant] = $config;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function disableForTenant(string $tenant): void
    {
        $this->tenantStatus[$tenant] = false;
        unset($this->tenantConfigs[$tenant]);
    }

    /**
     * {@inheritDoc}
     */
    public function configure(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get a specific configuration value.
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Register a hook for a specific stage.
     */
    protected function hook(string $stage, callable $handler, int $priority = 0): void
    {
        if (!isset($this->hooks[$stage])) {
            $this->hooks[$stage] = [];
        }

        $this->hooks[$stage][] = [
            'handler' => $handler,
            'priority' => $priority,
        ];
    }

    /**
     * Log a message (delegates to Laravel's logger).
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('logger')) {
            logger()->log($level, "[{$this->getName()}] {$message}", $context);
        }
    }

    /**
     * Log debug message.
     */
    protected function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log info message.
     */
    protected function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log warning message.
     */
    protected function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log error message.
     */
    protected function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
}