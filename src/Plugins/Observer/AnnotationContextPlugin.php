<?php

namespace Kargnas\LaravelAiTranslator\Plugins\Observer;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Plugins\Abstract\AbstractObserverPlugin;

/**
 * AnnotationContextPlugin - Extracts translation context from PHP docblocks and annotations
 * 
 * Core Responsibilities:
 * - Parses PHP files to extract translation-specific annotations
 * - Supports @translate-context, @translate-style, @translate-glossary annotations
 * - Provides contextual hints to improve translation accuracy
 * - Collects metadata from code comments for better understanding
 * - Integrates with IDE annotations and documentation standards
 * - Supports both PHPDoc and modern PHP 8 attributes
 * 
 * Annotation Support:
 * The plugin recognizes various annotation formats to guide translation:
 * - @translate-context: Provides context about the string usage
 * - @translate-style: Specifies translation style preferences
 * - @translate-glossary: Defines term-specific translations
 * - @translate-note: Additional notes for translators
 * - @translate-max-length: Character/word limits for translations
 * 
 * This improves translation quality by providing the AI with contextual
 * understanding of how and where the translated text will be used.
 */
class AnnotationContextPlugin extends AbstractObserverPlugin
{
    
    protected int $priority = 85; // High priority to extract context early

    /**
     * Get default configuration for annotation parsing
     * 
     * Defines which annotations to recognize and how to process them
     */
    protected function getDefaultConfig(): array
    {
        return [
            'annotations' => [
                'enabled' => true,
                'tags' => [
                    'translate-context' => true,
                    'translate-style' => true,
                    'translate-glossary' => true,
                    'translate-note' => true,
                    'translate-max-length' => true,
                    'translate-domain' => true,
                    'translate-placeholder' => true,
                ],
                'parse_attributes' => true, // PHP 8 attributes
                'parse_inline' => true,      // Inline comments
                'parse_multiline' => true,   // Multiline docblocks
            ],
            'sources' => [
                'scan_files' => true,
                'cache_annotations' => true,
                'cache_ttl' => 3600,
            ],
            'processing' => [
                'merge_duplicates' => true,
                'validate_syntax' => true,
                'extract_examples' => true,
            ],
        ];
    }

    /**
     * Subscribe to pipeline events
     * 
     * Monitors preparation stage to extract and apply annotations
     */
    public function subscribe(): array
    {
        return [
            'stage.preparation.started' => 'extractAnnotations',
            'translation.started' => 'onTranslationStarted',
        ];
    }

    /**
     * Handle translation started event
     * 
     * Prepares annotation extraction for the translation session
     * 
     * @param TranslationContext $context Translation context
     */
    public function onTranslationStarted(TranslationContext $context): void
    {
        if (!$this->getConfigValue('annotations.enabled', true)) {
            return;
        }

        // Initialize plugin data
        $context->setPluginData($this->getName(), [
            'annotations' => [],
            'file_cache' => [],
            'extraction_time' => 0,
        ]);

        $this->debug('Annotation context extraction initialized');
    }

    /**
     * Extract annotations during preparation stage
     * 
     * Responsibilities:
     * - Scan source files for translation annotations
     * - Parse and validate annotation syntax
     * - Apply extracted context to translation metadata
     * - Cache annotations for performance
     * 
     * @param TranslationContext $context Translation context
     */
    public function extractAnnotations(TranslationContext $context): void
    {
        if (!$this->getConfigValue('annotations.enabled', true)) {
            return;
        }

        $startTime = microtime(true);
        $annotations = [];

        // Extract annotations for each text key
        foreach ($context->texts as $key => $text) {
            $keyAnnotations = $this->extractAnnotationsForKey($key, $context);
            if (!empty($keyAnnotations)) {
                $annotations[$key] = $keyAnnotations;
            }
        }

        // Apply annotations to context
        $this->applyAnnotationsToContext($context, $annotations);

        // Store extraction data
        $pluginData = $context->getPluginData($this->getName());
        $pluginData['annotations'] = $annotations;
        $pluginData['extraction_time'] = microtime(true) - $startTime;
        $context->setPluginData($this->getName(), $pluginData);

        $this->info('Annotations extracted', [
            'count' => count($annotations),
            'time' => $pluginData['extraction_time'],
        ]);
    }

