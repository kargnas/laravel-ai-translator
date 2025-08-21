<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;

/**
 * GlossaryPlugin - Manages terminology consistency across translations
 * 
 * Primary Responsibilities:
 * - Maintains a glossary of terms that must be translated consistently
 * - Supports domain-specific terminology (legal, medical, technical, etc.)
 * - Handles brand names and product terms that should not be translated
 * - Manages bidirectional glossaries for multiple language pairs
 * - Provides context-aware term replacement based on usage
 * - Supports fuzzy matching for term variations (plural, case, etc.)
 * 
 * Glossary Management:
 * The plugin maintains an in-memory glossary that can be loaded from
 * various sources (database, files, API) and applies term replacements
 * during the preparation stage to ensure consistency.
 * 
 * Use Cases:
 * 1. Ensuring company-specific terms are translated consistently
 * 2. Maintaining technical terminology accuracy
 * 3. Preserving brand names and trademarks
 * 4. Applying industry-standard translations
 */
class GlossaryPlugin extends AbstractProviderPlugin
{
    
    protected int $priority = 80; // High priority to apply early

    /**
     * Get default glossary configuration
     * 
     * Provides default settings for glossary behavior and term sources
     */
    protected function getDefaultConfig(): array
    {
        return [
            'glossary' => [],
            'sources' => [
                'memory' => true,    // In-memory glossary
                'database' => false, // Database-backed glossary
                'file' => null,      // File path for glossary
                'api' => null,       // API endpoint for glossary
            ],
            'options' => [
                'case_sensitive' => false,
                'match_whole_words' => true,
                'apply_to_source' => true,
                'apply_to_target' => false,
                'fuzzy_matching' => true,
                'preserve_untranslated' => [],
            ],
            'domains' => [
                'general' => [],
                'technical' => [],
                'legal' => [],
                'medical' => [],
                'business' => [],
            ],
        ];
    }

    /**
     * Declare provided services
     * 
     * This plugin provides glossary application service
     */
    public function provides(): array
    {
        return ['glossary.application'];
    }

    /**
     * Specify when this provider should be active
     * 
     * Glossary should be applied during preparation stage
     */
    public function when(): array
    {
        return ['preparation'];
    }

    /**
     * Execute glossary application on translation context
     * 
     * Responsibilities:
     * - Load glossary from configured sources
     * - Apply term replacements to source texts
     * - Mark terms that should not be translated
     * - Store glossary metadata for validation
     * - Handle domain-specific terminology
     * 
     * @param TranslationContext $context Translation context
     * @return array Applied glossary information
     */
    public function execute(TranslationContext $context): mixed
    {
        $glossary = $this->loadGlossary($context);
        $targetLocales = $context->request->getTargetLocales();
        
        if (empty($glossary)) {
            $this->debug('No glossary terms to apply');
            return ['applied' => 0];
        }
        
        // Apply glossary to texts
        $appliedTerms = $this->applyGlossary($context, $glossary, $targetLocales);
        
        // Store glossary data for later reference
        $context->setPluginData($this->getName(), [
            'glossary' => $glossary,
            'applied_terms' => $appliedTerms,
            'preserve_terms' => $this->getPreserveTerms($glossary),
        ]);
        
        $this->info("Applied {$appliedTerms} glossary terms", [
            'total_terms' => count($glossary),
            'locales' => $targetLocales,
        ]);
        
        return [
            'applied' => $appliedTerms,
            'glossary_size' => count($glossary),
        ];
    }

    /**
     * Load glossary from all configured sources
     * 
     * Responsibilities:
     * - Merge glossaries from multiple sources
     * - Handle source-specific loading logic
     * - Validate glossary format
     * - Apply domain filtering if specified
     * 
     * @param TranslationContext $context Translation context
     * @return array Loaded glossary terms
     */
    protected function loadGlossary(TranslationContext $context): array
    {
        $glossary = [];
        $sources = $this->getConfigValue('sources', []);
        
        // Load from memory (configuration)
        if ($sources['memory'] ?? true) {
            $memoryGlossary = $this->getConfigValue('glossary', []);
            $glossary = array_merge($glossary, $memoryGlossary);
        }
        
        // Load from database
        if ($sources['database'] ?? false) {
            $dbGlossary = $this->loadFromDatabase($context);
            $glossary = array_merge($glossary, $dbGlossary);
        }
        
        // Load from file
        if ($filePath = $sources['file'] ?? null) {
            $fileGlossary = $this->loadFromFile($filePath);
            $glossary = array_merge($glossary, $fileGlossary);
        }
        
        // Load from API
        if ($apiEndpoint = $sources['api'] ?? null) {
            $apiGlossary = $this->loadFromApi($apiEndpoint, $context);
            $glossary = array_merge($glossary, $apiGlossary);
        }
        
        // Apply domain filtering
        $domain = $context->metadata['domain'] ?? 'general';
        $domainGlossary = $this->getDomainGlossary($domain);
        $glossary = array_merge($glossary, $domainGlossary);
        
        return $this->normalizeGlossary($glossary);
    }

