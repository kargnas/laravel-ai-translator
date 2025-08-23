<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Closure;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Plugins\AbstractMiddlewarePlugin;

/**
 * PromptPlugin - Manages system and user prompts for AI translation
 * 
 * This plugin replaces the legacy prompt management by loading
 * prompts from the resources/prompts directory and processing
 * template variables.
 */
class PromptPlugin extends AbstractMiddlewarePlugin
{
    protected string $name = 'prompt_plugin';

    protected array $systemPromptCache = [];
    protected array $userPromptCache = [];

    /**
     * Get the pipeline stage where this plugin should run
     */
    protected function getStage(): string
    {
        return 'preparation';
    }

    /**
     * Handle the prompt generation
     */
    public function handle(TranslationContext $context, Closure $next): mixed
    {
        // Load system and user prompts
        $context->setPluginData('system_prompt_template', $this->getSystemPrompt());
        $context->setPluginData('user_prompt_template', $this->getUserPrompt());
        
        // Process prompt templates with context data
        $request = $context->getRequest();
        
        $systemPrompt = $this->processTemplate(
            $context->getPluginData('system_prompt_template') ?? '',
            $this->getSystemPromptVariables($context)
        );
        
        $userPrompt = $this->processTemplate(
            $context->getPluginData('user_prompt_template') ?? '',
            $this->getUserPromptVariables($context)
        );

        $context->setPluginData('system_prompt', $systemPrompt);
        $context->setPluginData('user_prompt', $userPrompt);
        
        return $next($context);
    }

    /**
     * Get system prompt template
     */
    protected function getSystemPrompt(): string
    {
        if (!isset($this->systemPromptCache['content'])) {
            $promptPath = __DIR__ . '/../Support/Prompts/system-prompt.txt';
            
            if (!file_exists($promptPath)) {
                // Fallback to resources location
                $promptPath = base_path('resources/prompts/system-prompt.txt');
            }
            
            if (!file_exists($promptPath)) {
                throw new \Exception("System prompt file not found. Expected at: src/Support/Prompts/system-prompt.txt");
            }
            
            $this->systemPromptCache['content'] = file_get_contents($promptPath);
        }
        
        return $this->systemPromptCache['content'];
    }

    /**
     * Get user prompt template
     */
    protected function getUserPrompt(): string
    {
        if (!isset($this->userPromptCache['content'])) {
            $promptPath = __DIR__ . '/../Support/Prompts/user-prompt.txt';
            
            if (!file_exists($promptPath)) {
                // Fallback to resources location
                $promptPath = base_path('resources/prompts/user-prompt.txt');
            }
            
            if (!file_exists($promptPath)) {
                throw new \Exception("User prompt file not found. Expected at: src/Support/Prompts/user-prompt.txt");
            }
            
            $this->userPromptCache['content'] = file_get_contents($promptPath);
        }
        
        return $this->userPromptCache['content'];
    }

    /**
     * Get variables for system prompt template
     */
    protected function getSystemPromptVariables(TranslationContext $context): array
    {
        $request = $context->getRequest();
        
        return [
            'sourceLanguage' => $this->getLanguageName($request->getSourceLanguage()),
            'targetLanguage' => $this->getLanguageName($request->getTargetLanguage()),
            'additionalRules' => $this->getAdditionalRules($context),
            'translationContextInSourceLanguage' => $this->getTranslationContext($context),
        ];
    }

    /**
     * Get variables for user prompt template
     */
    protected function getUserPromptVariables(TranslationContext $context): array
    {
        $request = $context->getRequest();
        $texts = $request->getTexts();
        
        return [
            'sourceLanguage' => $this->getLanguageName($request->getSourceLanguage()),
            'targetLanguage' => $this->getLanguageName($request->getTargetLanguage()),
            'filename' => $request->getMetadata('filename', 'unknown'),
            'parentKey' => $request->getMetadata('parent_key', ''),
            'keys' => implode(', ', array_keys($texts)),
            'strings' => $this->formatStringsForPrompt($texts),
            'options' => [
                'disablePlural' => $request->getOption('disable_plural', false),
            ],
        ];
    }