    /**
     * Extract annotations for a specific translation key
     * 
     * Responsibilities:
     * - Locate source file containing the key
     * - Parse file for annotations near the key
     * - Extract and validate annotation values
     * - Handle different annotation formats
     * 
     * @param string $key Translation key
     * @param TranslationContext $context Translation context
     * @return array Extracted annotations
     */
    protected function extractAnnotationsForKey(string $key, TranslationContext $context): array
    {
        $annotations = [];
        
        // Find source file containing this key
        $sourceFile = $this->findSourceFile($key, $context);
        if (!$sourceFile || !file_exists($sourceFile)) {
            return $annotations;
        }

        // Check cache first
        $cacheKey = md5($sourceFile . ':' . $key);
        if ($this->getConfigValue('sources.cache_annotations', true)) {
            $cached = $this->getCachedAnnotations($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Parse file for annotations
        $fileContent = file_get_contents($sourceFile);
        
        // Extract annotations near the key
        $annotations = array_merge(
            $this->extractDocblockAnnotations($fileContent, $key),
            $this->extractInlineAnnotations($fileContent, $key),
            $this->extractAttributeAnnotations($fileContent, $key)
        );

        // Cache the result
        if ($this->getConfigValue('sources.cache_annotations', true)) {
            $this->cacheAnnotations($cacheKey, $annotations);
        }

        return $annotations;
    }

    /**
     * Extract docblock annotations from file content
     * 
     * Parses PHPDoc-style comments for translation annotations
     * 
     * @param string $content File content
     * @param string $key Translation key to search near
     * @return array Extracted annotations
     */
    protected function extractDocblockAnnotations(string $content, string $key): array
    {
        if (!$this->getConfigValue('annotations.parse_multiline', true)) {
            return [];
        }

        $annotations = [];
        $pattern = '/\/\*\*\s*\n(.*?)\*\/\s*[\'\"]' . preg_quote($key, '/') . '[\'\"]/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $docblock = $matches[1];
            
            // Parse each annotation tag
            foreach ($this->getEnabledTags() as $tag) {
                $tagPattern = '/@' . preg_quote($tag, '/') . '\s+(.+?)(?:\n|$)/';
                if (preg_match($tagPattern, $docblock, $tagMatch)) {
                    $annotations[$tag] = trim($tagMatch[1]);
                }
            }
        }

        return $annotations;
    }

    /**
     * Extract inline annotations from file content
     * 
     * Parses single-line comments for translation hints
     * 
     * @param string $content File content
     * @param string $key Translation key
     * @return array Extracted annotations
     */
    protected function extractInlineAnnotations(string $content, string $key): array
    {
        if (!$this->getConfigValue('annotations.parse_inline', true)) {
            return [];
        }

        $annotations = [];
        
        // Look for inline comments on the same line as the key
        $pattern = '/[\'\"]' . preg_quote($key, '/') . '[\'\"].*?\/\/\s*@(\w+(?:-\w+)?)\s+(.+?)$/m';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = $match[1];
                $value = trim($match[2]);
                
                if ($this->isTagEnabled($tag)) {
                    $annotations[$tag] = $value;
                }
            }
        }

