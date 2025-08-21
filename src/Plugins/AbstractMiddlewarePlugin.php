<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Closure;
use Kargnas\LaravelAiTranslator\Contracts\MiddlewarePlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;

abstract class AbstractMiddlewarePlugin extends AbstractTranslationPlugin implements MiddlewarePlugin
{
    /**
     * The pipeline stage this middleware operates in.
     */
    abstract protected function getStage(): string;

    /**
     * {@inheritDoc}
     */
    public function boot(TranslationPipeline $pipeline): void
    {
        $pipeline->registerStage($this->getStage(), [$this, 'handle'], $this->getPriority());

        // Register termination handler if the plugin implements it
        if (method_exists($this, 'terminate')) {
            $pipeline->registerTerminator([$this, 'terminate'], $this->getPriority());
        }
    }

    /**
     * {@inheritDoc}
     */
    abstract public function handle(TranslationContext $context, Closure $next): mixed;

    /**
     * {@inheritDoc}
     * Default implementation does nothing.
     */
    public function terminate(TranslationContext $context, mixed $response): void
    {
        // Default: no termination logic
    }

    /**
     * Helper method to check if middleware should be skipped.
     */
    protected function shouldSkip(TranslationContext $context): bool
    {
        // Check if plugin is disabled for this tenant
        if ($context->request->tenantId && !$this->isEnabledFor($context->request->tenantId)) {
            return true;
        }

        // Check if plugin is explicitly disabled in request
        if ($context->request->getOption("skip_{$this->getName()}", false)) {
            return true;
        }

        return false;
    }

    /**
     * Helper method to pass through to next middleware.
     */
    protected function passThrough(TranslationContext $context, Closure $next): mixed
    {
        return $next($context);
    }
}