    /**
     * Process template by replacing variables
     */
    protected function processTemplate(string $template, array $variables): string
    {
        $processed = $template;
        
        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                // Handle nested arrays (like options)
                foreach ($value as $subKey => $subValue) {
                    $placeholder = "{{$key}.{$subKey}}";
                    $processed = str_replace($placeholder, (string) $subValue, $processed);
                }
            } else {
                $placeholder = "{{$key}}";
                $processed = str_replace($placeholder, (string) $value, $processed);
            }
        }
        
        return $processed;
    }

    /**
     * Get human-readable language name
     */
    protected function getLanguageName(string $languageCode): string
    {
        // Use LanguageConfig to get proper language names
        $config = app(\Kargnas\LaravelAiTranslator\Support\Language\LanguageConfig::class);
        return $config::getLanguageName($languageCode) ?? ucfirst($languageCode);
    }

    /**
     * Get additional rules for the target language
     */
    protected function getAdditionalRules(TranslationContext $context): string
    {
        $request = $context->getRequest();
        $targetLanguage = $request->getTargetLanguage();
        
        // Use the new Language and LanguageRules classes
        $language = \Kargnas\LaravelAiTranslator\Support\Language\Language::fromCode($targetLanguage);
        $rules = \Kargnas\LaravelAiTranslator\Support\Language\LanguageRules::getAdditionalRules($language);
        
        return implode("\n", $rules);
    }

    /**
     * Get translation context from existing translations
     */
    protected function getTranslationContext(TranslationContext $context): string
    {
        $csvRows = [];
        $request = $context->getRequest();
        
        // Get filename from metadata
        $filename = $request->getMetadata('filename', 'unknown');
        if ($filename !== 'unknown') {
            // Remove extension for cleaner display
            $filename = pathinfo($filename, PATHINFO_FILENAME);
        }
        
        // Add CSV header
        $csvRows[] = "file,key,text";
        
        // Add current source texts as context
        $texts = $request->getTexts();
        if (!empty($texts)) {
            foreach ($texts as $key => $text) {
                // Escape text for CSV format
                $escapedText = $this->escapeCsvValue($text);
                $escapedKey = $this->escapeCsvValue($key);
                $escapedFilename = $this->escapeCsvValue($filename);
                $csvRows[] = "{$escapedFilename},{$escapedKey},{$escapedText}";
            }
        }
        
        // Also include any existing translations from context (e.g., from other files)
        $globalContext = $context->getPluginData('global_translation_context') ?? [];
        foreach ($globalContext as $file => $translations) {
            if ($file !== $filename) { // Don't duplicate current file
                $escapedFile = $this->escapeCsvValue($file);
                foreach ($translations as $key => $translation) {
                    $text = '';
                    if (is_array($translation) && isset($translation['source'])) {
                        $text = $translation['source'];
                    } elseif (is_string($translation)) {
                        $text = $translation;
                    }
                    
                    if ($text) {
                        $escapedText = $this->escapeCsvValue($text);
                        $escapedKey = $this->escapeCsvValue($key);
                        $csvRows[] = "{$escapedFile},{$escapedKey},{$escapedText}";
                    }
                }
            }
        }
        
        return implode("\n", $csvRows);
    }
    
    /**
     * Escape value for CSV format
     */
    protected function escapeCsvValue(string $value): string
    {
        // If value contains comma, double quote, or newline, wrap in quotes and escape quotes
        if (strpos($value, ',') !== false || 
            strpos($value, '"') !== false || 
            strpos($value, "\n") !== false ||
            strpos($value, "\r") !== false) {
            // Double any existing quotes and wrap in quotes
            $value = str_replace('"', '""', $value);
            return '"' . $value . '"';
        }
        return $value;
    }

    /**
     * Format strings for prompt
     */
    protected function formatStringsForPrompt(array $texts): string
    {
        $formatted = [];
        foreach ($texts as $key => $value) {
            $formatted[] = "{$key}: \"{$value}\"";
        }
        
        return implode("\n", $formatted);
    }
}