<?php

namespace Kargnas\LaravelAiTranslator\AI\Language;

use Kargnas\LaravelAiTranslator\Utility;

class Language
{
    public function __construct(
        public string $code,
        public string $name,
        public int $pluralForms = 2,
    ) {
    }

    public static function fromCode(string $code): self
    {
        $code = static::normalizeCode($code);

        // Get language name and validate
        $name = LanguageConfig::getLanguageName($code);
        if (!$name) {
            // Try to find the language code from the name
            $allLanguages = LanguageConfig::getAllLanguages();
            foreach ($allLanguages as $langCode => $langName) {
                if (strtolower($langName) === $code) {
                    $code = $langCode;
                    $name = $langName;
                    break;
                }
            }

            if (!$name) {
                throw new \InvalidArgumentException("Invalid language code: {$code}");
            }
        }

        // Get plural forms from Utility
        $pluralForms = Utility::getPluralForms($code);

        // Try base code if full code not found
        if ($pluralForms === null) {
            $baseCode = substr($code, 0, 2);
            $pluralForms = Utility::getPluralForms($baseCode);
        }

        // Let constructor use its default value if pluralForms is still null
        if ($pluralForms === null) {
            return new self($code, $name);
        }

        return new self($code, $name, $pluralForms);
    }

    public static function normalizeCode(string $code): string
    {
        return strtolower(str_replace('-', '_', $code));
    }

    public function getBaseCode(): string
    {
        return substr($this->code, 0, 2);
    }

    public function is(string $code): bool
    {
        $code = static::normalizeCode($code);
        return $this->code === $code || $this->getBaseCode() === $code;
    }

    public function hasPlural(): bool
    {
        return $this->pluralForms > 1;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}