<?php

namespace Kargnas\LaravelAiTranslator\Core;

/**
 * PipelineStages - Defines core pipeline stage constants
 * 
 * Core Responsibilities:
 * - Provides constants for core pipeline stages only
 * - Ensures consistency across core functionality
 * - Documents the purpose and order of core stages
 * - Prevents typos and magic strings in core stage references
 * 
 * Note: This class only defines core stages required by the framework.
 * Plugins can define and use their own custom stages as needed.
 * 
 * Core Stage Execution Order:
 * 1. PRE_PROCESS - Initial request validation and setup
 * 2. DIFF_DETECTION - Detect changes from previous translations
 * 3. PREPARATION - Prepare texts for translation
 * 4. CHUNKING - Split texts into optimal chunks
 * 5. TRANSLATION - Perform actual translation
 * 6. CONSENSUS - Resolve conflicts between multiple providers
 * 7. VALIDATION - Validate translation quality
 * 8. POST_PROCESS - Final processing and cleanup
 * 9. OUTPUT - Format and return results
 */
final class PipelineStages
{
    /**
     * Pre-processing stage
     * 
     * Purpose: Initial request validation, setup, and configuration
     * Typical operations:
     * - Validate input parameters
     * - Load configurations
     * - Initialize context
     * - Apply security checks
     */
    public const PRE_PROCESS = 'pre_process';

    /**
     * Diff detection stage
     * 
     * Purpose: Detect changes from previous translations
     * Typical operations:
     * - Load previous translation state
     * - Compare checksums
     * - Filter unchanged texts
     * - Apply cached translations
     */
    public const DIFF_DETECTION = 'diff_detection';

    /**
     * Preparation stage
     * 
     * Purpose: Prepare texts for translation
     * Typical operations:
     * - Extract annotations
     * - Apply glossary preprocessing
     * - Mask sensitive data
     * - Normalize formats
     */
    public const PREPARATION = 'preparation';

    /**
     * Chunking stage
     * 
     * Purpose: Split texts into optimal chunks for API calls
     * Typical operations:
     * - Estimate token counts
     * - Group related texts
     * - Balance chunk sizes
     * - Maintain context boundaries
     */
    public const CHUNKING = 'chunking';

    /**
     * Translation stage
     * 
     * Purpose: Perform actual translation via AI providers
     * Typical operations:
     * - Call AI translation APIs
     * - Handle provider-specific logic
     * - Manage retries and fallbacks
     * - Collect token usage
     */
    public const TRANSLATION = 'translation';

    /**
     * Consensus stage
     * 
     * Purpose: Resolve conflicts between multiple providers
     * Typical operations:
     * - Compare translations
     * - Apply voting algorithms
     * - Select best translations
     * - Merge provider results
     */
    public const CONSENSUS = 'consensus';

    /**
     * Validation stage
     * 
     * Purpose: Validate translation quality and correctness
     * Typical operations:
     * - Check variable preservation
     * - Validate HTML structure
     * - Verify pluralization
     * - Ensure glossary compliance
     */
    public const VALIDATION = 'validation';

    /**
     * Post-processing stage
     * 
     * Purpose: Final processing and cleanup
     * Typical operations:
     * - Unmask sensitive data
     * - Apply style formatting
     * - Restore annotations
     * - Final adjustments
     */
    public const POST_PROCESS = 'post_process';

    /**
     * Output stage
     * 
     * Purpose: Format and return results
     * Typical operations:
     * - Format response structure
     * - Generate metadata
     * - Create audit logs
     * - Stream results
     */
    public const OUTPUT = 'output';

    /**
     * Get all stages in execution order
     * 
     * @return array<string> Ordered list of stage constants
     */
    public static function all(): array
    {
        return [
            self::PRE_PROCESS,
            self::DIFF_DETECTION,
            self::PREPARATION,
            self::CHUNKING,
            self::TRANSLATION,
            self::CONSENSUS,
            self::VALIDATION,
            self::POST_PROCESS,
            self::OUTPUT,
        ];
    }

    /**
     * Check if a stage name is valid
     * 
     * @param string $stage Stage name to validate
     * @return bool True if stage is valid
     */
    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::all(), true);
    }

    /**
     * Get the index of a stage in the execution order
     * 
     * @param string $stage Stage name
     * @return int Stage index, or -1 if not found
     */
    public static function getIndex(string $stage): int
    {
        $index = array_search($stage, self::all(), true);
        return $index !== false ? $index : -1;
    }

    /**
     * Check if one stage comes before another
     * 
     * @param string $stage1 First stage
     * @param string $stage2 Second stage
     * @return bool True if stage1 comes before stage2
     */
    public static function isBefore(string $stage1, string $stage2): bool
    {
        $index1 = self::getIndex($stage1);
        $index2 = self::getIndex($stage2);
        
        if ($index1 === -1 || $index2 === -1) {
            return false;
        }
        
        return $index1 < $index2;
    }

    /**
     * Check if one stage comes after another
     * 
     * @param string $stage1 First stage
     * @param string $stage2 Second stage
     * @return bool True if stage1 comes after stage2
     */
    public static function isAfter(string $stage1, string $stage2): bool
    {
        $index1 = self::getIndex($stage1);
        $index2 = self::getIndex($stage2);
        
        if ($index1 === -1 || $index2 === -1) {
            return false;
        }
        
        return $index1 > $index2;
    }

    /**
     * Get the next stage in the pipeline
     * 
     * @param string $stage Current stage
     * @return string|null Next stage or null if last stage
     */
    public static function getNext(string $stage): ?string
    {
        $index = self::getIndex($stage);
        
        if ($index === -1) {
            return null;
        }
        
        $stages = self::all();
        $nextIndex = $index + 1;
        
        return $nextIndex < count($stages) ? $stages[$nextIndex] : null;
    }

    /**
     * Get the previous stage in the pipeline
     * 
     * @param string $stage Current stage
     * @return string|null Previous stage or null if first stage
     */
    public static function getPrevious(string $stage): ?string
    {
        $index = self::getIndex($stage);
        
        if ($index <= 0) {
            return null;
        }
        
        $stages = self::all();
        return $stages[$index - 1];
    }
}