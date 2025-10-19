<?php

namespace Kargnas\LaravelAiTranslator\Plugins\Provider;

use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Plugins\Abstract\AbstractProviderPlugin;

/**
 * StylePlugin - Manages translation styles and language-specific formatting preferences
 * 
 * Core Responsibilities:
 * - Applies predefined translation styles (formal, casual, technical, marketing)
 * - Manages language-specific style settings (Korean 존댓말/반말, Japanese 敬語/タメ口)
 * - Injects custom prompts and style instructions into translation context
 * - Handles regional dialect preferences (e.g., US vs UK English, Simplified vs Traditional Chinese)
 * - Maintains consistency of tone and voice across translations
 * - Provides style templates for different content types (legal, medical, gaming, etc.)
 * 
 * This plugin ensures translations match the intended audience and use case by:
 * 1. Setting appropriate formality levels based on content type
 * 2. Applying cultural and linguistic conventions
 * 3. Maintaining brand voice consistency
 * 4. Adapting to target audience demographics
 */
class StylePlugin extends AbstractProviderPlugin
{
    
    protected int $priority = 90; // High priority to set context early

    /**
     * Get default style configurations
     * 
     * Provides comprehensive style presets and language-specific settings
     */
    protected function getDefaultConfig(): array
    {
        return [
            'default_style' => 'formal',
            'styles' => [
                'formal' => [
                    'description' => 'Professional and respectful tone',
                    'prompt' => 'Use formal, professional language appropriate for business communication.',
                ],
                'casual' => [
                    'description' => 'Friendly and conversational tone',
                    'prompt' => 'Use casual, friendly language as if speaking to a friend.',
                ],
                'technical' => [
                    'description' => 'Precise technical terminology',
                    'prompt' => 'Use precise technical terminology and maintain accuracy of technical concepts.',
                ],
                'marketing' => [
                    'description' => 'Engaging and persuasive tone',
                    'prompt' => 'Use engaging, persuasive language that appeals to emotions and drives action.',
                ],
                'legal' => [
                    'description' => 'Precise legal terminology',
                    'prompt' => 'Use precise legal terminology and formal structure appropriate for legal documents.',
                ],
                'medical' => [
                    'description' => 'Medical and healthcare terminology',
                    'prompt' => 'Use accurate medical terminology while maintaining clarity for the intended audience.',
                ],
                'academic' => [
                    'description' => 'Scholarly and research-oriented',
                    'prompt' => 'Use academic language with appropriate citations style and scholarly tone.',
                ],
                'creative' => [
                    'description' => 'Creative and expressive',
                    'prompt' => 'Use creative, expressive language that captures emotion and imagery.',
                ],
            ],
            'language_specific' => [
                'ko' => [
                    'formal' => ['setting' => '존댓말', 'level' => 'highest'],
                    'casual' => ['setting' => '반말', 'level' => 'informal'],
                    'business' => ['setting' => '존댓말', 'level' => 'business'],
                ],
                'ja' => [
                    'formal' => ['setting' => '敬語', 'keigo_level' => 'sonkeigo'],
                    'casual' => ['setting' => 'タメ口', 'keigo_level' => 'none'],
                    'business' => ['setting' => '丁寧語', 'keigo_level' => 'teineigo'],
                ],
                'zh' => [
                    'region' => ['simplified', 'traditional'],
                    'formal' => ['honorifics' => true],
                    'casual' => ['honorifics' => false],
                ],
                'es' => [
                    'formal' => ['pronoun' => 'usted'],
                    'casual' => ['pronoun' => 'tú'],
                    'region' => ['spain', 'mexico', 'argentina'],
                ],
                'fr' => [
                    'formal' => ['pronoun' => 'vous'],
                    'casual' => ['pronoun' => 'tu'],
                    'region' => ['france', 'quebec', 'belgium'],
                ],
                'de' => [
                    'formal' => ['pronoun' => 'Sie'],
                    'casual' => ['pronoun' => 'du'],
                    'region' => ['germany', 'austria', 'switzerland'],
                ],
                'pt' => [
                    'formal' => ['pronoun' => 'você', 'conjugation' => 'third_person'],
                    'casual' => ['pronoun' => 'tu', 'conjugation' => 'second_person'],
                    'region' => ['brazil', 'portugal'],
                ],
                'ar' => [
                    'formal' => ['addressing' => 'حضرتك'],
                    'casual' => ['addressing' => 'انت'],
                    'gender' => ['masculine', 'feminine', 'neutral'],
                ],
                'ru' => [
                    'formal' => ['pronoun' => 'Вы'],
                    'casual' => ['pronoun' => 'ты'],
                ],
                'hi' => [
                    'formal' => ['pronoun' => 'आप'],
                    'casual' => ['pronoun' => 'तुम'],
                ],
            ],
            'custom_prompt' => null,
            'preserve_original_style' => false,
            'adapt_to_content' => true,
        ];
    }

