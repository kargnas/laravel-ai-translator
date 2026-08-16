<?php

namespace Kargnas\LaravelAiTranslator\Translation;

class Validator
{
    /**
     * @return array<int, string> issue messages, empty = valid
     */
    public function validate(string $original, string $translated): array
    {
        $issues = [];
        $issues = [...$issues, ...$this->validateHtml($original, $translated)];
        $issues = [...$issues, ...$this->validateLaravelVariables($original, $translated)];
        $issues = [...$issues, ...$this->validateMustache($original, $translated)];
        $issues = [...$issues, ...$this->validatePrintf($original, $translated)];
        $issues = [...$issues, ...$this->validateLength($original, $translated)];

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function validateHtml(string $original, string $translated): array
    {
        $originalTags = $this->extractTokens($original, '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/');
        $translatedTags = $this->extractTokens($translated, '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b/');

        return $this->compareTokenCounts('html', $originalTags, $translatedTags);
    }

    /**
     * @return array<int, string>
     */
    private function validateLaravelVariables(string $original, string $translated): array
    {
        $originalVariables = array_unique($this->extractTokens($original, '/:\w+/'));
        $translatedVariables = $this->extractTokens($translated, '/:\w+/');
        $issues = [];

        foreach ($originalVariables as $variable) {
            if (! in_array($variable, $translatedVariables, true)) {
                $issues[] = "laravel_variables: missing {$variable}";
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function validateMustache(string $original, string $translated): array
    {
        $originalTokens = array_unique($this->extractTokens($original, '/\{\{[^}]+\}\}/'));
        $translatedTokens = $this->extractTokens($translated, '/\{\{[^}]+\}\}/');
        $issues = [];

        foreach ($originalTokens as $token) {
            if (! in_array($token, $translatedTokens, true)) {
                $issues[] = "mustache: missing {$token}";
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function validatePrintf(string $original, string $translated): array
    {
        $originalPlaceholders = $this->extractTokens($original, '/%[sdifFeEgGxXobBcpn]/');
        $translatedPlaceholders = $this->extractTokens($translated, '/%[sdifFeEgGxXobBcpn]/');

        return $this->compareTokenCounts('printf', $originalPlaceholders, $translatedPlaceholders, 'missing', 'extra');
    }

    /**
     * @return array<int, string>
     */
    private function validateLength(string $original, string $translated): array
    {
        $originalLength = mb_strlen($original);
        $translatedLength = mb_strlen($translated);

        if ($originalLength < 20) {
            return [];
        }

        if ($translatedLength < $originalLength * 0.2) {
            return ["length: translated length {$translatedLength} is below 20% of original length {$originalLength}"];
        }

        if ($translatedLength > $originalLength * 4) {
            return ["length: translated length {$translatedLength} is above 400% of original length {$originalLength}"];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function extractTokens(string $value, string $pattern): array
    {
        preg_match_all($pattern, $value, $matches);
        $tokens = $matches[1] ?? $matches[0] ?? [];
        sort($tokens);

        return $tokens;
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     * @return array<int, string>
     */
    private function compareTokenCounts(
        string $check,
        array $expected,
        array $actual,
        string $missingLabel = 'expected',
        string $extraLabel = 'unexpected'
    ): array {
        $expectedCounts = array_count_values($expected);
        $actualCounts = array_count_values($actual);
        $issues = [];

        ksort($expectedCounts);
        ksort($actualCounts);

        foreach ($expectedCounts as $token => $count) {
            $actualCount = $actualCounts[$token] ?? 0;
            if ($actualCount >= $count) {
                continue;
            }

            $issues[] = "{$check}: {$missingLabel} {$token}";
        }

        foreach ($actualCounts as $token => $count) {
            $expectedCount = $expectedCounts[$token] ?? 0;
            if ($count <= $expectedCount) {
                continue;
            }

            $issues[] = "{$check}: {$extraLabel} {$token}";
        }

        return $issues;
    }
}
