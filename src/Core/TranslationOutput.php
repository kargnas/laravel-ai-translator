<?php

namespace Kargnas\LaravelAiTranslator\Core;

class TranslationOutput
{
    /**
     * @var string The output type (for backward compatibility)
     */
    public string $type;

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
        array $metadata = [],
        string $type = 'translation'
    ) {
        $this->type = $type;
        $this->key = $key;
        $this->value = $value;
        $this->locale = $locale;
        $this->cached = $cached;
        $this->metadata = $metadata;
    }

    /**
     * Get token usage from metadata
     */
    public function getTokenUsage(): array
    {
        return $this->metadata['token_usage'] ?? [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ];
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