    /**
     * Declare provided services
     * 
     * This plugin provides style configuration service
     */
    public function provides(): array
    {
        return ['style.configuration'];
    }

    /**
     * Specify when this provider should be active
     * 
     * Style should be set early in the pre-processing stage
     */
    public function when(): array
    {
        return ['pre_process'];
    }

    /**
     * Execute style configuration for translation context
     * 
     * Responsibilities:
     * - Analyze content to determine appropriate style if adaptive mode is enabled
     * - Apply selected style configuration to translation context
     * - Set language-specific formatting rules
     * - Inject style prompts into translation metadata
     * - Handle custom prompt overrides
     * 
     * @param TranslationContext $context The translation context to configure
     * @return array Style configuration that was applied
     */
    public function execute(TranslationContext $context): mixed
    {
        $style = $this->determineStyle($context);
        $targetLocales = $context->request->getTargetLocales();
        
        // Build style instructions
        $styleInstructions = $this->buildStyleInstructions($style, $targetLocales, $context);
        
        // Apply style to context
        $this->applyStyleToContext($context, $style, $styleInstructions);
        
        // Log style application
        $this->info("Applied style '{$style}' to translation context", [
            'locales' => $targetLocales,
            'custom_prompt' => !empty($styleInstructions['custom']),
        ]);
        
        return [
            'style' => $style,
            'instructions' => $styleInstructions,
        ];
    }

    /**
     * Determine which style to use based on context and configuration
     * 
     * Responsibilities:
     * - Check for explicitly requested style in context
     * - Analyze content to auto-detect appropriate style
     * - Apply default style as fallback
     * - Consider content type and domain
     * 
     * @param TranslationContext $context Translation context
     * @return string Selected style name
     */
    protected function determineStyle(TranslationContext $context): string
    {
        // Check if style is explicitly set in request
        $requestedStyle = $context->request->getOption('style');
        if ($requestedStyle && $this->isValidStyle($requestedStyle)) {
            return $requestedStyle;
        }
        
        // Check plugin configuration for style
        $configuredStyle = $this->getConfigValue('style');
        if ($configuredStyle && $this->isValidStyle($configuredStyle)) {
            return $configuredStyle;
        }
        
        // Auto-detect style if enabled
        if ($this->getConfigValue('adapt_to_content', true)) {
            $detectedStyle = $this->detectStyleFromContent($context);
            if ($detectedStyle) {
                $this->debug("Auto-detected style: {$detectedStyle}");
                return $detectedStyle;
            }
        }
        
        // Fall back to default
        return $this->getConfigValue('default_style', 'formal');
    }

