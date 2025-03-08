<?php

namespace Kargnas\LaravelAiTranslator\Enums;

class TranslationStatus
{
    public const STARTED = 'started';
    public const IN_PROGRESS = 'inprogress';
    public const COMPLETED = 'completed';

    /**
     * Get all valid statuses
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::STARTED,
            self::IN_PROGRESS,
            self::COMPLETED,
        ];
    }

    /**
     * Check if the given status is valid
     *
     * @param string $status
     * @return bool
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}