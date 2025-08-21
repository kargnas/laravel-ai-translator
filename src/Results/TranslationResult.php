<?php

namespace Kargnas\LaravelAiTranslator\Results;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

class TranslationResult implements Arrayable, Jsonable
{
    /**
     * @var array<string, array<string, string>> Translations by locale
     */
    protected array $translations;

    /**
     * @var array Token usage statistics
     */
    protected array $tokenUsage;

    /**
     * @var string Source locale
     */
    protected string $sourceLocale;

    /**
     * @var string|array Target locale(s)
     */
    protected string|array $targetLocales;

    /**
     * @var array Additional metadata
     */
    protected array $metadata;

    public function __construct(
        array $translations,
        array $tokenUsage,
        string $sourceLocale,
        string|array $targetLocales,
        array $metadata = []
    ) {
        $this->translations = $translations;
        $this->tokenUsage = $tokenUsage;
        $this->sourceLocale = $sourceLocale;
        $this->targetLocales = $targetLocales;
        $this->metadata = $metadata;
    }

    /**
     * Get all translations.
     */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /**
     * Get translations for a specific locale.
     */
    public function getTranslationsForLocale(string $locale): array
    {
        return $this->translations[$locale] ?? [];
    }

    /**
     * Get a specific translation.
     */
    public function getTranslation(string $key, ?string $locale = null): ?string
    {
        if ($locale === null) {
            // If no locale specified, try to get from first target locale
            $locales = is_array($this->targetLocales) ? $this->targetLocales : [$this->targetLocales];
            $locale = $locales[0] ?? null;
        }

        if ($locale === null) {
            return null;
        }

        return $this->translations[$locale][$key] ?? null;
    }

    /**
     * Get token usage statistics.
     */
    public function getTokenUsage(): array
    {
        return $this->tokenUsage;
    }

    /**
     * Get total token count.
     */
    public function getTotalTokens(): int
    {
        return $this->tokenUsage['total'] ?? 0;
    }

    /**
     * Get estimated cost (requires provider rates).
     */
    public function getCost(array $rates = []): float
    {
        if (empty($rates)) {
            // Default rates (example values, should be configurable)
            $rates = [
                'input' => 0.00001,  // per token
                'output' => 0.00003, // per token
            ];
        }

        $inputCost = ($this->tokenUsage['input'] ?? 0) * ($rates['input'] ?? 0);
        $outputCost = ($this->tokenUsage['output'] ?? 0) * ($rates['output'] ?? 0);

        return round($inputCost + $outputCost, 4);
    }

    /**
     * Get only changed items (if diff tracking was enabled).
     */
    public function getDiff(): array
    {
        return $this->metadata['diff'] ?? [];
    }

    /**
     * Get errors encountered during translation.
     */
    public function getErrors(): array
    {
        return $this->metadata['errors'] ?? [];
    }

    /**
     * Check if translation had errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->getErrors());
    }

    /**
     * Get warnings encountered during translation.
     */
    public function getWarnings(): array
    {
        return $this->metadata['warnings'] ?? [];
    }

    /**
     * Check if translation had warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->getWarnings());
    }

    /**
     * Get processing duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->metadata['duration'] ?? 0.0;
    }

    /**
     * Get source locale.
     */
    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    /**
     * Get target locale(s).
     */
    public function getTargetLocales(): string|array
    {
        return $this->targetLocales;
    }

    /**
     * Get metadata.
     */
    public function getMetadata(string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    /**
     * Get translation outputs (if available).
     */
    public function getOutputs(): array
    {
        return $this->metadata['outputs'] ?? [];
    }

    /**
     * Check if translation was successful.
     */
    public function isSuccessful(): bool
    {
        return !$this->hasErrors() && !empty($this->translations);
    }

    /**
     * Get statistics about the translation.
     */
    public function getStatistics(): array
    {
        $totalTranslations = 0;
        $localeStats = [];

        foreach ($this->translations as $locale => $translations) {
            $count = count($translations);
            $totalTranslations += $count;
            $localeStats[$locale] = $count;
        }

        return [
            'total_translations' => $totalTranslations,
            'by_locale' => $localeStats,
            'token_usage' => $this->tokenUsage,
            'duration' => $this->getDuration(),
            'cost' => $this->getCost(),
            'errors' => count($this->getErrors()),
            'warnings' => count($this->getWarnings()),
        ];
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'translations' => $this->translations,
            'token_usage' => $this->tokenUsage,
            'source_locale' => $this->sourceLocale,
            'target_locales' => $this->targetLocales,
            'metadata' => $this->metadata,
            'statistics' => $this->getStatistics(),
            'successful' => $this->isSuccessful(),
        ];
    }

    /**
     * Convert to JSON.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Save translations to files.
     */
    public function save(string $basePath): void
    {
        foreach ($this->translations as $locale => $translations) {
            $path = "{$basePath}/{$locale}.json";
            
            // Ensure directory exists
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($path, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Merge with another result.
     */
    public function merge(TranslationResult $other): self
    {
        // Merge translations
        foreach ($other->getTranslations() as $locale => $translations) {
            if (!isset($this->translations[$locale])) {
                $this->translations[$locale] = [];
            }
            $this->translations[$locale] = array_merge($this->translations[$locale], $translations);
        }

        // Merge token usage
        $this->tokenUsage['input'] = ($this->tokenUsage['input'] ?? 0) + ($other->tokenUsage['input'] ?? 0);
        $this->tokenUsage['output'] = ($this->tokenUsage['output'] ?? 0) + ($other->tokenUsage['output'] ?? 0);
        $this->tokenUsage['total'] = $this->tokenUsage['input'] + $this->tokenUsage['output'];

        // Merge metadata
        if ($other->hasErrors()) {
            $this->metadata['errors'] = array_merge(
                $this->metadata['errors'] ?? [],
                $other->getErrors()
            );
        }

        if ($other->hasWarnings()) {
            $this->metadata['warnings'] = array_merge(
                $this->metadata['warnings'] ?? [],
                $other->getWarnings()
            );
        }

        // Update duration
        $this->metadata['duration'] = ($this->metadata['duration'] ?? 0) + $other->getDuration();

        return $this;
    }

    /**
     * Filter translations by keys.
     */
    public function filter(array $keys): self
    {
        $filtered = [];

        foreach ($this->translations as $locale => $translations) {
            $filtered[$locale] = array_intersect_key($translations, array_flip($keys));
        }

        return new self(
            $filtered,
            $this->tokenUsage,
            $this->sourceLocale,
            $this->targetLocales,
            $this->metadata
        );
    }

    /**
     * Map translations.
     */
    public function map(callable $callback): self
    {
        $mapped = [];

        foreach ($this->translations as $locale => $translations) {
            $mapped[$locale] = [];
            foreach ($translations as $key => $value) {
                $mapped[$locale][$key] = $callback($value, $key, $locale);
            }
        }

        return new self(
            $mapped,
            $this->tokenUsage,
            $this->sourceLocale,
            $this->targetLocales,
            $this->metadata
        );
    }
}