    /**
     * Auto-detect appropriate style based on content analysis
     * 
     * Responsibilities:
     * - Analyze text patterns to identify content type
     * - Check for domain-specific terminology
     * - Evaluate formality indicators
     * - Consider metadata hints
     * 
     * @param TranslationContext $context Translation context
     * @return string|null Detected style or null if uncertain
     */
    protected function detectStyleFromContent(TranslationContext $context): ?string
    {
        $texts = implode(' ', $context->texts);
        $metadata = $context->metadata;
        
        // Check metadata for hints
        if (isset($metadata['domain'])) {
            $domainStyles = [
                'legal' => 'legal',
                'medical' => 'medical',
                'technical' => 'technical',
                'marketing' => 'marketing',
                'academic' => 'academic',
            ];
            
            if (isset($domainStyles[$metadata['domain']])) {
                return $domainStyles[$metadata['domain']];
            }
        }
        
        // Pattern-based detection
        $patterns = [
            'legal' => '/\b(whereas|hereby|pursuant|liability|agreement|contract)\b/i',
            'medical' => '/\b(patient|diagnosis|treatment|symptom|medication|clinical)\b/i',
            'technical' => '/\b(API|function|database|algorithm|implementation|protocol)\b/i',
            'marketing' => '/\b(buy now|limited offer|exclusive|discount|free|guaranteed)\b/i',
            'academic' => '/\b(research|study|hypothesis|methodology|conclusion|citation)\b/i',
            'casual' => '/\b(hey|gonna|wanna|yeah|cool|awesome)\b/i',
        ];
        
        foreach ($patterns as $style => $pattern) {
            if (preg_match($pattern, $texts)) {
                return $style;
            }
        }
        
        // Check formality level
        $informalIndicators = preg_match_all('/[!?]{2,}|:\)|;\)|LOL|OMG/i', $texts);
        if ($informalIndicators > 2) {
            return 'casual';
        }
        
        return null;
    }

    /**
     * Build comprehensive style instructions for translation
     * 
     * Responsibilities:
     * - Combine base style prompts with language-specific rules
     * - Merge custom prompts if provided
     * - Format instructions for AI provider consumption
     * - Include regional variations
     * 
     * @param string $style Selected style name
     * @param array $targetLocales Target translation locales
     * @param TranslationContext $context Translation context
     * @return array Structured style instructions
     */
    protected function buildStyleInstructions(string $style, array $targetLocales, TranslationContext $context): array
    {
        $instructions = [
            'base' => $this->getBaseStylePrompt($style),
            'language_specific' => [],
            'custom' => null,
        ];
        
        // Add language-specific instructions
        foreach ($targetLocales as $locale) {
            $langCode = substr($locale, 0, 2);
            $languageSettings = $this->getLanguageSpecificSettings($langCode, $style);
            
            if ($languageSettings) {
                $instructions['language_specific'][$locale] = $languageSettings;
            }
        }
        
        // Add custom prompt if provided
        $customPrompt = $this->getConfigValue('custom_prompt');
        if ($customPrompt) {
            $instructions['custom'] = $customPrompt;
        }
        
        // Add any context-specific instructions
        if (isset($context->metadata['style_hints'])) {
            $instructions['context_hints'] = $context->metadata['style_hints'];
        }
        
        return $instructions;
    }

    /**
     * Get base style prompt for a given style
     * 
     * @param string $style Style name
     * @return string Style prompt
     */
    protected function getBaseStylePrompt(string $style): string
    {
        $styles = $this->getConfigValue('styles', []);
        
        if (isset($styles[$style]['prompt'])) {
            return $styles[$style]['prompt'];
        }
        
        // Default prompt if style not found
        return "Translate in a {$style} style.";
    }

    /**
     * Get language-specific settings for a style
     * 
     * Responsibilities:
     * - Retrieve language-specific configuration
     * - Apply style-specific overrides
     * - Format settings for translation engine
     * 
     * @param string $langCode Language code (2-letter ISO)
     * @param string $style Style name
     * @return array|null Language-specific settings
     */
    protected function getLanguageSpecificSettings(string $langCode, string $style): ?array
    {
        $languageConfig = $this->getConfigValue("language_specific.{$langCode}", []);
        
        if (empty($languageConfig)) {
            return null;
        }
        
        $settings = [];
        
        // Apply style-specific settings
        if (isset($languageConfig[$style])) {
            $settings = array_merge($settings, $languageConfig[$style]);
        }
        
        // Add regional settings if available
        if (isset($languageConfig['region'])) {
            $settings['available_regions'] = $languageConfig['region'];
        }
        
        // Build prompt based on settings
        $prompt = $this->buildLanguageSpecificPrompt($langCode, $style, $settings);
        if ($prompt) {
            $settings['prompt'] = $prompt;
        }
        
        return $settings;
    }

