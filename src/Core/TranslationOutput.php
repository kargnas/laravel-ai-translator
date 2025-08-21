<?php

namespace Kargnas\LaravelAiTranslator\Core;

class TranslationOutput
{
    /**
     * @var string The translation key
     */
    public string $key;

    /**
     * @var string The translated value
     */
    public string $value;

    /**
     * @var string The target locale
     */
    public string $locale;

    /**
     * @var bool Whether this was retrieved from cache
     */
    public bool $cached;

    /**
     * @var array<string, mixed> Additional metadata
     */
    public array $metadata;

    public function __construct(
        string $key,
        string $value,
        string $locale,
        bool $cached = false,
        array $metadata = []
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->locale = $locale;
        $this->cached = $cached;
        $this->metadata = $metadata;
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'locale' => $this->locale,
            'cached' => $this->cached,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['value'],
            $data['locale'],
            $data['cached'] ?? false,
            $data['metadata'] ?? []
        );
    }
}