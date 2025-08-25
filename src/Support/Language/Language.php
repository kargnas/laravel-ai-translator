<?php

namespace Kargnas\LaravelAiTranslator\Support\Language;

class Language
{
    public function __construct(
        public string $code,
        public string $name,
        public int $pluralForms = 2,
    ) {}

    public static function fromCode(string $code): self
    {
        $code = static::normalizeCode($code);
        $name = LanguageConfig::getLanguageName($code);
        
        if (!$name) {
            throw new \InvalidArgumentException("Invalid language code: {$code}");
        }

        $pluralForms = LanguageConfig::getPluralForms($code);
        
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
