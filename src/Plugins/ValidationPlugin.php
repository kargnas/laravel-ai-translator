<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\PipelineStages;

class ValidationPlugin extends AbstractMiddlewarePlugin
{
    protected string $name = 'validation';
    
    protected int $priority = -100; // Run after translation

    /**
     * Default configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'checks' => ['all'], // 'all' or specific checks
            'available_checks' => [
                'html' => true,
                'variables' => true,
                'length' => true,
                'placeholders' => true,
                'urls' => true,
                'emails' => true,
                'numbers' => true,
                'punctuation' => true,
                'whitespace' => true,
            ],
            'length_ratio' => [
                'min' => 0.5,
                'max' => 2.0,
            ],
            'strict_mode' => false,
            'auto_fix' => false,
        ];
    }

    /**
     * Get the pipeline stage
     * 
     * Using the VALIDATION constant since it's an essential stage
     */
    protected function getStage(): string
    {
        return PipelineStages::VALIDATION;
    }

    /**
     * Handle validation
     */
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        // Let translation happen first
        $result = $next($context);

        if ($this->shouldSkip($context)) {
            return $result;
        }

        // Validate translations
        $this->validateTranslations($context);

        return $result;
    }

    /**
     * Validate all translations
     */
    protected function validateTranslations(TranslationContext $context): void
    {
        $checks = $this->getEnabledChecks();
        $strictMode = $this->getConfigValue('strict_mode', false);
        $autoFix = $this->getConfigValue('auto_fix', false);

        foreach ($context->translations as $locale => &$translations) {
            foreach ($translations as $key => &$translation) {
                $original = $context->texts[$key] ?? null;
                
                if (!$original) {
                    continue;
                }

                $issues = [];

                // Run each validation check
                foreach ($checks as $check) {
                    $methodName = "validate{$check}";
                    if (method_exists($this, $methodName)) {
                        $checkIssues = $this->{$methodName}($original, $translation, $locale);
                        if (!empty($checkIssues)) {
                            $issues = array_merge($issues, $checkIssues);
                        }
                    }
                }

                // Handle validation issues
                if (!empty($issues)) {
                    $this->handleValidationIssues($context, $key, $locale, $issues, $strictMode);
                    
                    // Attempt auto-fix if enabled
                    if ($autoFix) {
                        $fixed = $this->attemptAutoFix($original, $translation, $issues, $locale);
                        if ($fixed !== $translation) {
                            $translation = $fixed;
                            $context->addWarning("Auto-fixed translation for '{$key}' in locale '{$locale}'");
                        }
                    }
                }
            }
        }
    }

    /**
     * Get enabled validation checks
     */
    protected function getEnabledChecks(): array
    {
        $configChecks = $this->getConfigValue('checks', ['all']);
        $availableChecks = $this->getConfigValue('available_checks', []);

        if (in_array('all', $configChecks)) {
            return array_keys(array_filter($availableChecks));
        }

        return array_intersect($configChecks, array_keys(array_filter($availableChecks)));
    }

    /**
     * Validate HTML tags preservation
     */
    protected function validateHtml(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Extract HTML tags from original
        preg_match_all('/<[^>]+>/', $original, $originalTags);
        preg_match_all('/<[^>]+>/', $translation, $translationTags);
        
        $originalTags = $originalTags[0];
        $translationTags = $translationTags[0];
        
        // Check if tag counts match
        if (count($originalTags) !== count($translationTags)) {
            $issues[] = [
                'type' => 'html_tag_count',
                'message' => 'HTML tag count mismatch',
                'original_count' => count($originalTags),
                'translation_count' => count($translationTags),
            ];
        }
        
        // Check if specific tags are preserved
        $originalTagTypes = array_map(fn($tag) => strip_tags($tag), $originalTags);
        $translationTagTypes = array_map(fn($tag) => strip_tags($tag), $translationTags);
        
        $missingTags = array_diff($originalTagTypes, $translationTagTypes);
        if (!empty($missingTags)) {
            $issues[] = [
                'type' => 'html_tags_missing',
                'message' => 'Missing HTML tags',
                'missing' => $missingTags,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate variables preservation
     */
    protected function validateVariables(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Laravel style variables :variable
        preg_match_all('/:\w+/', $original, $originalVars);
        preg_match_all('/:\w+/', $translation, $translationVars);
        
        $missing = array_diff($originalVars[0], $translationVars[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'laravel_variables',
                'message' => 'Missing Laravel variables',
                'missing' => $missing,
            ];
        }
        
        // Mustache style {{variable}}
        preg_match_all('/\{\{[^}]+\}\}/', $original, $originalMustache);
        preg_match_all('/\{\{[^}]+\}\}/', $translation, $translationMustache);
        
        $missing = array_diff($originalMustache[0], $translationMustache[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'mustache_variables',
                'message' => 'Missing mustache variables',
                'missing' => $missing,
            ];
        }
        
        // PHP variables $variable
        preg_match_all('/\$\w+/', $original, $originalPhpVars);
        preg_match_all('/\$\w+/', $translation, $translationPhpVars);
        
        $missing = array_diff($originalPhpVars[0], $translationPhpVars[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'php_variables',
                'message' => 'Missing PHP variables',
                'missing' => $missing,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate placeholders
     */
    protected function validatePlaceholders(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Printf style placeholders %s, %d, etc.
        preg_match_all('/%[sdifFeEgGxXobBcpn]/', $original, $originalPrintf);
        preg_match_all('/%[sdifFeEgGxXobBcpn]/', $translation, $translationPrintf);
        
        if (count($originalPrintf[0]) !== count($translationPrintf[0])) {
            $issues[] = [
                'type' => 'printf_placeholders',
                'message' => 'Printf placeholder count mismatch',
                'original' => $originalPrintf[0],
                'translation' => $translationPrintf[0],
            ];
        }
        
        // Named placeholders {name}, [name]
        preg_match_all('/[\{\[][\w\s]+[\}\]]/', $original, $originalNamed);
        preg_match_all('/[\{\[][\w\s]+[\}\]]/', $translation, $translationNamed);
        
        $missing = array_diff($originalNamed[0], $translationNamed[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'named_placeholders',
                'message' => 'Missing named placeholders',
                'missing' => $missing,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate translation length ratio
     */
    protected function validateLength(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        $originalLength = mb_strlen($original);
        $translationLength = mb_strlen($translation);
        
        if ($originalLength === 0) {
            return $issues;
        }
        
        $ratio = $translationLength / $originalLength;
        $minRatio = $this->getConfigValue('length_ratio.min', 0.5);
        $maxRatio = $this->getConfigValue('length_ratio.max', 2.0);
        
        // Adjust ratios based on language pairs
        $adjustedMinRatio = $this->adjustRatioForLanguage($minRatio, $locale);
        $adjustedMaxRatio = $this->adjustRatioForLanguage($maxRatio, $locale);
        
        if ($ratio < $adjustedMinRatio) {
            $issues[] = [
                'type' => 'length_too_short',
                'message' => 'Translation seems too short',
                'ratio' => $ratio,
                'expected_min' => $adjustedMinRatio,
            ];
        } elseif ($ratio > $adjustedMaxRatio) {
            $issues[] = [
                'type' => 'length_too_long',
                'message' => 'Translation seems too long',
                'ratio' => $ratio,
                'expected_max' => $adjustedMaxRatio,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate URLs preservation
     */
    protected function validateUrls(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        $urlPattern = '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i';
        preg_match_all($urlPattern, $original, $originalUrls);
        preg_match_all($urlPattern, $translation, $translationUrls);
        
        $missing = array_diff($originalUrls[0], $translationUrls[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'urls_missing',
                'message' => 'Missing URLs',
                'missing' => $missing,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate email addresses
     */
    protected function validateEmails(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        $emailPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        preg_match_all($emailPattern, $original, $originalEmails);
        preg_match_all($emailPattern, $translation, $translationEmails);
        
        $missing = array_diff($originalEmails[0], $translationEmails[0]);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'emails_missing',
                'message' => 'Missing email addresses',
                'missing' => $missing,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate numbers
     */
    protected function validateNumbers(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Extract numbers (including decimals)
        preg_match_all('/\d+([.,]\d+)?/', $original, $originalNumbers);
        preg_match_all('/\d+([.,]\d+)?/', $translation, $translationNumbers);
        
        // Normalize numbers for comparison
        $originalNormalized = array_map(fn($n) => str_replace(',', '.', $n), $originalNumbers[0]);
        $translationNormalized = array_map(fn($n) => str_replace(',', '.', $n), $translationNumbers[0]);
        
        $missing = array_diff($originalNormalized, $translationNormalized);
        if (!empty($missing)) {
            $issues[] = [
                'type' => 'numbers_mismatch',
                'message' => 'Number mismatch',
                'missing' => $missing,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate punctuation consistency
     */
    protected function validatePunctuation(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Check ending punctuation
        $originalEnd = mb_substr($original, -1);
        $translationEnd = mb_substr($translation, -1);
        
        $punctuation = ['.', '!', '?', ':', ';'];
        
        if (in_array($originalEnd, $punctuation) && !in_array($translationEnd, $punctuation)) {
            $issues[] = [
                'type' => 'ending_punctuation',
                'message' => 'Missing ending punctuation',
                'expected' => $originalEnd,
            ];
        }
        
        return $issues;
    }

    /**
     * Validate whitespace consistency
     */
    protected function validateWhitespace(string $original, string $translation, string $locale): array
    {
        $issues = [];
        
        // Check leading/trailing whitespace
        if (trim($original) !== $original && trim($translation) === $translation) {
            $issues[] = [
                'type' => 'whitespace',
                'message' => 'Whitespace mismatch',
                'detail' => 'Original has leading/trailing whitespace but translation does not',
            ];
        }
        
        // Check for multiple consecutive spaces
        if (strpos($original, '  ') !== false && strpos($translation, '  ') === false) {
            $issues[] = [
                'type' => 'multiple_spaces',
                'message' => 'Multiple consecutive spaces not preserved',
            ];
        }
        
        return $issues;
    }

    /**
     * Adjust length ratio based on target language
     */
    protected function adjustRatioForLanguage(float $ratio, string $locale): float
    {
        // Language-specific adjustments
        $adjustments = [
            'de' => 1.3,  // German tends to be longer
            'fr' => 1.2,  // French tends to be longer
            'es' => 1.1,  // Spanish tends to be longer
            'ru' => 1.2,  // Russian can be longer
            'zh' => 0.7,  // Chinese tends to be shorter
            'ja' => 0.8,  // Japanese tends to be shorter
            'ko' => 0.9,  // Korean tends to be shorter
        ];
        
        $langCode = substr($locale, 0, 2);
        $adjustment = $adjustments[$langCode] ?? 1.0;
        
        return $ratio * $adjustment;
    }

    /**
     * Handle validation issues
     */
    protected function handleValidationIssues(
        TranslationContext $context,
        string $key,
        string $locale,
        array $issues,
        bool $strictMode
    ): void {
        $issueCount = count($issues);
        $issueTypes = array_column($issues, 'type');
        
        $message = "Validation issues for '{$key}' in locale '{$locale}': " . implode(', ', $issueTypes);
        
        if ($strictMode) {
            $context->addError($message);
        } else {
            $context->addWarning($message);
        }
        
        // Store detailed issues in metadata
        $context->metadata['validation_issues'][$locale][$key] = $issues;
    }

    /**
     * Attempt to auto-fix common issues
     */
    protected function attemptAutoFix(string $original, string $translation, array $issues, string $locale): string
    {
        $fixed = $translation;
        
        foreach ($issues as $issue) {
            switch ($issue['type']) {
                case 'ending_punctuation':
                    // Add missing ending punctuation
                    if (isset($issue['expected'])) {
                        $fixed = rtrim($fixed, '.!?:;') . $issue['expected'];
                    }
                    break;
                    
                case 'whitespace':
                    // Preserve leading/trailing whitespace
                    if (trim($original) !== $original) {
                        $leadingWhitespace = strlen($original) - strlen(ltrim($original));
                        $trailingWhitespace = strlen($original) - strlen(rtrim($original));
                        
                        if ($leadingWhitespace > 0) {
                            $fixed = str_repeat(' ', $leadingWhitespace) . ltrim($fixed);
                        }
                        if ($trailingWhitespace > 0) {
                            $fixed = rtrim($fixed) . str_repeat(' ', $trailingWhitespace);
                        }
                    }
                    break;
            }
        }
        
        return $fixed;
    }
}