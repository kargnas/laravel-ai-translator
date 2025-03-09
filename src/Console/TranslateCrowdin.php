<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\CrowdinApiService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\ProjectService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\LanguageService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\FileService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\TranslationService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Services\CrowdinAsyncApiService;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Traits\ConsoleOutputTrait;
use Kargnas\LaravelAiTranslator\Console\CrowdIn\Traits\TokenUsageTrait;

/**
 * Command to translate strings in Crowdin using AI technology
 * 
 * Environment variables:
 * - CROWDIN_API_KEY: Your Crowdin API token (required if not provided via --token option)
 */
class TranslateCrowdin extends Command
{
    use ConsoleOutputTrait;
    use TokenUsageTrait;

    protected $signature = 'ai-translator:translate-crowdin
                            {--token= : Crowdin API token (optional, will use CROWDIN_API_KEY env by default)}
                            {--organization= : Crowdin organization (optional)}
                            {--project= : Crowdin project ID}
                            {--source-language= : Source language code}
                            {--target-language= : Target language code}
                            {--chunk-size=30 : Chunk size for translation}
                            {--max-context-items=100 : Maximum number of context items}
                            {--show-prompt : Show AI prompts during translation}';

    protected $description = 'Translate strings in Crowdin using AI technology';

    protected CrowdinApiService $apiService;
    protected ProjectService $projectService;
    protected LanguageService $languageService;
    protected FileService $fileService;
    protected TranslationService $translationService;
    protected CrowdinAsyncApiService $asyncApiService;

    /**
     * Main execution method
     */
    public function handle()
    {
        try {
            // Get option values
            $token = $this->option('token') ?: env('CROWDIN_API_KEY');
            $organization = $this->option('organization');

            // Validate and get token
            if (empty($token)) {
                $this->warn("No API token provided. You can:");
                $this->line("1. Set CROWDIN_API_KEY in your .env file");
                $this->line("2. Use --token option");
                $this->line("3. Enter it interactively");
                $token = $this->secret('Enter your Crowdin API token');

                if (empty($token)) {
                    throw new \RuntimeException("API token is required to connect to Crowdin.");
                }
            }

            // Display header
            $this->displayHeader();

            // Initialize services
            $this->initializeServices($token, $organization);

            // Select project
            if (!$this->projectService->selectProject($this->option('project'))) {
                return 1;
            }

            // Select languages
            if (
                !$this->languageService->selectLanguages(
                    $this->option('source-language'),
                    $this->option('target-language')
                )
            ) {
                return 1;
            }

            // Select reference languages
            $this->languageService->selectReferenceLanguages();

            // Translate
            $this->translationService->translate();

            return 0;
        } catch (\Exception $e) {
            $this->displayError($e);
            return 1;
        }
    }

    /**
     * Initialize services
     */
    protected function initializeServices(string $token, ?string $organization = null): void
    {
        // Initialize API service
        $this->apiService = new CrowdinApiService($this, $token, $organization);
        $this->apiService->initialize();

        // Initialize other services
        $this->projectService = new ProjectService($this->apiService, $this);
        $this->languageService = new LanguageService($this->projectService, $this);
        $this->fileService = new FileService($this->apiService, $this->projectService, $this);
        $this->asyncApiService = new CrowdinAsyncApiService($this->apiService, $this->projectService, $this);
        $this->translationService = new TranslationService(
            $this->projectService,
            $this->languageService,
            $this->fileService,
            $this->asyncApiService,
            $this,
            (int) $this->option('chunk-size'),
            (int) $this->option('max-context-items'),
            (bool) $this->option('show-prompt')
        );
    }
}
