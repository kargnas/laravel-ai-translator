<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationOutput;
use Generator;

/**
 * StreamingOutputPlugin - Provides real-time streaming of translation results
 * 
 * Primary Responsibilities:
 * - Implements AsyncGenerator-based streaming for memory efficiency
 * - Differentiates between cached and newly translated content
 * - Provides progressive output for real-time UI updates
 * - Manages output buffering and flushing strategies
 * - Handles backpressure in streaming scenarios
 * - Supports partial result delivery for long-running translations
 * 
 * Streaming Benefits:
 * - Reduced memory footprint for large translation batches
 * - Immediate user feedback as translations complete
 * - Better perceived performance through progressive rendering
 * - Ability to cancel long-running operations mid-stream
 * 
 * Output Flow:
 * The plugin intercepts translation outputs and yields them immediately
 * rather than accumulating all results in memory before returning.
 */
class StreamingOutputPlugin extends AbstractObserverPlugin
{
    protected string $name = 'streaming_output';
    
    protected int $priority = 10; // Low priority to run last

    /**
     * @var Generator|null Current output generator
     */
    protected ?Generator $outputGenerator = null;

    /**
     * @var array Buffer for outputs before streaming starts
     */
    protected array $outputBuffer = [];

    /**
     * @var bool Whether streaming is currently active
     */
    protected bool $isStreaming = false;

    /**
     * Get default configuration for streaming
     * 
     * Defines streaming behavior and buffering strategies
     */
    protected function getDefaultConfig(): array
    {
        return [
            'streaming' => [
                'enabled' => true,
                'buffer_size' => 10,
                'flush_interval' => 0.1, // seconds
                'differentiate_cached' => true,
                'include_metadata' => true,
            ],
            'progress' => [
                'report_progress' => true,
                'progress_interval' => 5, // Report every N items
                'include_estimates' => true,
            ],
            'formatting' => [
                'format' => 'json', // json, text, or custom
                'pretty_print' => false,
                'include_timestamps' => true,
            ],
        ];
    }

    /**
     * Subscribe to pipeline events for streaming
     * 
     * Monitors translation events to capture and stream outputs
     */
    public function subscribe(): array
    {
        return [
            'translation.started' => 'onTranslationStarted',
            'translation.output' => 'onTranslationOutput',
            'translation.completed' => 'onTranslationCompleted',
            'stage.output.started' => 'startStreaming',
            'stage.output.completed' => 'endStreaming',
        ];
    }

    /**
     * Handle translation started event
     * 
     * Initializes streaming state and prepares buffers
     * 
     * @param TranslationContext $context Translation context
     */
    public function onTranslationStarted(TranslationContext $context): void
    {
        if (!$this->getConfigValue('streaming.enabled', true)) {
            return;
        }

        // Reset state
        $this->outputBuffer = [];
        $this->isStreaming = false;
        $this->outputGenerator = null;

        // Initialize plugin data
        $context->setPluginData($this->getName(), [
            'start_time' => microtime(true),
            'output_count' => 0,
            'cached_count' => 0,
            'total_expected' => count($context->texts) * count($context->request->getTargetLocales()),
        ]);

        $this->debug('Streaming output initialized', [
            'texts' => count($context->texts),
            'locales' => count($context->request->getTargetLocales()),
        ]);
    }

    /**
     * Start streaming when output stage begins
     * 
     * Transitions from buffering to active streaming mode
     * 
     * @param TranslationContext $context Translation context
     */
    public function startStreaming(TranslationContext $context): void
    {
        if (!$this->getConfigValue('streaming.enabled', true)) {
            return;
        }

        $this->isStreaming = true;
        
        // Create output generator
        $this->outputGenerator = $this->createOutputStream($context);
        
        // Flush any buffered outputs
        if (!empty($this->outputBuffer)) {
            $this->flushBuffer($context);
        }

        $this->info('Streaming started', [
            'buffered_outputs' => count($this->outputBuffer),
        ]);
    }

    /**
     * Handle individual translation output
     * 
     * Captures outputs and either buffers or streams them
     * 
     * @param TranslationContext $context Translation context
     */
    public function onTranslationOutput(TranslationContext $context): void
    {
        if (!$this->getConfigValue('streaming.enabled', true)) {
            return;
        }

        // This would be triggered by actual translation outputs
        // For now, we'll process outputs from context
        $this->processOutputs($context);
    }

