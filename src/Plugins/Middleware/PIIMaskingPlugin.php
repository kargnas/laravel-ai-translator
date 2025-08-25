<?php

namespace Kargnas\LaravelAiTranslator\Plugins\Middleware;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Plugins\Abstract\AbstractMiddlewarePlugin;

/**
 * PIIMaskingPlugin - Masks personally identifiable information during translation
 * 
 * This plugin identifies and masks sensitive data before translation to prevent
 * PII leakage to AI providers, then restores the original values after translation.
 * 
 * Features:
 * - Email address masking
 * - Phone number masking
 * - Credit card number masking
 * - Social security number masking
 * - IP address masking
 * - Custom pattern masking
 * - Automatic restoration after translation
 */
class PIIMaskingPlugin extends AbstractMiddlewarePlugin
{
    protected int $priority = 200; // Run early to protect data
    
    /**
     * @var array<string, string> Map of masked tokens to original values
     */
    protected array $maskMap = [];
    
    /**
     * @var int Counter for generating unique mask tokens
     */
    protected int $maskCounter = 0;
    
    /**
     * Get default configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'mask_emails' => true,
            'mask_phones' => true,
            'mask_credit_cards' => true,
            'mask_ssn' => true,
            'mask_ips' => true,
            'mask_urls' => false,
            'mask_custom_patterns' => [],
            'mask_token_prefix' => '__PII_',
            'mask_token_suffix' => '__',
            'preserve_format' => true,
        ];
    }
    
    /**
     * Get the pipeline stage
     */
    protected function getStage(): string
    {
        return 'pre_process'; // Run before translation
    }
    
    /**
     * Handle the masking process
     */
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        // Reset mask map for this translation session
        $this->maskMap = [];
        $this->maskCounter = 0;
        
        // Mask PII in all texts
        $maskedTexts = [];
        foreach ($context->texts as $key => $text) {
            $maskedTexts[$key] = $this->maskPII($text);
        }
        
        // Store original texts and replace with masked versions
        $originalTexts = $context->texts;
        $context->texts = $maskedTexts;
        
        // Store mask map in context for restoration
        $context->setPluginData($this->getName(), [
            'original_texts' => $originalTexts,
            'mask_map' => $this->maskMap,
            'masked_texts' => $maskedTexts,
        ]);
        
        $this->info('PII masking applied', [
            'total_masks' => count($this->maskMap),
            'texts_processed' => count($maskedTexts),
        ]);
        
        // Process through pipeline with masked texts
        $result = $next($context);
        
        // Restore PII in translations
        $this->restorePII($context);
        
        return $result;
    }
    
    /**
     * Mask PII in text
     */
    protected function maskPII(string $text): string
    {
        $maskedText = $text;
        
        // Mask custom patterns first (highest priority)
        $customPatterns = $this->getConfigValue('mask_custom_patterns', []);
        foreach ($customPatterns as $pattern => $label) {
            $maskedText = $this->maskPattern($maskedText, $pattern, $label);
        }
        
        // Mask SSN (before phone numbers as it's more specific)
        if ($this->getConfigValue('mask_ssn', true)) {
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b\d{3}-\d{2}-\d{4}\b/',
                'SSN'
            );
        }
        
        // Mask credit card numbers (before general number patterns)
        if ($this->getConfigValue('mask_credit_cards', true)) {
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b(?:\d[ -]*?){13,19}\b/',
                'CARD',
                function ($match) {
                    // Validate with Luhn algorithm
                    $number = preg_replace('/\D/', '', $match);
                    return $this->isValidCreditCard($number) ? $match : null;
                }
            );
        }
        
        // Mask IP addresses (before phone numbers)
        if ($this->getConfigValue('mask_ips', true)) {
            // IPv4
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/',
                'IP'
            );
            
            // IPv6
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b(?:[A-Fa-f0-9]{1,4}:){7}[A-Fa-f0-9]{1,4}\b/',
                'IP'
            );
        }
        
        // Mask emails
        if ($this->getConfigValue('mask_emails', true)) {
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
                'EMAIL'
            );
        }
        
        // Mask phone numbers (last as it's less specific)
        if ($this->getConfigValue('mask_phones', true)) {
            // US format with parentheses
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\(\d{3}\)\s*\d{3}-\d{4}/',
                'PHONE'
            );
            
            // International format
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\+\d{1,3}[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{1,9}/',
                'PHONE'
            );
            
            // US format without parentheses
            $maskedText = $this->maskPattern(
                $maskedText,
                '/\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/',
                'PHONE'
            );
        }
        
        // Mask URLs
        if ($this->getConfigValue('mask_urls', false)) {
            $maskedText = $this->maskPattern(
                $maskedText,
                '/https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)/',
                'URL'
            );
        }
        
        return $maskedText;
    }
    
    /**
     * Mask a pattern in text
     */
    protected function maskPattern(string $text, string $pattern, string $type, ?callable $validator = null): string
    {
        return preg_replace_callback($pattern, function ($matches) use ($type, $validator) {
            $match = $matches[0];
            
            // Apply validator if provided
            if ($validator && !$validator($match)) {
                return $match;
            }
            
            // Check if this value was already masked, return same token
            foreach ($this->maskMap as $token => $value) {
                if ($value === $match) {
                    return $token;
                }
            }
            
            // Generate mask token
            $maskToken = $this->generateMaskToken($type);
            
            // Store mapping
            $this->maskMap[$maskToken] = $match;
            
            return $maskToken;
        }, $text);
    }
    
    /**
     * Generate a unique mask token
     */
    protected function generateMaskToken(string $type): string
    {
        $prefix = $this->getConfigValue('mask_token_prefix', '__PII_');
        $suffix = $this->getConfigValue('mask_token_suffix', '__');
        
        $this->maskCounter++;
        
        return "{$prefix}{$type}_{$this->maskCounter}{$suffix}";
    }
    
    /**
     * Restore PII in translations
     */
    protected function restorePII(TranslationContext $context): void
    {
        $pluginData = $context->getPluginData($this->getName());
        
        if (!$pluginData || !isset($pluginData['mask_map'])) {
            return;
        }
        
        $maskMap = $pluginData['mask_map'];
        $restoredCount = 0;
        
        // Restore PII in all translations
        foreach ($context->translations as $locale => &$translations) {
            foreach ($translations as $key => &$translation) {
                foreach ($maskMap as $maskToken => $originalValue) {
                    if (str_contains($translation, $maskToken)) {
                        $translation = str_replace($maskToken, $originalValue, $translation);
                        $restoredCount++;
                    }
                }
            }
        }
        
        // Restore original texts
        $context->texts = $pluginData['original_texts'];
        
        $this->info('PII restoration completed', [
            'restored_count' => $restoredCount,
            'mask_map_size' => count($maskMap),
        ]);
    }
    
    /**
     * Validate credit card number using Luhn algorithm
     */
    protected function isValidCreditCard(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        
        if (strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }
        
        $sum = 0;
        $even = false;
        
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int)$number[$i];
            
            if ($even) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
            $even = !$even;
        }
        
        return ($sum % 10) === 0;
    }
    
    /**
     * Get masking statistics
     */
    public function getStats(): array
    {
        return [
            'total_masks' => count($this->maskMap),
            'mask_types' => array_reduce(
                array_keys($this->maskMap),
                function ($types, $token) {
                    preg_match('/__PII_([A-Z]+)_/', $token, $matches);
                    $type = $matches[1] ?? 'UNKNOWN';
                    $types[$type] = ($types[$type] ?? 0) + 1;
                    return $types;
                },
                []
            ),
        ];
    }
}