<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PluginRegistry;

interface TranslationPlugin
{
    /**
     * Get the plugin name.
     */
    public function getName(): string;

    /**
     * Get the plugin version.
     */
    public function getVersion(): string;

    /**
     * Get plugin dependencies.
     * 
     * @return array<string> Array of plugin names this plugin depends on
     */
    public function getDependencies(): array;

    /**
     * Get plugin priority (higher = earlier execution).
     */
    public function getPriority(): int;

    /**
     * Boot the plugin with the pipeline.
     */
    public function boot(TranslationPipeline $pipeline): void;

    /**
     * Register the plugin with the registry.
     */
    public function register(PluginRegistry $registry): void;

    /**
     * Check if plugin is enabled for a specific tenant.
     */
    public function isEnabledFor(?string $tenant = null): bool;

    /**
     * Configure the plugin with options.
     */
    public function configure(array $config): self;

    /**
     * Get plugin configuration.
     */
    public function getConfig(): array;

    /**
     * Enable plugin for a specific tenant with optional configuration.
     */
    public function enableForTenant(string $tenant, array $config = []): void;

    /**
     * Disable plugin for a specific tenant.
     */
    public function disableForTenant(string $tenant): void;
}