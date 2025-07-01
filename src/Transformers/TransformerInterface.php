<?php

namespace Kargnas\LaravelAiTranslator\Transformers;

interface TransformerInterface
{
    /**
     * Parse a language file and return its contents as an array
     *
     * @param string $file The file path to parse
     * @return array The parsed contents
     */
    public function parse(string $file): array;

    /**
     * Save the language data to a file
     *
     * @param string $file The file path to save to
     * @param array $data The data to save
     * @return void
     */
    public function save(string $file, array $data): void;

    /**
     * Flatten a nested array into dot notation
     *
     * @param array $array The array to flatten
     * @param string $prefix The prefix for keys
     * @return array The flattened array
     */
    public function flatten(array $array, string $prefix = ''): array;

    /**
     * Unflatten a dot notation key-value pair back into a nested array
     *
     * @param array $array The existing array
     * @param string $key The dot notation key
     * @param string $value The value to set
     * @return array The updated array
     */
    public function unflatten(array $array, string $key, string $value): array;
}