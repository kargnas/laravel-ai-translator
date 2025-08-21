<?php

namespace Kargnas\LaravelAiTranslator\Core;

use Kargnas\LaravelAiTranslator\Core\TranslationRequest;
use Illuminate\Support\Collection;

/**
 * TranslationContext - Central state container for the entire translation process
 * 
 * Core Responsibilities:
 * - Maintains the complete state of a translation operation from start to finish
 * - Tracks original texts, translations, and all intermediate transformations
 * - Manages plugin-specific data storage in an isolated namespace
 * - Collects and aggregates errors, warnings, and performance metrics
 * - Provides token usage tracking for cost calculation
 * - Records timing information for performance analysis
 * 
 * State Management:
 * The context acts as a shared blackboard where plugins can read and write
 * data throughout the translation process. Each plugin can store its own
 * data without interfering with others.
 * 
 * Data Flow:
 * 1. Initialized with TranslationRequest containing source texts
 * 2. Modified by plugins during each pipeline stage
 * 3. Accumulates translations, metrics, and metadata
 * 4. Provides final snapshot for result generation
 */
class TranslationContext
{
    /**
     * @var array<string, string> Original texts to translate (key => text)
     */
    public array $texts = [];

    /**
     * @var array<string, array<string, string>> Translations by locale (locale => [key => translation])
     */
    public array $translations = [];

    /**
     * @var array<string, mixed> Metadata for the translation process
     */
    public array $metadata = [];

    /**
     * @var array<string, mixed> Runtime state data
     */
    public array $state = [];

    /**
     * @var array<string> Processing errors
     */
    public array $errors = [];

    /**
     * @var array<string> Processing warnings
     */
    public array $warnings = [];

    /**
     * @var Collection Plugin-specific data storage
     */
    public Collection $pluginData;

    /**
     * @var TranslationRequest The original request
     */
    public TranslationRequest $request;

    /**
     * @var string Current processing stage
     */
    public string $currentStage = '';

    /**
     * @var array Token usage tracking
     */
    public array $tokenUsage = [
        'input' => 0,
        'output' => 0,
        'total' => 0,
    ];

    /**
     * @var float Processing start time
     */
    public float $startTime;

    /**
     * @var float|null Processing end time
     */
    public ?float $endTime = null;

    public function __construct(TranslationRequest $request)
    {
        $this->request = $request;
        $this->texts = $request->texts;
        $this->metadata = $request->metadata;
        $this->pluginData = new Collection();
        $this->startTime = microtime(true);
    }

    /**
     * Get plugin-specific data
     * 
     * Retrieves data stored by a specific plugin, maintaining isolation
     * between different plugins' data spaces
     * 
     * @param string $pluginName The name of the plugin
     * @return mixed The stored data or null if not found
     */
    public function getPluginData(string $pluginName): mixed
    {
        return $this->pluginData->get($pluginName);
    }

    /**
     * Set plugin-specific data.
     */
    public function setPluginData(string $pluginName, mixed $data): void
    {
        $this->pluginData->put($pluginName, $data);
    }

    /**
     * Add a translation for a specific locale.
     */
    public function addTranslation(string $locale, string $key, string $translation): void
    {
        if (!isset($this->translations[$locale])) {
            $this->translations[$locale] = [];
        }
        $this->translations[$locale][$key] = $translation;
    }

    /**
     * Get translations for a specific locale.
     */
    public function getTranslations(string $locale): array
    {
        return $this->translations[$locale] ?? [];
    }

    /**
     * Add an error message.
     */
    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Add a warning message.
     */
    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Check if the context has errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Update token usage.
     */
    public function addTokenUsage(int $input, int $output): void
    {
        $this->tokenUsage['input'] += $input;
        $this->tokenUsage['output'] += $output;
        $this->tokenUsage['total'] = $this->tokenUsage['input'] + $this->tokenUsage['output'];
    }

    /**
     * Mark processing as complete.
     */
    public function complete(): void
    {
        $this->endTime = microtime(true);
    }

    /**
     * Get processing duration in seconds.
     */
    public function getDuration(): float
    {
        $endTime = $this->endTime ?? microtime(true);
        return $endTime - $this->startTime;
    }

    /**
     * Create a snapshot of the current context state
     * 
     * Captures the complete state at a point in time, useful for
     * debugging, logging, and creating immutable checkpoints
     * 
     * @return array Complete state representation
     */
    public function snapshot(): array
    {
        return [
            'texts' => $this->texts,
            'translations' => $this->translations,
            'metadata' => $this->metadata,
            'state' => $this->state,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'currentStage' => $this->currentStage,
            'tokenUsage' => $this->tokenUsage,
            'duration' => $this->getDuration(),
        ];
    }
}