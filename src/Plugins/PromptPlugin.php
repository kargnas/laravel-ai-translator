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
        // This could be populated by a separate context plugin
        $translationContext = $context->getPluginData('global_translation_context') ?? [];
        
        if (empty($translationContext)) {
            return '';
        }
        
        $contextStrings = [];
        foreach ($translationContext as $file => $translations) {
            $contextStrings[] = "File: {$file}";
            foreach ($translations as $key => $translation) {
                if (is_array($translation) && isset($translation['source'], $translation['target'])) {
                    $contextStrings[] = "  {$key}: \"{$translation['source']}\" → \"{$translation['target']}\"";
                }
            }
        }
        
        return implode("\n", $contextStrings);
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