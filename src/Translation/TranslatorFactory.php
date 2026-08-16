<?php

namespace Kargnas\LaravelAiTranslator\Translation;

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Contracts\Translator;

class TranslatorFactory
{
    public static function usesConsensus(): bool
    {
        return count(config('ai-translator.consensus.translators', [])) >= 2;
    }

    public static function make(
        string $filename,
        array $strings,
        string $sourceLocale,
        string $targetLocale,
        array $references = [],
        array $additionalRules = [],
        ?array $globalContext = null,
    ): Translator {
        if (self::usesConsensus()) {
            return new ConsensusTranslator(
                $filename,
                $strings,
                $sourceLocale,
                $targetLocale,
                $references,
                $additionalRules,
                $globalContext,
                config('ai-translator.consensus.translators', []),
                config('ai-translator.consensus.judge', []),
            );
        }

        return new AIProvider(
            $filename,
            $strings,
            $sourceLocale,
            $targetLocale,
            $references,
            $additionalRules,
            $globalContext,
        );
    }
}
