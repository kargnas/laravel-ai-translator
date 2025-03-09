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
        \Log::debug("Language::fromCode - Processing code: {$code}");

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
        \Log::debug("Language::fromCode - Found name: {$name}");

        // Get plural forms from Utility
        $pluralForms = Utility::getPluralForms($code);
        \Log::debug("Language::fromCode - Plural forms for {$code}: " . var_export($pluralForms, true));

        // Try base code if full code not found
        if ($pluralForms === null) {
            $baseCode = substr($code, 0, 2);
            $pluralForms = Utility::getPluralForms($baseCode);
            \Log::debug("Language::fromCode - Trying base code {$baseCode}, plural forms: " . var_export($pluralForms, true));
        }

        // Let constructor use its default value if pluralForms is still null
        if ($pluralForms === null) {
            \Log::debug("Language::fromCode - Using default plural forms for {$code}");
            return new self($code, $name);
        }

        \Log::debug("Language::fromCode - Creating language object for {$code} with plural forms: {$pluralForms}");
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