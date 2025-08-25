<?php

namespace Kargnas\LaravelAiTranslator\Core;

/**
 * PipelineStages - Defines essential pipeline stage constants
 * 
 * This class provides constants for only the most essential stages
 * that are fundamental to the translation pipeline.
 * 
 * Philosophy:
 * - Only truly essential stages are defined as constants
 * - Most stages should be defined as strings for flexibility
 * - Plugins can freely add/remove/modify non-essential stages
 * 
 * Essential Stages:
 * - TRANSLATION: The core translation process (required)
 * - VALIDATION: Quality assurance checks (highly recommended)
 * - OUTPUT: Final result formatting (required for results)
 * 
 * Common Stage Names (use as strings):
 * - 'pre_process': Initial validation and setup
 * - 'diff_detection': Track changes between translations
 * - 'preparation': Prepare texts (glossary, masking, etc.)
 * - 'chunking': Split texts for API limits
 * - 'consensus': Merge results from multiple providers
 * - 'post_process': Final cleanup and adjustments
 * 
 * Plugins are encouraged to use descriptive string names for custom stages:
 * - 'metrics_collection'
 * - 'rate_limiting'
 * - 'cache_lookup'
 * - 'notification'
 * - etc.
 */
final class PipelineStages
{

    /**
     * Translation stage - ESSENTIAL
     * 
     * The core translation process where actual API calls are made.
     * This is the heart of the translation pipeline.
     */
    public const TRANSLATION = 'translation';


    /**
     * Validation stage - ESSENTIAL
     * 
     * Quality assurance and correctness verification.
     * Critical for ensuring translation accuracy.
     */
    public const VALIDATION = 'validation';


    /**
     * Output stage - ESSENTIAL
     * 
     * Final result formatting and delivery.
     * Required for returning results to the caller.
     */
    public const OUTPUT = 'output';

    /**
     * Get essential stages
     * 
     * @return array<string> List of essential stage constants
     */
    public static function essentials(): array
    {
        return [
            self::TRANSLATION,
            self::VALIDATION,
            self::OUTPUT,
        ];
    }
    
    /**
     * Get commonly used stage names (as strings)
     * 
     * These are provided for reference but should be used as strings,
     * not constants, to maintain flexibility.
     * 
     * @return array<string> Common stage names in typical execution order
     */
    public static function common(): array
    {
        return [
            'pre_process',
            'diff_detection',
            'preparation',
            'chunking',
            self::TRANSLATION,  // Essential
            'consensus',
            self::VALIDATION,   // Essential
            'post_process',
            self::OUTPUT,       // Essential
        ];
    }

    /**
     * Check if a stage is essential
     * 
     * @param string $stage Stage name to check
     * @return bool True if stage is essential
     */
    public static function isEssential(string $stage): bool
    {
        return in_array($stage, self::essentials(), true);
    }
}