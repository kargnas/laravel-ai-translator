<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationOutput;
use Generator;

class TokenChunkingPlugin extends AbstractMiddlewarePlugin
{
    protected string $name = 'token_chunking';
    
    protected int $priority = 100;

    /**
     * Default configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'max_tokens_per_chunk' => 2000,
            'estimation_multipliers' => [
                'cjk' => 1.5,      // Chinese, Japanese, Korean
                'arabic' => 0.8,    // Arabic scripts
                'cyrillic' => 0.7,  // Cyrillic scripts
                'latin' => 0.25,    // Latin scripts (default)
                'devanagari' => 1.0, // Hindi, Sanskrit
                'thai' => 1.2,      // Thai script
            ],
            'buffer_percentage' => 0.9, // Use 90% of max tokens for safety
        ];
    }

    /**
     * Get the pipeline stage
     */
    protected function getStage(): string
    {
        return 'chunking';
    }

    /**
     * Handle the chunking process
     */
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        if ($this->shouldSkip($context)) {
            return $this->passThrough($context, $next);
        }

        // Get configuration
        $maxTokens = $this->getConfigValue('max_tokens_per_chunk', 2000);
        $bufferPercentage = $this->getConfigValue('buffer_percentage', 0.9);
        $effectiveMaxTokens = (int)($maxTokens * $bufferPercentage);

        // Chunk the texts
        $chunks = $this->createChunks($context->texts, $effectiveMaxTokens);
        
        // Store original texts and replace with chunks
        $originalTexts = $context->texts;
        $context->setPluginData($this->getName(), [
            'original_texts' => $originalTexts,
            'chunks' => $chunks,
            'current_chunk' => 0,
            'total_chunks' => count($chunks),
        ]);

        // Process each chunk
        $allResults = [];
        foreach ($chunks as $chunkIndex => $chunk) {
            $context->texts = $chunk;
            $context->metadata['chunk_info'] = [
                'current' => $chunkIndex + 1,
                'total' => count($chunks),
                'size' => count($chunk),
            ];

            $totalChunks = count($chunks);
            $this->debug("Processing chunk {$chunkIndex}/{$totalChunks}", [
                'chunk_size' => count($chunk),
                'estimated_tokens' => $this->estimateTokens($chunk),
            ]);

            // Process the chunk through the pipeline
            $result = $next($context);
            
            // Collect results
            if ($result instanceof Generator) {
                foreach ($result as $output) {
                    $allResults[] = $output;
                    yield $output;
                }
            } else {
                $allResults[] = $result;
            }
        }

        // Restore original texts
        $context->texts = $originalTexts;
        
        return $allResults;
    }

    /**
     * Create chunks based on token estimation
     */
    protected function createChunks(array $texts, int $maxTokens): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentTokens = 0;

        foreach ($texts as $key => $text) {
            $estimatedTokens = $this->estimateTokensForText($text);
            
            // If single text exceeds max tokens, split it
            if ($estimatedTokens > $maxTokens) {
                // Save current chunk if not empty
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                    $currentChunk = [];
                    $currentTokens = 0;
                }
                
                // Split the large text
                $splitTexts = $this->splitLargeText($key, $text, $maxTokens);
                foreach ($splitTexts as $splitChunk) {
                    $chunks[] = $splitChunk;
                }
                continue;
            }
            
            // Check if adding this text would exceed the limit
            if ($currentTokens + $estimatedTokens > $maxTokens && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentTokens = 0;
            }
            
            $currentChunk[$key] = $text;
            $currentTokens += $estimatedTokens;
        }
        
        // Add remaining chunk
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }

    /**
     * Split a large text into smaller chunks
     */
    protected function splitLargeText(string $key, string $text, int $maxTokens): array
    {
        $chunks = [];
        $sentences = $this->splitIntoSentences($text);
        $currentChunk = [];
        $currentTokens = 0;
        $chunkIndex = 0;

        foreach ($sentences as $sentence) {
            $estimatedTokens = $this->estimateTokensForText($sentence);
            
            if ($currentTokens + $estimatedTokens > $maxTokens && !empty($currentChunk)) {
                $chunks[] = ["{$key}_part_{$chunkIndex}" => implode(' ', $currentChunk)];
                $currentChunk = [];
                $currentTokens = 0;
                $chunkIndex++;
            }
            
            $currentChunk[] = $sentence;
            $currentTokens += $estimatedTokens;
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = ["{$key}_part_{$chunkIndex}" => implode(' ', $currentChunk)];
        }
        
        return $chunks;
    }

    /**
     * Split text into sentences
     */
    protected function splitIntoSentences(string $text): array
    {
        // Simple sentence splitting (can be improved with better NLP)
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        if (empty($sentences)) {
            // Fallback to splitting by newlines
            $sentences = explode("\n", $text);
        }
        
        return array_filter($sentences);
    }

    /**
     * Estimate tokens for an array of texts
     */
    protected function estimateTokens(array $texts): int
    {
        $total = 0;
        foreach ($texts as $text) {
            $total += $this->estimateTokensForText($text);
        }
        return $total;
    }

    /**
     * Estimate tokens for a single text
     */
    protected function estimateTokensForText(string $text): int
    {
        $scriptType = $this->detectScriptType($text);
        $multipliers = $this->getConfigValue('estimation_multipliers', []);
        $multiplier = $multipliers[$scriptType] ?? 0.25;
        
        // Basic estimation: character count * multiplier
        $charCount = mb_strlen($text);
        
        // Add overhead for structure (keys, formatting)
        $overhead = 20;
        
        return (int)($charCount * $multiplier) + $overhead;
    }

    /**
     * Detect the predominant script type in text
     */
    protected function detectScriptType(string $text): string
    {
        $scripts = [
            'cjk' => '/[\x{4E00}-\x{9FFF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{AC00}-\x{D7AF}]/u',
            'arabic' => '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u',
            'cyrillic' => '/[\x{0400}-\x{04FF}]/u',
            'devanagari' => '/[\x{0900}-\x{097F}]/u',
            'thai' => '/[\x{0E00}-\x{0E7F}]/u',
        ];
        
        $counts = [];
        foreach ($scripts as $name => $pattern) {
            preg_match_all($pattern, $text, $matches);
            $counts[$name] = count($matches[0]);
        }
        
        // Return script with most matches
        arsort($counts);
        $topScript = key($counts);
        
        // If no significant non-Latin script found, assume Latin
        if ($counts[$topScript] < mb_strlen($text) * 0.3) {
            return 'latin';
        }
        
        return $topScript;
    }

    /**
     * Merge chunked results back
     */
    public function terminate(TranslationContext $context, mixed $response): void
    {
        $pluginData = $context->getPluginData($this->getName());
        
        if (!$pluginData || !isset($pluginData['chunks'])) {
            return;
        }

        // Merge translations from all chunks
        $mergedTranslations = [];
        foreach ($context->translations as $locale => $translations) {
            foreach ($translations as $key => $value) {
                // Handle split text parts
                if (preg_match('/^(.+)_part_\d+$/', $key, $matches)) {
                    $originalKey = $matches[1];
                    if (!isset($mergedTranslations[$locale][$originalKey])) {
                        $mergedTranslations[$locale][$originalKey] = '';
                    }
                    $mergedTranslations[$locale][$originalKey] .= ' ' . $value;
                } else {
                    $mergedTranslations[$locale][$key] = $value;
                }
            }
        }

        // Clean up merged translations
        foreach ($mergedTranslations as $locale => &$translations) {
            foreach ($translations as &$translation) {
                $translation = trim($translation);
            }
        }

        $context->translations = $mergedTranslations;
    }
}