    /**
     * Apply glossary terms to translation context
     * 
     * Responsibilities:
     * - Replace or mark glossary terms in source texts
     * - Handle term variations (plural, case)
     * - Track which terms were applied
     * - Generate hints for translation engine
     * 
     * @param TranslationContext $context Translation context
     * @param array $glossary Glossary terms
     * @param array $targetLocales Target locales
     * @return int Number of terms applied
     */
    protected function applyGlossary(TranslationContext $context, array $glossary, array $targetLocales): int
    {
        $appliedCount = 0;
        $options = $this->getConfigValue('options', []);
        $caseSensitive = $options['case_sensitive'] ?? false;
        $wholeWords = $options['match_whole_words'] ?? true;
        
        // Build glossary hints for translation
        $glossaryHints = [];
        
        foreach ($context->texts as $key => &$text) {
            $appliedTerms = [];
            
            foreach ($glossary as $term => $translations) {
                // Check if term exists in text
                if ($this->termExists($text, $term, $caseSensitive, $wholeWords)) {
                    $appliedTerms[$term] = $translations;
                    $appliedCount++;
                    
                    // Mark term for preservation if needed
                    if ($this->shouldPreserveTerm($term, $translations)) {
                        $text = $this->markTermForPreservation($text, $term);
                    }
                }
            }
            
            if (!empty($appliedTerms)) {
                $glossaryHints[$key] = $this->buildGlossaryHint($appliedTerms, $targetLocales);
            }
        }
        
        // Add glossary hints to metadata
        if (!empty($glossaryHints)) {
            $context->metadata['glossary_hints'] = $glossaryHints;
        }
        
        return $appliedCount;
    }

    /**
     * Check if a term exists in text
     * 
     * @param string $text Text to search
     * @param string $term Term to find
     * @param bool $caseSensitive Case sensitive search
     * @param bool $wholeWords Match whole words only
     * @return bool Whether term exists
     */
    protected function termExists(string $text, string $term, bool $caseSensitive, bool $wholeWords): bool
    {
        if ($wholeWords) {
            $pattern = '/\b' . preg_quote($term, '/') . '\b/';
            if (!$caseSensitive) {
                $pattern .= 'i';
            }
            return preg_match($pattern, $text) > 0;
        } else {
            if ($caseSensitive) {
                return str_contains($text, $term);
            } else {
                return stripos($text, $term) !== false;
            }
        }
    }

    /**
     * Build glossary hint for translation engine
     * 
     * Creates a structured hint that helps the AI understand
     * how specific terms should be translated
     * 
     * @param array $terms Applied terms and their translations
     * @param array $targetLocales Target locales
     * @return string Formatted glossary hint
     */
    protected function buildGlossaryHint(array $terms, array $targetLocales): string
    {
        $hints = [];
        
        foreach ($targetLocales as $locale) {
            $localeHints = [];
            foreach ($terms as $term => $translations) {
                if (isset($translations[$locale])) {
                    $localeHints[] = "'{$term}' => '{$translations[$locale]}'";
                } elseif (isset($translations['*'])) {
                    // Universal translation or preservation
                    $localeHints[] = "'{$term}' => '{$translations['*']}'";
                }
            }
            
            if (!empty($localeHints)) {
                $hints[] = "{$locale}: " . implode(', ', $localeHints);
            }
        }
        
        return "Glossary terms: " . implode('; ', $hints);
    }

    /**
     * Load glossary from database
     * 
     * @param TranslationContext $context Translation context
     * @return array Database glossary terms
     */
    protected function loadFromDatabase(TranslationContext $context): array
    {
        // This would load from actual database
        // Example implementation:
        try {
            if (class_exists('\\App\\Models\\GlossaryTerm')) {
                $terms = \App\Models\GlossaryTerm::query()
                    ->where('active', true)
                    ->when($context->request->tenantId, function ($query, $tenantId) {
                        $query->where('tenant_id', $tenantId);
                    })
                    ->get();
                
                $glossary = [];
                foreach ($terms as $term) {
                    $glossary[$term->source] = json_decode($term->translations, true);
                }
                
                return $glossary;
            }
        } catch (\Exception $e) {
            $this->warning('Failed to load glossary from database: ' . $e->getMessage());
        }
        
        return [];
    }

