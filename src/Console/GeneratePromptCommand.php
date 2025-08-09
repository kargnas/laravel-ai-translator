<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kargnas\LaravelAiTranslator\AI\Clients\GeminiClient;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;

class GeneratePromptCommand extends Command
{
    /**
     * Common stop words to exclude from glossary
     */
    protected const COMMON_STOP_WORDS = [
        'the', 'and', 'for', 'are', 'you', 'your', 'this', 'that', 'with', 'from',
        'but', 'not', 'have', 'has', 'had', 'was', 'were', 'been', 'being', 'will',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai-translator:generate-prompt
                            {--source=en : Source locale to analyze}
                            {--frontend-path=resources/js : Path to frontend files}
                            {--lang-path=lang : Path to language files}
                            {--output-path=storage/ai-translator : Path to save generated prompts and glossary}
                            {--force : Overwrite existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate initial prompts and glossary by analyzing frontend and language files using Gemini';

    /**
     * @var GeminiClient
     */
    protected $gemini_client;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting prompt and glossary generation...');

        // Initialize Gemini client
        $this->initializeGeminiClient();

        // Get paths and options
        $source_locale = $this->option('source');
        $frontend_path = base_path($this->option('frontend-path'));
        $lang_path = base_path($this->option('lang-path'));
        $output_path = base_path($this->option('output-path'));

        // Ensure output directory exists
        if (!File::exists($output_path)) {
            File::makeDirectory($output_path, 0755, true);
        }

        // Step 1: Scan frontend files
        $this->info('Scanning frontend files...');
        $frontend_context = $this->scanFrontendFiles($frontend_path);

        // Step 2: Scan language files
        $this->info('Scanning language files...');
        $language_data = $this->scanLanguageFiles($lang_path, $source_locale);

        // Step 3: Analyze existing translations for glossary
        $this->info('Analyzing existing translations for glossary...');
        $existing_translations = $this->analyzeExistingTranslations($lang_path);

        // Step 4: Generate global prompt
        $this->info('Generating global prompt...');
        $global_prompt = $this->generateGlobalPrompt($frontend_context, $language_data);

        // Step 5: Generate language-specific prompts
        $this->info('Generating language-specific prompts...');
        $language_prompts = $this->generateLanguagePrompts($existing_translations);

        // Step 6: Build glossary
        $this->info('Building glossary...');
        $glossary = $this->buildGlossary($existing_translations, $language_data);

        // Step 7: Save generated content
        $this->saveGeneratedContent($output_path, $global_prompt, $language_prompts, $glossary);

        $this->info('✅ Prompt and glossary generation completed!');
        $this->info("Files saved to: {$output_path}");

        return Command::SUCCESS;
    }

    /**
     * Initialize Gemini client
     */
    protected function initializeGeminiClient(): void
    {
        $api_key = config('ai-translator.ai.gemini.api_key');
        
        if (empty($api_key)) {
            throw new \Exception('Gemini API key not configured. Please set GEMINI_API_KEY in your .env file.');
        }

        $model = config('ai-translator.ai.gemini.model', 'gemini-2.0-flash-exp');
        $this->gemini_client = new GeminiClient($api_key, $model);
    }

    /**
     * Scan frontend files for context
     */
    protected function scanFrontendFiles(string $path): array
    {
        $context = [
            'components' => [],
            'features' => [],
            'ui_patterns' => [],
        ];

        if (!File::exists($path)) {
            $this->warn("Frontend path not found: {$path}");
            return $context;
        }

        // Scan for Vue, React, or other frontend files
        $files = File::glob("{$path}/**/*.{js,jsx,ts,tsx,vue}");
        
        foreach ($files as $file) {
            $content = File::get($file);
            $relative_path = str_replace(base_path() . '/', '', $file);
            
            // Extract component names
            if (preg_match_all('/(?:export\s+(?:default\s+)?(?:class|function|const)\s+|name:\s*[\'"])(\w+)/', $content, $matches)) {
                foreach ($matches[1] as $component) {
                    $context['components'][] = $component;
                }
            }
            
            // Extract translation keys being used
            if (preg_match_all('/(?:__|\$t|\$trans|trans|t)\([\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
                foreach ($matches[1] as $key) {
                    if (!isset($context['translation_keys'])) {
                        $context['translation_keys'] = [];
                    }
                    $context['translation_keys'][] = $key;
                }
            }
        }

        // Remove duplicates
        $context['components'] = array_unique($context['components']);
        if (isset($context['translation_keys'])) {
            $context['translation_keys'] = array_unique($context['translation_keys']);
        }

        return $context;
    }

    /**
     * Scan language files
     */
    protected function scanLanguageFiles(string $path, string $source_locale): array
    {
        $data = [
            'structure' => [],
            'categories' => [],
            'total_keys' => 0,
            'sample_strings' => [],
        ];

        if (!File::exists($path)) {
            $this->warn("Language path not found: {$path}");
            return $data;
        }

        $source_path = "{$path}/{$source_locale}";
        
        // Handle PHP files
        $php_files = File::glob("{$source_path}/*.php");
        foreach ($php_files as $file) {
            $file_name = basename($file, '.php');
            $data['categories'][] = $file_name;
            
            $content = include $file;
            if (is_array($content)) {
                $data['structure'][$file_name] = array_keys($content);
                $data['total_keys'] += count($content, COUNT_RECURSIVE);
                
                // Collect sample strings
                foreach ($content as $key => $value) {
                    if (is_string($value) && strlen($value) > 10 && count($data['sample_strings']) < 20) {
                        $data['sample_strings'][] = $value;
                    }
                }
            }
        }

        // Handle JSON files
        $json_file = "{$path}/{$source_locale}.json";
        $json_files = File::exists($json_file) ? [$json_file] : [];
        foreach ($json_files as $file) {
            $content = json_decode(File::get($file), true);
            if (is_array($content)) {
                $data['structure']['json'] = array_keys($content);
                $data['total_keys'] += count($content);
                
                // Collect sample strings
                foreach ($content as $key => $value) {
                    if (is_string($value) && strlen($value) > 10 && count($data['sample_strings']) < 20) {
                        $data['sample_strings'][] = $value;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Analyze existing translations for patterns
     */
    protected function analyzeExistingTranslations(string $path): array
    {
        $translations = [];
        
        // Get all locale directories
        $locales = File::directories($path);
        
        foreach ($locales as $locale_path) {
            $locale = basename($locale_path);
            $translations[$locale] = [];
            
            // Load PHP files
            $php_files = File::glob("{$locale_path}/*.php");
            foreach ($php_files as $file) {
                $content = include $file;
                if (is_array($content)) {
                    $translations[$locale] = array_merge($translations[$locale], $this->flattenArray($content));
                }
            }
            
            // Load JSON files
            $json_file = "{$locale_path}.json";
            if (File::exists($json_file)) {
                $content = json_decode(File::get($json_file), true);
                if (is_array($content)) {
                    $translations[$locale] = array_merge($translations[$locale], $content);
                }
            }
        }
        
        return $translations;
    }

    /**
     * Flatten nested array
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $new_key = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $new_key));
            } else {
                $result[$new_key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Generate global prompt using Gemini
     */
    protected function generateGlobalPrompt(array $frontend_context, array $language_data): string
    {
        $context_summary = $this->prepareContextSummary($frontend_context, $language_data);
        
        $system_prompt = "You are an expert in creating translation system prompts. Generate a concise and effective global system prompt for translating this application.";
        
        $user_prompt = "Based on the following application context, generate a short but comprehensive global translation prompt that will help AI translate this application accurately:\n\n";
        $user_prompt .= "Frontend Components: " . implode(', ', array_slice($frontend_context['components'] ?? [], 0, 10)) . "\n";
        $user_prompt .= "Translation Categories: " . implode(', ', $language_data['categories'] ?? []) . "\n";
        $user_prompt .= "Total Translation Keys: " . ($language_data['total_keys'] ?? 0) . "\n";
        $user_prompt .= "Sample Strings:\n" . implode("\n", array_slice($language_data['sample_strings'] ?? [], 0, 5)) . "\n\n";
        $user_prompt .= "Generate a global prompt that:\n";
        $user_prompt .= "1. Captures the application's domain and purpose\n";
        $user_prompt .= "2. Sets the appropriate tone and style\n";
        $user_prompt .= "3. Highlights key terminology to maintain consistency\n";
        $user_prompt .= "4. Is concise (max 200 words)\n\n";
        $user_prompt .= "Return only the prompt text, no explanations.";
        
        $response = $this->gemini_client->complete($system_prompt, $user_prompt);
        
        return trim($response);
    }

    /**
     * Generate language-specific prompts
     */
    protected function generateLanguagePrompts(array $existing_translations): array
    {
        $prompts = [];
        $locales = array_keys($existing_translations);
        
        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue; // Skip source language
            }
            
            $this->info("Generating prompt for {$locale}...");
            
            $sample_translations = array_slice($existing_translations[$locale] ?? [], 0, 10);
            
            $language_name = LanguageConfig::getLanguageName($locale) ?? $locale;
            $system_prompt = "You are an expert in creating language-specific translation prompts. Generate a concise prompt for translating to " . $language_name . ".";
            
            $user_prompt = "Based on these sample existing translations for {$locale}, generate a language-specific prompt:\n\n";
            foreach ($sample_translations as $key => $value) {
                $user_prompt .= "- {$value}\n";
            }
            $user_prompt .= "\nGenerate a short prompt (max 100 words) that:\n";
            $user_prompt .= "1. Captures the specific style and tone used in this language\n";
            $user_prompt .= "2. Notes any cultural adaptations or localizations\n";
            $user_prompt .= "3. Highlights language-specific patterns or preferences\n";
            $user_prompt .= "4. Mentions formality level and addressing style\n\n";
            $user_prompt .= "Return only the prompt text, no explanations.";
            
            $response = $this->gemini_client->complete($system_prompt, $user_prompt);
            $prompts[$locale] = trim($response);
        }
        
        return $prompts;
    }

    /**
     * Build glossary from existing translations
     */
    protected function buildGlossary(array $existing_translations, array $language_data): array
    {
        $this->info('Extracting potential glossary terms...');
        
        // Collect terms that appear frequently or have specific translations
        $term_candidates = $this->extractGlossaryTerms($existing_translations);
        
        $system_prompt = "You are an expert in creating translation glossaries. Analyze the provided terms and create a comprehensive glossary.";
        
        $user_prompt = "Based on the following translation patterns, create a glossary of important terms that need consistent translation:\n\n";
        
        // Add term candidates
        foreach ($term_candidates as $term => $translations) {
            $user_prompt .= "Term: {$term}\n";
            $user_prompt .= "Translations: " . json_encode($translations) . "\n\n";
        }
        
        $user_prompt .= "Create a glossary that:\n";
        $user_prompt .= "1. Identifies ambiguous terms that need clarification (like 'knocked' → 'knocked down' vs 'knocked out')\n";
        $user_prompt .= "2. Lists technical terms that should remain consistent\n";
        $user_prompt .= "3. Highlights brand names or product features\n";
        $user_prompt .= "4. Notes any terms that should NOT be translated\n\n";
        $user_prompt .= "Return the glossary in JSON format with this structure:\n";
        $user_prompt .= '{"term": {"definition": "...", "context": "...", "preferred_translation": "...", "notes": "..."}}';
        
        $response = $this->gemini_client->complete($system_prompt, $user_prompt);
        
        // Parse JSON response
        $glossary = json_decode($response, true);
        if (!$glossary) {
            $this->warn('Failed to parse glossary JSON, using empty glossary');
            return [];
        }
        
        return $glossary;
    }

    /**
     * Extract potential glossary terms from translations
     */
    protected function extractGlossaryTerms(array $translations): array
    {
        $terms = [];
        $en_strings = $translations['en'] ?? [];
        
        // Extract common words/phrases that appear in multiple keys
        $word_frequency = [];
        foreach ($en_strings as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            
            // Extract significant words (3+ characters, not common words)
            $words = preg_split('/\s+/', strtolower($value));
            foreach ($words as $word) {
                $word = trim($word, '.,!?;:()[]{}"\'-');
                if (strlen($word) >= 3 && !in_array($word, self::COMMON_STOP_WORDS)) {
                    if (!isset($word_frequency[$word])) {
                        $word_frequency[$word] = 0;
                    }
                    $word_frequency[$word]++;
                }
            }
        }
        
        // Get frequently used terms
        arsort($word_frequency);
        $frequent_terms = array_slice(array_keys($word_frequency), 0, 30);
        
        // Check how these terms are translated in other languages
        foreach ($frequent_terms as $term) {
            $term_translations = [];
            
            foreach ($translations as $locale => $locale_strings) {
                if ($locale === 'en') {
                    continue;
                }
                
                // Find strings containing this term and see how it's translated
                foreach ($en_strings as $key => $en_value) {
                    if (stripos($en_value, $term) !== false && isset($locale_strings[$key])) {
                        if (!isset($term_translations[$locale])) {
                            $term_translations[$locale] = [];
                        }
                        $term_translations[$locale][] = $locale_strings[$key];
                    }
                }
            }
            
            if (!empty($term_translations)) {
                $terms[$term] = $term_translations;
            }
        }
        
        return array_slice($terms, 0, 20); // Limit to top 20 terms
    }

    /**
     * Prepare context summary
     */
    protected function prepareContextSummary(array $frontend_context, array $language_data): string
    {
        $summary = "Application Context:\n";
        $summary .= "- Components: " . count($frontend_context['components'] ?? []) . "\n";
        $summary .= "- Translation Keys Used: " . count($frontend_context['translation_keys'] ?? []) . "\n";
        $summary .= "- Language Categories: " . implode(', ', $language_data['categories'] ?? []) . "\n";
        $summary .= "- Total Keys: " . ($language_data['total_keys'] ?? 0) . "\n";
        
        return $summary;
    }

    /**
     * Save generated content to files
     */
    protected function saveGeneratedContent(string $output_path, string $global_prompt, array $language_prompts, array $glossary): void
    {
        // Save global prompt
        $global_prompt_file = "{$output_path}/prompt-global.txt";
        File::put($global_prompt_file, $global_prompt);
        $this->info("✅ Global prompt saved to: {$global_prompt_file}");
        
        // Save language-specific prompts
        foreach ($language_prompts as $locale => $prompt) {
            $language_prompt_file = "{$output_path}/prompt-{$locale}.txt";
            File::put($language_prompt_file, $prompt);
            $this->info("✅ Language prompt for {$locale} saved to: {$language_prompt_file}");
        }
        
        // Save glossary as JSON
        $glossary_file = "{$output_path}/glossary.json";
        File::put($glossary_file, json_encode($glossary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✅ Glossary saved to: {$glossary_file}");
        
        // Also save glossary as markdown for easy reading
        $glossary_md = "# Translation Glossary\n\n";
        foreach ($glossary as $term => $details) {
            $glossary_md .= "## {$term}\n\n";
            if (isset($details['definition'])) {
                $glossary_md .= "**Definition:** {$details['definition']}\n\n";
            }
            if (isset($details['context'])) {
                $glossary_md .= "**Context:** {$details['context']}\n\n";
            }
            if (isset($details['preferred_translation'])) {
                $glossary_md .= "**Preferred Translation:** {$details['preferred_translation']}\n\n";
            }
            if (isset($details['notes'])) {
                $glossary_md .= "**Notes:** {$details['notes']}\n\n";
            }
            $glossary_md .= "---\n\n";
        }
        
        $glossary_md_file = "{$output_path}/glossary.md";
        File::put($glossary_md_file, $glossary_md);
        $this->info("✅ Glossary (Markdown) saved to: {$glossary_md_file}");
    }
}