    /**
     * Build language-specific prompt based on settings
     * 
     * @param string $langCode Language code
     * @param string $style Style name
     * @param array $settings Language settings
     * @return string|null Language-specific prompt
     */
    protected function buildLanguageSpecificPrompt(string $langCode, string $style, array $settings): ?string
    {
        $prompts = [];
        
        switch ($langCode) {
            case 'ko':
                if (isset($settings['setting'])) {
                    $prompts[] = "Use {$settings['setting']} (Korean honorific level).";
                }
                if (isset($settings['level'])) {
                    $prompts[] = "Formality level: {$settings['level']}.";
                }
                break;
                
            case 'ja':
                if (isset($settings['setting'])) {
                    $prompts[] = "Use {$settings['setting']} (Japanese speech level).";
                }
                if (isset($settings['keigo_level'])) {
                    $prompts[] = "Keigo level: {$settings['keigo_level']}.";
                }
                break;
                
            case 'zh':
                if (isset($settings['honorifics'])) {
                    $honorifics = $settings['honorifics'] ? 'with' : 'without';
                    $prompts[] = "Translate {$honorifics} honorifics.";
                }
                break;
                
            case 'es':
            case 'fr':
            case 'de':
            case 'pt':
            case 'ru':
            case 'hi':
                if (isset($settings['pronoun'])) {
                    $prompts[] = "Use '{$settings['pronoun']}' for second-person address.";
                }
                break;
                
            case 'ar':
                if (isset($settings['addressing'])) {
                    $prompts[] = "Use '{$settings['addressing']}' for addressing.";
                }
                if (isset($settings['gender'])) {
                    $prompts[] = "Use {$settings['gender']} gender forms.";
                }
                break;
        }
        
        return !empty($prompts) ? implode(' ', $prompts) : null;
    }

    /**
     * Apply style configuration to translation context
     * 
     * Responsibilities:
     * - Inject style instructions into context metadata
     * - Set style parameters for translation engine
     * - Configure output formatting rules
     * - Update context state with style information
     * 
     * @param TranslationContext $context Translation context
     * @param string $style Selected style
     * @param array $instructions Style instructions
     */
    protected function applyStyleToContext(TranslationContext $context, string $style, array $instructions): void
    {
        // Store style in context metadata
        $context->metadata['style'] = $style;
        $context->metadata['style_instructions'] = $instructions;
        
        // Build combined prompt for translation
        $combinedPrompt = $this->buildCombinedPrompt($instructions);
        
        // Add to translation prompts
        if (!isset($context->metadata['prompts'])) {
            $context->metadata['prompts'] = [];
        }
        $context->metadata['prompts']['style'] = $combinedPrompt;
        
        // Set plugin data for reference
        $context->setPluginData($this->getName(), [
            'applied_style' => $style,
            'instructions' => $instructions,
            'timestamp' => microtime(true),
        ]);
    }

    /**
     * Build combined prompt from all instruction sources
     * 
     * @param array $instructions Style instructions
     * @return string Combined prompt
     */
    protected function buildCombinedPrompt(array $instructions): string
    {
        $parts = [];
        
        // Add base prompt
        if (!empty($instructions['base'])) {
            $parts[] = $instructions['base'];
        }
        
        // Add language-specific prompts
        foreach ($instructions['language_specific'] as $locale => $settings) {
            if (isset($settings['prompt'])) {
                $parts[] = "For {$locale}: {$settings['prompt']}";
            }
        }
        
        // Add custom prompt
        if (!empty($instructions['custom'])) {
            $parts[] = $instructions['custom'];
        }
        
        // Add context hints
        if (!empty($instructions['context_hints'])) {
            $parts[] = "Additional context: " . implode(', ', $instructions['context_hints']);
        }
        
        return implode("\n", $parts);
    }

    /**
     * Check if a style name is valid
     * 
     * @param string $style Style name to validate
     * @return bool Whether the style is valid
     */
    protected function isValidStyle(string $style): bool
    {
        $styles = $this->getConfigValue('styles', []);
        return isset($styles[$style]);
    }

    /**
     * Get available styles
     * 
     * @return array List of available style names and descriptions
     */
    public function getAvailableStyles(): array
    {
        $styles = $this->getConfigValue('styles', []);
        $available = [];
        
        foreach ($styles as $name => $config) {
            $available[$name] = $config['description'] ?? $name;
        }
        
        return $available;
    }

    /**
     * Get supported languages with style options
     * 
     * @return array Languages with their style capabilities
     */
    public function getSupportedLanguages(): array
    {
        return array_keys($this->getConfigValue('language_specific', []));
    }
}