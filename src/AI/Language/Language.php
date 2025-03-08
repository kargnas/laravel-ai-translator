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
        $name = LanguageConfig::getLanguageName($code) ?? $code;
        $pluralForms = Utility::getPluralForms($code);

        if ($name === $code) {
            \Log::warning("Language::fromCode: Language name not found for code = {$code}");
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