    /**
     * Load glossary from file
     * 
     * Supports JSON, CSV, and PHP array formats
     * 
     * @param string $filePath Path to glossary file
     * @return array File glossary terms
     */
    protected function loadFromFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            $this->warning("Glossary file not found: {$filePath}");
            return [];
        }
        
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        try {
            switch ($extension) {
                case 'json':
                    $content = file_get_contents($filePath);
                    return json_decode($content, true) ?: [];
                    
                case 'csv':
                    return $this->loadFromCsv($filePath);
                    
                case 'php':
                    return include $filePath;
                    
                default:
                    $this->warning("Unsupported glossary file format: {$extension}");
                    return [];
            }
        } catch (\Exception $e) {
            $this->error("Failed to load glossary from file: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load glossary from CSV file
     * 
     * Expected format: source,target_locale,translation
     * 
     * @param string $filePath CSV file path
     * @return array Parsed glossary
     */
    protected function loadFromCsv(string $filePath): array
    {
        $glossary = [];
        
        if (($handle = fopen($filePath, 'r')) !== false) {
            // Skip header if present
            $header = fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 3) {
                    $source = $data[0];
                    $locale = $data[1];
                    $translation = $data[2];
                    
                    if (!isset($glossary[$source])) {
                        $glossary[$source] = [];
                    }
                    $glossary[$source][$locale] = $translation;
                }
            }
            
            fclose($handle);
        }
        
        return $glossary;
    }

    /**
     * Load glossary from API
     * 
     * @param string $endpoint API endpoint
     * @param TranslationContext $context Translation context
     * @return array API glossary terms
     */
    protected function loadFromApi(string $endpoint, TranslationContext $context): array
    {
        try {
            // This would make actual API call
            // Example with Laravel HTTP client:
            if (class_exists('\\Illuminate\\Support\\Facades\\Http')) {
                $response = \Illuminate\Support\Facades\Http::get($endpoint, [
                    'source_locale' => $context->request->sourceLocale,
                    'target_locales' => $context->request->getTargetLocales(),
                    'domain' => $context->metadata['domain'] ?? 'general',
                ]);
                
                if ($response->successful()) {
                    return $response->json() ?: [];
                }
            }
        } catch (\Exception $e) {
            $this->warning('Failed to load glossary from API: ' . $e->getMessage());
        }
        
        return [];
    }

    /**
     * Get domain-specific glossary
     * 
     * @param string $domain Domain name
     * @return array Domain glossary terms
     */
    protected function getDomainGlossary(string $domain): array
    {
        $domains = $this->getConfigValue('domains', []);
        return $domains[$domain] ?? [];
    }

    /**
     * Normalize glossary format
     * 
     * Ensures consistent glossary structure regardless of source
     * 
     * @param array $glossary Raw glossary data
     * @return array Normalized glossary
     */
    protected function normalizeGlossary(array $glossary): array
    {
        $normalized = [];
        
        foreach ($glossary as $key => $value) {
            if (is_string($value)) {
                // Simple string translation
                $normalized[$key] = ['*' => $value];
            } elseif (is_array($value)) {
                // Already structured
                $normalized[$key] = $value;
            }
        }
        
        return $normalized;
    }

    /**
     * Check if term should be preserved (not translated)
     * 
     * @param string $term Source term
     * @param array $translations Term translations
     * @return bool Whether to preserve
     */
    protected function shouldPreserveTerm(string $term, array $translations): bool
    {
        // Check if term has a universal preservation marker
        if (isset($translations['*']) && $translations['*'] === $term) {
            return true;
        }
        
        // Check preserve list
        $preserveList = $this->getConfigValue('options.preserve_untranslated', []);
        return in_array($term, $preserveList, true);
    }

    /**
     * Mark term for preservation in text
     * 
     * Adds special markers that the translation engine will recognize
     * 
     * @param string $text Source text
     * @param string $term Term to preserve
     * @return string Text with marked term
     */
    protected function markTermForPreservation(string $text, string $term): string
    {
        // Use a special marker that translation engine will preserve
        $marker = "[[PRESERVE:{$term}]]";
        
        $pattern = '/\b' . preg_quote($term, '/') . '\b/i';
        return preg_replace($pattern, $marker, $text);
    }

    /**
     * Get terms that should be preserved
     * 
     * @param array $glossary Glossary terms
     * @return array Terms to preserve
     */
    protected function getPreserveTerms(array $glossary): array
    {
        $preserveTerms = [];
        
        foreach ($glossary as $term => $translations) {
            if ($this->shouldPreserveTerm($term, $translations)) {
                $preserveTerms[] = $term;
            }
        }
        
        return $preserveTerms;
    }

    /**
     * Add glossary term dynamically
     * 
     * @param string $source Source term
     * @param array|string $translations Translations by locale
     */
    public function addTerm(string $source, array|string $translations): void
    {
        $glossary = $this->getConfigValue('glossary', []);
        
        if (is_string($translations)) {
            $translations = ['*' => $translations];
        }
        
        $glossary[$source] = $translations;
        $this->configure(['glossary' => $glossary]);
    }

    /**
     * Remove glossary term
     * 
     * @param string $source Source term to remove
     */
    public function removeTerm(string $source): void
    {
        $glossary = $this->getConfigValue('glossary', []);
        unset($glossary[$source]);
        $this->configure(['glossary' => $glossary]);
    }

    /**
     * Get current glossary
     * 
     * @return array Current glossary terms
     */
    public function getGlossary(): array
    {
        return $this->getConfigValue('glossary', []);
    }
}