<?php

namespace Kargnas\LaravelAiTranslator\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranslationEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @var string The event name
     */
    public string $name;

    /**
     * @var mixed The event data
     */
    public mixed $data;

    /**
     * @var float Event timestamp
     */
    public float $timestamp;

    public function __construct(string $name, mixed $data)
    {
        $this->name = $name;
        $this->data = $data;
        $this->timestamp = microtime(true);
    }

    /**
     * Get the event name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the event data.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get the event timestamp.
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ];
    }
}