<?php

namespace Kargnas\LaravelAiTranslator\Translation;

use Illuminate\Support\Facades\Storage;
use JsonException;

class ChangeDetector
{
    /**
     * Build a stable checksum from key and normalized source text so formatting-only edits do not retrigger translation.
     */
    public function checksum(string $key, string $text): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        return hash('sha256', "{$key}:{$normalized}");
    }

    /**
     * Return source entries whose saved checksum exists and differs from the current checksum.
     */
    public function changedAgainstState(array $strings, string $source, string $target): array
    {
        $state = $this->readState($source, $target);
        $changed = [];

        foreach ($strings as $key => $value) {
            $key = (string) $key;
            $checksum = $this->checksum($key, $this->text($value));

            if (array_key_exists($key, $state) && $state[$key] !== $checksum) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    /**
     * Merge checksums for provided source keys while preserving state for other language entries.
     */
    public function saveState(array $keys, array $sourceStrings, string $source, string $target): void
    {
        $state = $this->readState($source, $target);

        foreach ($keys as $key) {
            $key = (string) $key;

            if (array_key_exists($key, $sourceStrings)) {
                $state[$key] = $this->checksum($key, $this->text($sourceStrings[$key]));
            }
        }

        $disk = Storage::disk($this->disk());
        $disk->put(
            $this->statePath($source, $target),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function text(mixed $value): string
    {
        if (is_array($value) && array_key_exists('text', $value)) {
            return (string) $value['text'];
        }

        return (string) $value;
    }

    /**
     * Invalid or missing state cannot prove a source is unchanged, so it behaves like an empty state.
     *
     * @return array<string, string>
     */
    private function readState(string $source, string $target): array
    {
        $disk = Storage::disk($this->disk());
        $path = $this->statePath($source, $target);

        if (! $disk->exists($path)) {
            return [];
        }

        try {
            $state = json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($state)) {
            return [];
        }

        $normalizedState = [];
        foreach ($state as $key => $checksum) {
            if (is_string($checksum)) {
                $normalizedState[(string) $key] = $checksum;
            }
        }

        return $normalizedState;
    }

    private function disk(): string
    {
        return (string) config('ai-translator.state.disk', 'local');
    }

    private function statePath(string $source, string $target): string
    {
        return "ai-translator/state/{$source}/{$target}.json";
    }
}
