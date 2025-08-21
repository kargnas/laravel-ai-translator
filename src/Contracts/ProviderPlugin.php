<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;

interface ProviderPlugin extends TranslationPlugin
{
    /**
     * Get the services this provider offers.
     * 
     * @return array<string> Array of service names
     */
    public function provides(): array;

    /**
     * Get the stages when this provider should be active.
     * 
     * @return array<string> Array of stage names
     */
    public function when(): array;

    /**
     * Execute the provider's main service logic.
     * 
     * @param TranslationContext $context The translation context
     * @return mixed The result of the provider execution
     */
    public function execute(TranslationContext $context): mixed;
}