        return $annotations;
    }

    /**
     * Extract PHP 8 attribute annotations
     * 
     * Parses modern PHP attributes for translation metadata
     * 
     * @param string $content File content
     * @param string $key Translation key
     * @return array Extracted annotations
     */
    protected function extractAttributeAnnotations(string $content, string $key): array
    {
        if (!$this->getConfigValue('annotations.parse_attributes', true)) {
            return [];
        }

        $annotations = [];
        
        // Look for PHP 8 attributes
        $pattern = '/#\[Translate(.*?)\]\s*[\'\"]' . preg_quote($key, '/') . '[\'\"]/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $attributeContent = $matches[1];
            
            // Parse attribute parameters
            if (preg_match_all('/(\w+)\s*[:=]\s*[\'\"](.*?)[\'\"]/', $attributeContent, $params, PREG_SET_ORDER)) {
                foreach ($params as $param) {
                    $paramName = 'translate-' . strtolower(str_replace('_', '-', $param[1]));
                    if ($this->isTagEnabled($paramName)) {
                        $annotations[$paramName] = $param[2];
                    }
                }
            }
        }

        return $annotations;
    }

    /**
     * Apply extracted annotations to translation context
     * 
     * Responsibilities:
     * - Convert annotations to translation metadata
     * - Merge with existing context information
     * - Generate prompts from annotations
     * - Apply style and glossary hints
     * 
     * @param TranslationContext $context Translation context
     * @param array $annotations Extracted annotations by key
     */
    protected function applyAnnotationsToContext(TranslationContext $context, array $annotations): void
    {
        if (empty($annotations)) {
            return;
        }

        // Build context prompts from annotations
        $contextPrompts = [];
        $styleHints = [];
        $glossaryTerms = [];
        $constraints = [];

        foreach ($annotations as $key => $keyAnnotations) {
            // Process context annotations
            if (isset($keyAnnotations['translate-context'])) {
                $contextPrompts[$key] = $keyAnnotations['translate-context'];
            }

            // Process style annotations
            if (isset($keyAnnotations['translate-style'])) {
                $styleHints[$key] = $keyAnnotations['translate-style'];
            }

            // Process glossary annotations
            if (isset($keyAnnotations['translate-glossary'])) {
                $this->parseGlossaryAnnotation($keyAnnotations['translate-glossary'], $glossaryTerms);
            }

            // Process constraints
            if (isset($keyAnnotations['translate-max-length'])) {
                $constraints[$key]['max_length'] = (int)$keyAnnotations['translate-max-length'];
            }

            // Process domain annotations
            if (isset($keyAnnotations['translate-domain'])) {
                $context->metadata['domain'] = $keyAnnotations['translate-domain'];
            }

            // Process placeholder annotations
            if (isset($keyAnnotations['translate-placeholder'])) {
                $constraints[$key]['placeholders'] = $this->parsePlaceholderAnnotation(
                    $keyAnnotations['translate-placeholder']
                );
            }

            // Process notes
            if (isset($keyAnnotations['translate-note'])) {
                $contextPrompts[$key] = ($contextPrompts[$key] ?? '') . 
                                       ' Note: ' . $keyAnnotations['translate-note'];
            }
        }

        // Apply to context metadata
        if (!empty($contextPrompts)) {
            $context->metadata['annotation_context'] = $contextPrompts;
        }

        if (!empty($styleHints)) {
            $context->metadata['style_hints'] = array_merge(
                $context->metadata['style_hints'] ?? [],
                $styleHints
            );
        }

        if (!empty($glossaryTerms)) {
            $context->metadata['annotation_glossary'] = $glossaryTerms;
        }

        if (!empty($constraints)) {
            $context->metadata['translation_constraints'] = $constraints;
        }

        // Generate combined prompt
        $combinedPrompt = $this->generateCombinedPrompt($annotations);
        if ($combinedPrompt) {
            $context->metadata['prompts']['annotations'] = $combinedPrompt;
        }
    }

    /**
     * Parse glossary annotation into terms
     * 
     * Handles format: "term1 => translation1, term2 => translation2"
     * 
     * @param string $annotation Glossary annotation value
     * @param array &$terms Terms array to populate
     */
    protected function parseGlossaryAnnotation(string $annotation, array &$terms): void
    {
        $pairs = explode(',', $annotation);
        
        foreach ($pairs as $pair) {
            if (str_contains($pair, '=>')) {
                [$term, $translation] = array_map('trim', explode('=>', $pair, 2));
                $terms[$term] = $translation;
            }
        }
    }

    /**
     * Parse placeholder annotation
     * 
     * Handles format: ":name:string, :count:number"
     * 
     * @param string $annotation Placeholder annotation
     * @return array Parsed placeholders
     */
    protected function parsePlaceholderAnnotation(string $annotation): array
    {
        $placeholders = [];
        $items = explode(',', $annotation);
        
        foreach ($items as $item) {
            if (str_contains($item, ':')) {
                $parts = explode(':', trim($item));
                if (count($parts) >= 2) {
                    $name = trim($parts[1]);
                    $type = isset($parts[2]) ? trim($parts[2]) : 'string';
                    $placeholders[$name] = $type;
                }
            }
        }
        
        return $placeholders;
    }

    /**
     * Generate combined prompt from all annotations
     * 
     * Creates a comprehensive prompt for the translation engine
     * 
     * @param array $annotations All extracted annotations
     * @return string Combined prompt
     */
    protected function generateCombinedPrompt(array $annotations): string
    {
        $prompts = [];
        
        foreach ($annotations as $key => $keyAnnotations) {
            $keyPrompts = [];
            
            if (isset($keyAnnotations['translate-context'])) {
                $keyPrompts[] = "Context: " . $keyAnnotations['translate-context'];
            }
            
            if (isset($keyAnnotations['translate-style'])) {
                $keyPrompts[] = "Style: " . $keyAnnotations['translate-style'];
            }
            
            if (isset($keyAnnotations['translate-max-length'])) {
                $keyPrompts[] = "Max length: " . $keyAnnotations['translate-max-length'] . " characters";
            }
            
            if (!empty($keyPrompts)) {
                $prompts[] = "For '{$key}': " . implode(', ', $keyPrompts);
            }
        }
        
        return !empty($prompts) ? implode("\n", $prompts) : '';
    }

    /**
     * Find source file containing a translation key
     * 
     * @param string $key Translation key
     * @param TranslationContext $context Translation context
     * @return string|null File path or null if not found
     */
    protected function findSourceFile(string $key, TranslationContext $context): ?string
    {
        // Check if source file is provided in metadata
        if (isset($context->metadata['source_files'][$key])) {
            return $context->metadata['source_files'][$key];
        }

        // Try to find in standard Laravel language directories
        $possiblePaths = [
            base_path('lang/en.php'),
            base_path('lang/en/' . str_replace('.', '/', $key) . '.php'),
            resource_path('lang/en.php'),
            resource_path('lang/en/' . str_replace('.', '/', $key) . '.php'),
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                // Verify the key exists in this file
                $content = file_get_contents($path);
                if (str_contains($content, "'{$key}'") || str_contains($content, "\"{$key}\"")) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Get enabled annotation tags
     * 
     * @return array List of enabled tags
     */
    protected function getEnabledTags(): array
    {
        $tags = $this->getConfigValue('annotations.tags', []);
        return array_keys(array_filter($tags));
    }

    /**
     * Check if a tag is enabled
     * 
     * @param string $tag Tag name
     * @return bool Whether tag is enabled
     */
    protected function isTagEnabled(string $tag): bool
    {
        $tags = $this->getConfigValue('annotations.tags', []);
        return $tags[$tag] ?? false;
    }

    /**
     * Get cached annotations
     * 
     * @param string $cacheKey Cache key
     * @return array|null Cached annotations or null
     */
    protected function getCachedAnnotations(string $cacheKey): ?array
    {
        // Simple in-memory cache for this session
        // In production, this would use Laravel's cache
        static $cache = [];
        
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        return null;
    }

    /**
     * Cache annotations
     * 
     * @param string $cacheKey Cache key
     * @param array $annotations Annotations to cache
     */
    protected function cacheAnnotations(string $cacheKey, array $annotations): void
    {
        // Simple in-memory cache
        static $cache = [];
        $cache[$cacheKey] = $annotations;
    }
}