    /**
     * Process and stream translation outputs
     * 
     * Responsibilities:
     * - Extract outputs from context
     * - Differentiate cached vs new translations
     * - Apply formatting and metadata
     * - Yield outputs through generator
     * 
     * @param TranslationContext $context Translation context
     */
    protected function processOutputs(TranslationContext $context): void
    {
        $pluginData = $context->getPluginData($this->getName());
        $differentiateCached = $this->getConfigValue('streaming.differentiate_cached', true);
        
        foreach ($context->translations as $locale => $translations) {
            foreach ($translations as $key => $translation) {
                // Check if this is a cached translation
                $isCached = false;
                if ($differentiateCached) {
                    $isCached = $this->isCachedTranslation($context, $key, $locale);
                }

                // Create output object
                $output = $this->createOutput($key, $translation, $locale, $isCached, $context);
                
                // Stream or buffer
                if ($this->isStreaming && $this->outputGenerator) {
                    $this->outputGenerator->send($output);
                } else {
                    $this->outputBuffer[] = $output;
                }

                // Update statistics
                $pluginData['output_count']++;
                if ($isCached) {
                    $pluginData['cached_count']++;
                }
                
                // Report progress if configured
                $this->reportProgress($context, $pluginData);
            }
        }
        
        $context->setPluginData($this->getName(), $pluginData);
    }

    /**
     * Create output stream generator
     * 
     * Implements the core streaming logic using PHP generators
     * 
     * @param TranslationContext $context Translation context
     * @return Generator Output stream
     */
    protected function createOutputStream(TranslationContext $context): Generator
    {
        $bufferSize = $this->getConfigValue('streaming.buffer_size', 10);
        $flushInterval = $this->getConfigValue('streaming.flush_interval', 0.1);
        $lastFlush = microtime(true);
        $buffer = [];

        while (true) {
            // Receive output from send()
            $output = yield;
            
            if ($output === null) {
                // End of stream signal
                if (!empty($buffer)) {
                    yield from $buffer;
                }
                break;
            }

            $buffer[] = $output;

            // Check if we should flush
            $shouldFlush = count($buffer) >= $bufferSize ||
                          (microtime(true) - $lastFlush) >= $flushInterval;

            if ($shouldFlush) {
                yield from $buffer;
                $buffer = [];
                $lastFlush = microtime(true);
            }
        }
    }

    /**
     * Create formatted output object
     * 
     * Builds a structured output with metadata and formatting
     * 
     * @param string $key Translation key
     * @param string $translation Translated text
     * @param string $locale Target locale
     * @param bool $cached Whether from cache
     * @param TranslationContext $context Translation context
     * @return TranslationOutput Formatted output
     */
    protected function createOutput(
        string $key,
        string $translation,
        string $locale,
        bool $cached,
        TranslationContext $context
    ): TranslationOutput {
        $metadata = [];

        if ($this->getConfigValue('streaming.include_metadata', true)) {
            $metadata = [
                'cached' => $cached,
                'locale' => $locale,
                'timestamp' => microtime(true),
            ];

            // Add source text if available
            if (isset($context->texts[$key])) {
                $metadata['source'] = $context->texts[$key];
            }

            // Add token usage if tracked
            if (!$cached && isset($context->tokenUsage)) {
                $metadata['tokens'] = [
                    'estimated' => $this->estimateTokens($translation),
                ];
            }
        }

        return new TranslationOutput($key, $translation, $locale, $cached, $metadata);
    }

    /**
     * Check if a translation is from cache
     * 
     * @param TranslationContext $context Translation context
     * @param string $key Translation key
     * @param string $locale Target locale
     * @return bool Whether translation is cached
     */
    protected function isCachedTranslation(TranslationContext $context, string $key, string $locale): bool
    {
        // Check if DiffTrackingPlugin marked this as cached
        $diffData = $context->getPluginData('diff_tracking');
        if ($diffData && isset($diffData['changes']['unchanged'][$key])) {
            return true;
        }

        // Check metadata for cache indicators
        if (isset($context->metadata['cached_translations'][$locale][$key])) {
            return true;
        }

        return false;
    }

