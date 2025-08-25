<?php

namespace Kargnas\LaravelAiTranslator\Models;

/**
 * Simple data object for localized strings
 */
class LocalizedString
{
    /**
     * @var string
     */
    public string $key = '';

    /**
     * @var string
     */
    public string $translated = '';

    /**
     * @var string|null
     */
    public ?string $comment = null;
}