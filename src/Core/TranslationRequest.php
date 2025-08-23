<?php

namespace Kargnas\LaravelAiTranslator\Core;

class TranslationRequest
{
    /**
     * @var array<string, string> Texts to translate (key => text)
     */
    public array $texts;

    /**
     * @var string Source locale
     */
    public string $sourceLocale;

    /**
     * @var string|array Target locale(s)
     */
    public string|array $targetLocales;

    /**
     * @var array<string, mixed> Request metadata
     */
    public array $metadata;

    /**
     * @var array<string, mixed> Request options
     */
    public array $options;

    /**
     * @var string|null Tenant ID for multi-tenant support
     */
    public ?string $tenantId;

    /**
     * @var array<string> Enabled plugins for this request
     */
    public array $plugins;

    /**
     * @var array<string, array> Plugin configurations
     */
    public array $pluginConfigs;

    public function __construct(
        array $texts,
        string $sourceLocale,
        string|array $targetLocales,
        array $metadata = [],
        array $options = [],
        ?string $tenantId = null,
        array $plugins = [],
        array $pluginConfigs = []
    ) {
        $this->texts = $texts;
        $this->sourceLocale = $sourceLocale;
        $this->targetLocales = $targetLocales;
        $this->metadata = $metadata;
        $this->options = $options;
        $this->tenantId = $tenantId;
        $this->plugins = $plugins;
        $this->pluginConfigs = $pluginConfigs;
    }

    /**
     * Get texts to translate.
     */
    public function getTexts(): array
    {
        return $this->texts;
    }

    /**
     * Get source language.
     */
    public function getSourceLanguage(): string
    {
        return $this->sourceLocale;
    }

    /**
     * Get first target language.
     */
    public function getTargetLanguage(): string
    {
        $locales = $this->getTargetLocales();
        return $locales[0] ?? '';
    }

    /**
     * Get target locales as array.
     */
    public function getTargetLocales(): array
    {
        return is_array($this->targetLocales) ? $this->targetLocales : [$this->targetLocales];
    }

    /**
     * Check if a specific plugin is enabled.
     */
    public function hasPlugin(string $pluginName): bool
    {
        return in_array($pluginName, $this->plugins, true);
    }

    /**
     * Get configuration for a specific plugin.
     */
    public function getPluginConfig(string $pluginName): array
    {
        return $this->pluginConfigs[$pluginName] ?? [];
    }

    /**
     * Get an option value.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set an option value.
     */
    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    /**
     * Get metadata value.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set metadata value.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Create a request for a single locale from a multi-locale request.
     */
    public function forLocale(string $locale): self
    {
        return new self(
            $this->texts,
            $this->sourceLocale,
            $locale,
            $this->metadata,
            $this->options,
            $this->tenantId,
            $this->plugins,
            $this->pluginConfigs
        );
    }

    /**
     * Get total number of texts to translate.
     */
    public function count(): int
    {
        return count($this->texts);
    }

    /**
     * Check if request has texts to translate.
     */
    public function isEmpty(): bool
    {
        return empty($this->texts);
    }
}