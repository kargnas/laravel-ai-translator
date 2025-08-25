<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;

interface MiddlewarePlugin extends TranslationPlugin
{
    /**
     * Handle the translation context transformation.
     * 
     * @param TranslationContext $context The translation context
     * @param Closure $next The next middleware in the chain
     * @return mixed The response from the middleware chain
     */
    public function handle(TranslationContext $context, Closure $next): mixed;

    /**
     * Perform any cleanup or reverse transformations after processing.
     * 
     * @param TranslationContext $context The translation context
     * @param mixed $response The response from the pipeline
     */
    public function terminate(TranslationContext $context, mixed $response): void;
}