    /**
     * Flush buffered outputs
     * 
     * Sends all buffered outputs through the stream
     * 
     * @param TranslationContext $context Translation context
     */
    protected function flushBuffer(TranslationContext $context): void
    {
        if (empty($this->outputBuffer) || !$this->outputGenerator) {
            return;
        }

        foreach ($this->outputBuffer as $output) {
            $this->outputGenerator->send($output);
        }

        $this->outputBuffer = [];
        
        $this->debug('Flushed output buffer', [
            'count' => count($this->outputBuffer),
        ]);
    }

    /**
     * Report translation progress
     * 
     * Emits progress events for UI updates
     * 
     * @param TranslationContext $context Translation context
     * @param array $pluginData Plugin data
     */
    protected function reportProgress(TranslationContext $context, array $pluginData): void
    {
        if (!$this->getConfigValue('progress.report_progress', true)) {
            return;
        }

        $interval = $this->getConfigValue('progress.progress_interval', 5);
        $outputCount = $pluginData['output_count'];

        if ($outputCount % $interval === 0 || $outputCount === $pluginData['total_expected']) {
            $progress = [
                'completed' => $outputCount,
                'total' => $pluginData['total_expected'],
                'percentage' => round(($outputCount / max($pluginData['total_expected'], 1)) * 100, 2),
                'cached' => $pluginData['cached_count'],
                'elapsed' => microtime(true) - $pluginData['start_time'],
            ];

            if ($this->getConfigValue('progress.include_estimates', true)) {
                $progress['estimated_remaining'] = $this->estimateRemainingTime($progress);
            }

            $this->emit('streaming.progress', $progress);
            
            $this->debug('Progress report', $progress);
        }
    }

    /**
     * Estimate remaining time based on current progress
     * 
     * @param array $progress Current progress data
     * @return float Estimated seconds remaining
     */
    protected function estimateRemainingTime(array $progress): float
    {
        if ($progress['completed'] === 0) {
            return 0;
        }

        $rate = $progress['completed'] / $progress['elapsed'];
        $remaining = $progress['total'] - $progress['completed'];
        
        return $remaining / max($rate, 0.001);
    }

    /**
     * Estimate token count for text
     * 
     * Simple estimation for metadata purposes
     * 
     * @param string $text Text to estimate
     * @return int Estimated token count
     */
    protected function estimateTokens(string $text): int
    {
        // Simple estimation: ~4 characters per token for English
        // This would be more sophisticated in production
        return (int)(mb_strlen($text) / 4);
    }

    /**
     * End streaming when output stage completes
     * 
     * @param TranslationContext $context Translation context
     */
    public function endStreaming(TranslationContext $context): void
    {
        if (!$this->isStreaming) {
            return;
        }

        // Send end signal to generator
        if ($this->outputGenerator) {
            $this->outputGenerator->send(null);
        }

        $this->isStreaming = false;
        
        $pluginData = $context->getPluginData($this->getName());
        
        $this->info('Streaming completed', [
            'total_outputs' => $pluginData['output_count'] ?? 0,
            'cached_outputs' => $pluginData['cached_count'] ?? 0,
            'duration' => microtime(true) - ($pluginData['start_time'] ?? 0),
        ]);
    }

    /**
     * Handle translation completed event
     * 
     * Final cleanup and statistics emission
     * 
     * @param TranslationContext $context Translation context
     */
    public function onTranslationCompleted(TranslationContext $context): void
    {
        // Ensure streaming is ended
        $this->endStreaming($context);

        // Emit final statistics
        $pluginData = $context->getPluginData($this->getName());
        
        if ($pluginData) {
            $this->emit('streaming.completed', [
                'total_outputs' => $pluginData['output_count'] ?? 0,
                'cached_outputs' => $pluginData['cached_count'] ?? 0,
                'cache_ratio' => $pluginData['output_count'] > 0 
                    ? round(($pluginData['cached_count'] / $pluginData['output_count']) * 100, 2)
                    : 0,
                'total_time' => microtime(true) - ($pluginData['start_time'] ?? 0),
            ]);
        }
    }

    /**
     * Get the current output generator
     * 
     * @return Generator|null Current generator or null
     */
    public function getOutputStream(): ?Generator
    {
        return $this->outputGenerator;
    }

    /**
     * Check if streaming is active
     * 
     * @return bool Whether streaming is active
     */
    public function isStreamingActive(): bool
    {
        return $this->isStreaming;
    }
}