<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Contracts\ObserverPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Events\TranslationEvent;

abstract class AbstractObserverPlugin extends AbstractTranslationPlugin implements ObserverPlugin
{
    /**
     * {@inheritDoc}
     */
    abstract public function subscribe(): array;

    /**
     * {@inheritDoc}
     */
    public function observe(string $event, TranslationContext $context): void
    {
        $handlers = $this->subscribe();
        
        if (isset($handlers[$event])) {
            $method = $handlers[$event];
            if (method_exists($this, $method)) {
                $this->{$method}($context);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function emit(string $event, mixed $data): void
    {
        if (class_exists(TranslationEvent::class)) {
            event(new TranslationEvent($event, $data));
        } else {
            // Fallback to Laravel's generic event
            event("{$this->getName()}.{$event}", [$data]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function boot(TranslationPipeline $pipeline): void
    {
        // Subscribe to pipeline events
        foreach ($this->subscribe() as $event => $handler) {
            $pipeline->on($event, function (TranslationContext $context) use ($event) {
                if ($this->shouldObserve($context)) {
                    $this->observe($event, $context);
                }
            });
        }
    }

    /**
     * Check if this observer should observe events for the context.
     */
    protected function shouldObserve(TranslationContext $context): bool
    {
        // Check if observer is disabled for this tenant
        if ($context->request->tenantId && !$this->isEnabledFor($context->request->tenantId)) {
            return false;
        }

        // Check if observer is explicitly disabled in request
        if ($context->request->getOption("disable_{$this->getName()}", false)) {
            return false;
        }

        return true;
    }

    /**
     * Helper method to track metrics.
     */
    protected function trackMetric(string $metric, mixed $value, array $tags = []): void
    {
        $this->emit('metric', [
            'plugin' => $this->getName(),
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Helper method to log events.
     */
    protected function logEvent(string $event, array $data = []): void
    {
        $this->info("Event: {$event}", $data);
    }
}