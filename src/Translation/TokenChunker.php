<?php

namespace Kargnas\LaravelAiTranslator\Translation;

class TokenChunker
{
    public function chunk(array $strings, int $maxTokens): array
    {
        $tokenBudget = (int) floor($maxTokens * 0.9);
        $chunks = [];
        $currentChunk = [];
        $currentTokens = 0;

        foreach ($strings as $key => $value) {
            $itemTokens = $this->estimateTokens($this->textFromValue($value));

            if (! empty($currentChunk) && $currentTokens + $itemTokens > $tokenBudget) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentTokens = 0;
            }

            if ($itemTokens > $tokenBudget) {
                $chunks[] = [$key => $value];

                continue;
            }

            $currentChunk[$key] = $value;
            $currentTokens += $itemTokens;
        }

        if (! empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    public function estimateTokens(string $text): int
    {
        $scriptPatterns = [
            '/[\x{4E00}-\x{9FFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}]/u' => 1.5,
            '/[\x{0E00}-\x{0E7F}]/u' => 1.2,
            '/[\x{0900}-\x{097F}]/u' => 1.0,
            '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u' => 0.8,
            '/[\x{0400}-\x{04FF}]/u' => 0.7,
        ];
        $length = mb_strlen($text);
        $topCount = 0;
        $multiplier = 0.25;

        foreach ($scriptPatterns as $pattern => $scriptMultiplier) {
            $count = preg_match_all($pattern, $text);
            if ($count !== false && $count > $topCount) {
                $topCount = $count;
                $multiplier = $scriptMultiplier;
            }
        }

        if ($length === 0 || $topCount < $length * 0.3) {
            $multiplier = 0.25;
        }

        return (int) ceil($length * $multiplier) + 20;
    }

    private function textFromValue(mixed $value): string
    {
        return is_array($value) ? (string) ($value['text'] ?? '') : (string) $value;
    }
}
