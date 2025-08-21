<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;

/**
 * CustomStageExamplePlugin - Example of a plugin that defines its own custom stage
 * 
 * This demonstrates how plugins can extend the pipeline with custom stages
 * beyond the core stages defined in PipelineStages.
 * 
 * Example Use Cases:
 * - Add a 'metrics_collection' stage for gathering translation metrics
 * - Add a 'notification' stage for sending progress updates
 * - Add a 'backup' stage for saving translations before output
 * - Add a 'review' stage for manual review workflow
 */
class CustomStageExamplePlugin extends AbstractObserverPlugin
{
    
    /**
     * Custom stage name that this plugin defines
     */
    const CUSTOM_STAGE = 'custom_processing';
    
    /**
     * Subscribe to events including our custom stage
     */
    public function subscribe(): array
    {
        return [
            'stage.' . self::CUSTOM_STAGE . '.started' => 'onCustomStageStarted',
            'stage.' . self::CUSTOM_STAGE . '.completed' => 'onCustomStageCompleted',
        ];
    }
    
    /**
     * Boot the plugin and register custom stage
     */
    public function boot(TranslationPipeline $pipeline): void
    {
        // Register our custom stage handler
        $pipeline->registerStage(self::CUSTOM_STAGE, [$this, 'processCustomStage'], 50);
        
        // Call parent to register event subscriptions
        parent::boot($pipeline);
    }
    
    /**
     * Process the custom stage
     */
    public function processCustomStage(TranslationContext $context): void
    {
        $this->info('Processing custom stage', [
            'texts_count' => count($context->texts),
            'stage' => self::CUSTOM_STAGE,
        ]);
        
        // Add custom processing logic here
        // For example: collect metrics, send notifications, etc.
        $context->metadata['custom_processed'] = true;
        $context->metadata['custom_timestamp'] = time();
    }
    
    /**
     * Handle custom stage started event
     */
    public function onCustomStageStarted(TranslationContext $context): void
    {
        $this->debug('Custom stage started');
    }
    
    /**
     * Handle custom stage completed event
     */
    public function onCustomStageCompleted(TranslationContext $context): void
    {
        $this->debug('Custom stage completed', [
            'custom_processed' => $context->metadata['custom_processed'] ?? false,
        ]);
    }
}