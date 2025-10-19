<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

interface StorageInterface
{
    /**
     * Get data from storage.
     * 
     * @param string $key The storage key
     * @return mixed The stored data or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Put data into storage.
     * 
     * @param string $key The storage key
     * @param mixed $value The data to store
     * @param int|null $ttl Time to live in seconds (null for permanent)
     * @return bool Success status
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Check if a key exists in storage.
     * 
     * @param string $key The storage key
     * @return bool Whether the key exists
     */
    public function has(string $key): bool;

    /**
     * Delete data from storage.
     * 
     * @param string $key The storage key
     * @return bool Success status
     */
    public function delete(string $key): bool;

    /**
     * Clear all data from storage.
     * 
     * @return bool Success status
     */
    public function clear(): bool;
}