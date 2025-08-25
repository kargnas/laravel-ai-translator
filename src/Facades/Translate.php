<?php

namespace Kargnas\LaravelAiTranslator\Facades;

use Illuminate\Support\Facades\Facade;
use Kargnas\LaravelAiTranslator\TranslationBuilder;

/**
 * @method static string text(string $text, string $from, string $to)
 * @method static array array(array $texts, string $from, string $to)
 * @method static TranslationBuilder builder()
 * 
 * @see \Kargnas\LaravelAiTranslator\TranslationBuilder
 */
class Translate extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'translator';
    }

    /**
     * Translate a single text string.
     */
    public static function text(string $text, string $from, string $to): string
    {
        $result = static::builder()
            ->from($from)
            ->to($to)
            ->translate(['text' => $text]);

        return $result->getTranslation('text', $to) ?? $text;
    }

    /**
     * Translate an array of texts.
     */
    public static function array(array $texts, string $from, string $to): array
    {
        $result = static::builder()
            ->from($from)
            ->to($to)
            ->translate($texts);

        return $result->getTranslationsForLocale($to);
    }

    /**
     * Get a new translation builder instance.
     */
    public static function builder(): TranslationBuilder
    {
        return app(TranslationBuilder::class);
    }
}