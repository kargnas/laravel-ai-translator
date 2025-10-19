<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;

interface ObserverPlugin extends TranslationPlugin
{
    /**
     * Get the events this observer subscribes to.
     * 
     * @return array<string, string> Event name => handler method mapping
     */
    public function subscribe(): array;

    /**
     * Handle an observed event.
     * 
     * @param string $event The event name
     * @param TranslationContext $context The translation context
     */
    public function observe(string $event, TranslationContext $context): void;

    /**
     * Emit a custom event.
     * 
     * @param string $event The event name
     * @param mixed $data The event data
     */
    public function emit(string $event, mixed $data): void;
}