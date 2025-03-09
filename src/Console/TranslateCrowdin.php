<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Carbon\Carbon;
use CrowdinApiClient\Crowdin;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\Project;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;

/**
 * Command to translate strings in Crowdin using AI technology
 * 
 * Environment variables:
 * - CROWDIN_API_KEY: Your Crowdin API token (required if not provided via --token option)
 */
class TranslateCrowdin extends Command
{
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

    /**
     * Crowdin API client
     */
    protected Crowdin $crowdin;

    /**
     * API authentication information
     */
    protected string $crowdinToken;
    protected ?string $crowdinOrganization = null;

    /**
     * Project and language information
     */
    protected array $selectedProject;
    protected array $selectedTargetLanguages = [];
    protected array $referenceLanguages = [];
    protected string $sourceLocale;

    /**
     * Translation settings
     */
    protected int $chunkSize;

    /**
     * Token usage tracking
     */
    protected array $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'total_tokens' => 0
    ];

    /**
     * Color codes for console output
     */
    protected array $colors = [
        'reset' => "\033[0m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'purple' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'gray' => "\033[90m",
        'bold' => "\033[1m",
        'underline' => "\033[4m",
        'red_bg' => "\033[41m",
        'green_bg' => "\033[42m",
        'yellow_bg' => "\033[43m",
        'blue_bg' => "\033[44m",
        'purple_bg' => "\033[45m",
        'cyan_bg' => "\033[46m",
        'white_bg' => "\033[47m"
    ];

    /**
     * Main execution method
     */
    public function handle()
    {
        try {
            // Get option values
            $token = $this->option('token') ?: env('CROWDIN_API_KEY');
            $organization = $this->option('organization');
            $projectId = $this->option('project');
            $sourceLanguage = $this->option('source-language');
            $targetLanguage = $this->option('target-language');
            $this->chunkSize = (int) $this->option('chunk-size') ?: 30;

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

            // Get organization if provided
            if (!empty($organization)) {
                $this->crowdinOrganization = $organization;
                $this->line($this->colors['gray'] . "Using organization: {$organization}" . $this->colors['reset']);
            } else {
                $this->line($this->colors['gray'] . "No organization specified, using default account" . $this->colors['reset']);
            }

            $this->crowdinToken = $token;

            // Display header
            $this->displayHeader();

            // Initialize Crowdin client
            $this->initializeCrowdinClient();

            // Select project
            if (!$this->selectProject($projectId)) {
                return 1;
            }

            // Select languages
            if (!$this->selectLanguages($sourceLanguage, $targetLanguage)) {
                return 1;
            }

            // Select reference languages
            $this->selectReferenceLanguages();

            // Translate
            $this->translate();

            return 0;
        } catch (\Exception $e) {
            $this->error("Translation process failed: " . $e->getMessage());
            if (config('app.debug')) {
                $this->line($this->colors['gray'] . $e->getTraceAsString() . $this->colors['reset']);
            }
            return 1;
        }
    }

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Crowdin AI Translator " . $this->colors['reset']);
        $this->line($this->colors['gray'] . "Translating strings using AI technology" . $this->colors['reset']);
        $this->line(str_repeat('─', 80) . "\n");
    }

    /**
     * Initialize Crowdin client
     *
     * @throws \RuntimeException When client initialization fails
     */
    protected function initializeCrowdinClient(): void
    {
        try {
            $config = ['access_token' => $this->crowdinToken];

            // Add organization only if provided
            if (!empty($this->crowdinOrganization)) {
                $config['organization'] = $this->crowdinOrganization;
            }

            $this->crowdin = new Crowdin($config);

            $connectionMsg = $this->colors['green'] . "✓ Connected to Crowdin API";
            if (!empty($this->crowdinOrganization)) {
                $connectionMsg .= " (Organization: {$this->crowdinOrganization})";
            }
            $this->info($connectionMsg . $this->colors['reset']);

            // Verify connection by making a test API call
            $this->crowdin->project->list(['limit' => 1]);
        } catch (\Exception $e) {
            $errorMsg = "Failed to initialize Crowdin client: " . $e->getMessage();
            if (str_contains(strtolower($e->getMessage()), 'unauthorized')) {
                $errorMsg .= "\nPlease check your API token and organization settings.";
            }
            throw new \RuntimeException($errorMsg, 0, $e);
        }
    }

    /**
     * Select project
     *
     * @param string|null $projectId Project ID
     * @return bool Success status
     */
    protected function selectProject(?string $projectId = null): bool
    {
        // Get project list
        $projects = $this->getAllProjects();

        if (empty($projects)) {
            $this->error("No projects found in your Crowdin account.");
            return false;
        }

        // Select project
        if (!empty($projectId)) {
            $this->selectedProject = collect($projects)->firstWhere('id', $projectId);
            if (empty($this->selectedProject)) {
                $this->error("Project with ID {$projectId} not found.");
                return false;
            }
        } else {
            $projectChoices = collect($projects)->mapWithKeys(function ($project) {
                return [$project['id'] => "{$project['name']} ({$project['id']})"];
            })->toArray();

            $selectedProjectId = array_search($this->choice(
                $this->colors['yellow'] . 'Select a project' . $this->colors['reset'],
                $projectChoices
            ), $projectChoices);

            $this->selectedProject = collect($projects)->where('id', $selectedProjectId)->first();
        }

        $this->info($this->colors['green'] . "✓ Selected project: " .
            $this->colors['reset'] . $this->colors['bold'] . "{$this->selectedProject['name']}" .
            $this->colors['reset'] . " ({$this->selectedProject['id']})");

        return true;
    }

    /**
     * Select languages
     *
     * @param string|null $sourceLanguage Source language
     * @param string|null $targetLanguage Target language
     * @return bool Success status
     */
    protected function selectLanguages(?string $sourceLanguage = null, ?string $targetLanguage = null): bool
    {
        // Select source language
        if (!empty($sourceLanguage)) {
            $this->selectedProject['sourceLanguage'] = collect($this->selectedProject['targetLanguages'])
                ->firstWhere('name', $sourceLanguage);

            if (empty($this->selectedProject['sourceLanguage'])) {
                $this->error("Source language {$sourceLanguage} not found in project.");
                return false;
            }
        }

        $this->sourceLocale = $this->selectedProject['sourceLanguage']['id'];
        $this->info($this->colors['green'] . "✓ Source language: " .
            $this->colors['reset'] . $this->colors['bold'] . "{$this->selectedProject['sourceLanguage']['name']}" .
            $this->colors['reset'] . " ({$this->sourceLocale})");

        // Select target languages
        if (!empty($targetLanguage)) {
            $this->selectedTargetLanguages = [
                collect($this->selectedProject['targetLanguages'])
                    ->firstWhere('name', $targetLanguage)
            ];

            if (empty($this->selectedTargetLanguages[0])) {
                $this->error("Target language {$targetLanguage} not found in project.");
                return false;
            }
        } else {
            // Keep existing logic
            $targetLanguageChoices = collect($this->selectedProject['targetLanguages'])
                ->filter(function ($language) {
                    return $language['id'] !== $this->selectedProject['sourceLanguage']['id'];
                })
                ->mapWithKeys(function ($language) {
                    return [$language['id'] => "{$language['name']} ({$language['id']})"];
                })
                ->toArray();

            $selectedTargetLanguageIds = $this->choice(
                $this->colors['yellow'] . 'Select target languages (comma-separated)' . $this->colors['reset'],
                $targetLanguageChoices,
                null,
                null,
                true
            );

            $this->selectedTargetLanguages = collect($this->selectedProject['targetLanguages'])
                ->filter(function ($language) use ($selectedTargetLanguageIds) {
                    return in_array($language['id'], $selectedTargetLanguageIds);
                })
                ->toArray();
        }

        // Selected languages output
        foreach ($this->selectedTargetLanguages as $language) {
            $this->info($this->colors['green'] . "✓ Target language: " .
                $this->colors['reset'] . $this->colors['bold'] . "{$language['name']}" .
                $this->colors['reset'] . " ({$language['id']})");
        }

        return true;
    }

    /**
     * Select reference languages
     */
    protected function selectReferenceLanguages(): void
    {
        if ($this->ask($this->colors['yellow'] . 'Do you want to choose reference languages? (y/n)' . $this->colors['reset'], 'n') === 'y') {
            $this->referenceLanguages = $this->choiceLanguages(
                $this->colors['yellow'] . "Choose reference languages for translation guidance. Select languages with high-quality translations. Multiple selections with comma separator (e.g. '1,2')" . $this->colors['reset'],
                true
            );

            // Selected reference languages output
            if (!empty($this->referenceLanguages)) {
                $refLanguageNames = collect($this->selectedProject['targetLanguages'])
                    ->whereIn('id', $this->referenceLanguages)
                    ->pluck('name')
                    ->toArray();

                $this->info($this->colors['green'] . "✓ Reference languages: " .
                    $this->colors['reset'] . $this->colors['bold'] . implode(", ", $refLanguageNames) .
                    $this->colors['reset']);
            }
        }
    }

    /**
     * Language selection helper method
     *
     * @param string $question Question
     * @param bool $multiple Multiple selection
     * @param string|null $default Default value
     * @return array|string Selected languages(s)
     */
    public function choiceLanguages(string $question, bool $multiple, ?string $default = null): array|string
    {
        $locales = collect($this->selectedProject['targetLanguages'])
            ->sortBy('id')
            ->pluck('id')
            ->values()
            ->toArray();

        return $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple
        );
    }

    /**
     * Translate operation execution
     */
    public function translate(): void
    {
        foreach ($this->selectedTargetLanguages as $targetLanguage) {
            $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Translating to {$targetLanguage['name']} " . $this->colors['reset']);
            $this->line($this->colors['gray'] . "Locale: {$targetLanguage['locale']}" . $this->colors['reset']);

            // Variable for progress display
            $directoryCount = 0;
            $fileCount = 0;
            $stringCount = 0;
            $translatedCount = 0;

            // Get directory list
            $directories = $this->getAllDirectories($this->selectedProject['id']);

            foreach ($directories as $directory) {
                $directoryCount++;
                $directory = $directory->getData();

                // Get file list
                $files = collect($this->getAllFiles($this->selectedProject['id'], $directory['id']))->map(function ($file) {
                    return $file->getData();
                });

                if ($files->count() === 0) {
                    continue;
                }

                $this->displayDirectoryInfo($directory, $files->count(), $directoryCount, count($directories));

                foreach ($files as $file) {
                    $fileCount++;
                    $this->displayFileInfo($file, $fileCount);

                    // Get string and translation information
                    $allStrings = $this->getAllSourceString($this->selectedProject['id'], $file['id']);
                    $allTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $targetLanguage['id']);
                    $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                    // Get reference language translations
                    $referenceApprovals = $this->getReferenceApprovals($file, $allStrings);

                    // Filter untranslated strings
                    $untranslatedStrings = $this->filterUntranslatedStrings($allStrings, $approvals, $allTranslations);
                    $stringCount += $untranslatedStrings->count();

                    $this->info($this->colors['yellow'] . "➤ Untranslated: " .
                        $this->colors['reset'] . $this->colors['bold'] . "{$untranslatedStrings->count()}" .
                        $this->colors['reset'] . " strings");

                    // Translate in chunks
                    $untranslatedStrings
                        ->chunk($this->chunkSize)
                        ->each(function ($chunk, $chunkIndex) use ($file, $targetLanguage, $untranslatedStrings, $referenceApprovals, &$translatedCount) {
                            $chunkSize = $chunk->count();
                            $this->info($this->colors['cyan'] . "✎ Translating chunk " .
                                ($chunkIndex + 1) . "/" . ceil($untranslatedStrings->count() / $this->chunkSize) .
                                " ({$chunkSize} strings)" . $this->colors['reset']);

                            // Get global translation context
                            $globalContext = $this->getGlobalContext($file, $targetLanguage, (int) $this->option('max-context-items') ?: 100);

                            // AIProvider setup
                            $translator = $this->createTranslator($file, $chunk, $referenceApprovals, $targetLanguage, $globalContext);

                            try {
                                // Translate
                                $translated = $translator->translate();
                                $translatedCount += count($translated);

                                // Process translation results
                                $this->processTranslationResults($translated, $untranslatedStrings, $targetLanguage);

                                // Cost calculation and display
                                $this->displayCostEstimation($translator);
                            } catch (\Exception $e) {
                                $this->error("Translation failed: " . $e->getMessage());
                            }
                        });
                }
            }

            // Translation complete summary
            $this->displayTranslationSummary($targetLanguage, $directoryCount, $fileCount, $stringCount, $translatedCount);
        }
    }

    /**
     * Display directory information
     */
    protected function displayDirectoryInfo(array $directory, int $fileCount, int $current, int $total): void
    {
        $this->line("\n" . $this->colors['blue'] . "📂 Directory [{$current}/{$total}]: " .
            $this->colors['reset'] . $this->colors['bold'] . "{$directory['path']}" .
            $this->colors['reset'] . " ({$fileCount} files)");
    }

    /**
     * Display file information
     */
    protected function displayFileInfo(array $file, int $fileCount): void
    {
        $this->line($this->colors['purple'] . "  📄 File: " .
            $this->colors['reset'] . $this->colors['bold'] . "{$file['name']}" .
            $this->colors['reset'] . " ({$file['id']})");
    }

    /**
     * Get reference language approved translations
     */
    protected function getReferenceApprovals(array $file, Collection $allStrings): Collection
    {
        $referenceApprovals = collect([]);

        if (!empty($this->referenceLanguages)) {
            foreach ($this->referenceLanguages as $refLocale) {
                $this->line($this->colors['gray'] . "    ↳ Loading reference language: {$refLocale}" . $this->colors['reset']);

                $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $refLocale);
                $refTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $refLocale);

                $referenceApprovals[$refLocale] = $allStrings->mapWithKeys(function (SourceString $sourceString) use ($approvals, $refTranslations) {
                    $approved = $approvals->map(fn(StringTranslationApproval $ap) => $ap->getData())
                        ->where('stringId', $sourceString->getId())
                        ->first();

                    if (!$approved) {
                        return [];
                    }

                    $approvedTranslation = $refTranslations->map(fn(LanguageTranslation $t) => $t->getData())
                        ->where('translationId', $approved['translationId'])
                        ->first();

                    if (!$approvedTranslation) {
                        return [];
                    }

                    return [
                        $sourceString->getIdentifier() => $approvedTranslation['text'],
                    ];
                });
            }
        }

        return $referenceApprovals;
    }

    /**
     * Filter untranslated strings
     */
    protected function filterUntranslatedStrings(Collection $allStrings, Collection $approvals, Collection $allTranslations): Collection
    {
        return $allStrings
            ->filter(function (SourceString $sourceString) use ($approvals, $allTranslations) {
                if (!$sourceString->getIdentifier() || $sourceString->isHidden()) {
                    return false;
                }

                $approved = $approvals->filter(fn(StringTranslationApproval $ap) => $ap->getStringId() == $sourceString->getId());

                if ($approved->count() > 0) {
                    $translation = $allTranslations->filter(fn(LanguageTranslation $t) => $t->getTranslationId() == $approved->first()->getTranslationId())->first();

                    if ($translation) {
                        return false; // Skip if already approved translation exists
                    }
                }

                return true;
            })
            ->map(function (SourceString $sourceString) {
                return $sourceString->getData();
            });
    }

    /**
     * Get global translation context
     */
    protected function getGlobalContext(array $file, array $targetLanguage, int $maxContextItems): array
    {
        if ($maxContextItems <= 0) {
            return [];
        }

        $contextProvider = new TranslationContextProvider();
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $this->selectedProject['sourceLanguage']['name'],
            $targetLanguage['name'],
            basename($file['name']),
            $maxContextItems
        );

        if (!empty($globalContext)) {
            $contextItemCount = collect($globalContext)->map(fn($items) => count($items))->sum();
            $this->info($this->colors['blue'] . "    ℹ Using context: " .
                $this->colors['reset'] . count($globalContext) . " files, " .
                $contextItemCount . " items");
        }

        return $globalContext;
    }

    /**
     * AIProvider setup
     */
    protected function createTranslator(array $file, Collection $chunk, Collection $referenceApprovals, array $targetLanguage, array $globalContext): AIProvider
    {
        $translator = new AIProvider(
            filename: basename($file['name']),
            strings: $chunk->mapWithKeys(function ($string) use ($referenceApprovals) {
                $context = $string['context'] ?? null;
                $context = preg_replace("/[\.\s\->]/", "", $context);

                if (preg_replace("/[\.\s\->]/", "", $string['identifier']) === $context) {
                    $context = null;
                }

                /** @var Collection $references */
                $references = $referenceApprovals->map(function ($items) use ($string) {
                    return $items[$string['identifier']] ?? "";
                })->filter(function ($value) {
                    return strlen($value) > 0;
                });

                return [
                    $string['identifier'] => [
                        'text' => $references->only($this->sourceLocale)->first() ?? $string['text'],
                        'context' => $context,
                        'references' => $references->except($this->sourceLocale)->toArray(),
                    ],
                ];
            })->toArray(),
            sourceLanguage: $this->selectedProject['sourceLanguage']['name'],
            targetLanguage: $targetLanguage['name'],
            additionalRules: [],
            globalTranslationContext: $globalContext
        );

        // Set up thinking callbacks
        $translator->setOnThinking(function ($thinking) {
            echo $this->colors['gray'] . $thinking . $this->colors['reset'];
        });

        $translator->setOnThinkingStart(function () {
            $this->line($this->colors['gray'] . "    " . "🧠 AI Thinking..." . $this->colors['reset']);
        });

        $translator->setOnThinkingEnd(function () {
            $this->line($this->colors['gray'] . "    " . "Thinking completed." . $this->colors['reset']);
        });

        // Set up translation progress callback
        $translator->setOnTranslated(function ($item, $status, $translatedItems) use ($chunk) {
            if ($status === TranslationStatus::COMPLETED) {
                $totalCount = $chunk->count();
                $completedCount = count($translatedItems);

                $this->line($this->colors['cyan'] . "  ⟳ " .
                    $this->colors['reset'] . $item->key .
                    $this->colors['gray'] . " → " .
                    $this->colors['reset'] . $item->translated .
                    $this->colors['gray'] . " ({$completedCount}/{$totalCount})" .
                    $this->colors['reset']);
            }
        });

        // Set up token usage callback
        $translator->setOnTokenUsage(function ($usage) {
            $isFinal = $usage['final'] ?? false;
            $inputTokens = $usage['input_tokens'] ?? 0;
            $outputTokens = $usage['output_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? 0;

            // Display real-time token usage
            $this->line($this->colors['gray'] . "    Tokens: " .
                "Input=" . $this->colors['green'] . $inputTokens . $this->colors['gray'] . ", " .
                "Output=" . $this->colors['green'] . $outputTokens . $this->colors['gray'] . ", " .
                "Total=" . $this->colors['purple'] . $totalTokens . $this->colors['gray'] .
                $this->colors['reset']);
        });

        // Set up prompt logging callback if enabled
        if ($this->option('show-prompt')) {
            $translator->setOnPromptGenerated(function ($prompt, PromptType $type) {
                $typeText = match ($type) {
                    PromptType::SYSTEM => '🤖 System Prompt',
                    PromptType::USER => '👤 User Prompt',
                };

                print ("\n    {$typeText}:\n");
                print ($this->colors['gray'] . "    " . str_replace("\n", $this->colors['reset'] . "\n    " . $this->colors['gray'], $prompt) . $this->colors['reset'] . "\n");
            });
        }

        return $translator;
    }

    /**
     * Process translation results
     */
    protected function processTranslationResults(array $translated, Collection $untranslatedStrings, array $targetLanguage): void
    {
        foreach ($translated as $item) {
            $targetString = $untranslatedStrings->where('identifier', $item->key)->first();

            if (!$targetString) {
                $this->line($this->colors['gray'] . "    ↳ Skipping: {$item->key} (Not found)" . $this->colors['reset']);
                continue;
            }

            $existsTranslations = $this->getAllTranslations($this->selectedProject['id'], $targetString['id'], $targetLanguage['id']);
            $existsTranslations = $existsTranslations->sortByDesc(fn(StringTranslation $t) => Carbon::make($t->getDataProperty('created_at')))->values();

            // Skip if identical translation exists
            if ($existsTranslations->filter(fn(StringTranslation $t) => $t->getText() === $item->translated)->isNotEmpty()) {
                $this->line($this->colors['gray'] . "    ↳ Skipping: {$item->key} (Duplicate)" . $this->colors['reset']);
                continue;
            }

            // Delete existing translations by the same user
            $myTransitions = $existsTranslations->filter(fn(StringTranslation $t) => $t->getUser()['id'] === 16501205);

            if ($myTransitions->count() > 0) {
                $this->delTranslation($this->selectedProject['id'], $myTransitions->first()->getId());
            }

            // Add new translation
            $this->info($this->colors['green'] . "    ✓ Added: " .
                $this->colors['reset'] . "{$item->key}" .
                $this->colors['gray'] . " => " .
                $this->colors['reset'] . "{$item->translated}");

            $this->addTranslation($this->selectedProject['id'], $targetString['id'], $targetLanguage['id'], $item->translated);
        }
    }

    /**
     * Cost calculation and display
     */
    protected function displayCostEstimation(AIProvider $translator): void
    {
        $usage = $translator->getTokenUsage();
        $printer = new TokenUsagePrinter($translator->getModel());
        $printer->printTokenUsageSummary($this, $usage);
    }

    /**
     * Translation complete summary display
     */
    protected function displayTranslationSummary(array $targetLanguage, int $directoryCount, int $fileCount, int $stringCount, int $translatedCount): void
    {
        $this->line("\n" . str_repeat('─', 80));
        $this->line($this->colors['green_bg'] . $this->colors['white'] . $this->colors['bold'] . " Translation Complete: {$targetLanguage['name']} " . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Directories scanned: " . $this->colors['reset'] . $directoryCount);
        $this->line($this->colors['yellow'] . "Files processed: " . $this->colors['reset'] . $fileCount);
        $this->line($this->colors['yellow'] . "Strings found: " . $this->colors['reset'] . $stringCount);
        $this->line($this->colors['yellow'] . "Strings translated: " . $this->colors['reset'] . $translatedCount);

        // Total token usage output
        $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . " Total Token Usage " . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Input Tokens: " . $this->colors['reset'] . $this->colors['green'] . $this->tokenUsage['input_tokens'] . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Output Tokens: " . $this->colors['reset'] . $this->colors['green'] . $this->tokenUsage['output_tokens'] . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Cache Created: " . $this->colors['reset'] . $this->colors['blue'] . $this->tokenUsage['cache_creation_input_tokens'] . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Cache Read: " . $this->colors['reset'] . $this->colors['blue'] . $this->tokenUsage['cache_read_input_tokens'] . $this->colors['reset']);
        $this->line($this->colors['yellow'] . "Total Tokens: " . $this->colors['reset'] . $this->colors['bold'] . $this->colors['purple'] . $this->tokenUsage['total_tokens'] . $this->colors['reset']);
    }

    /**
     * Get all projects
     */
    protected function getAllProjects(): array
    {
        $projects = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->project->list([
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $projects = $projects->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $projects->map(function (Project $project) {
            return $project->getData();
        })->toArray();
    }

    /**
     * Get all directories
     */
    protected function getAllDirectories(int $projectId): array
    {
        $directories = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->directory->list($projectId, [
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $directories = $directories->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $directories->toArray();
    }

    /**
     * Get all files
     */
    protected function getAllFiles(int $projectId, int $directoryId): array
    {
        $files = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->file->list($projectId, [
                'directoryId' => $directoryId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $files = $files->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $files->toArray();
    }

    /**
     * Get all language translations
     */
    protected function getAllLanguageTranslations(int $projectId, int $fileId, string $languageId): Collection
    {
        $translations = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->stringTranslation->listLanguageTranslations($projectId, $languageId, [
                'fileId' => $fileId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $translations = $translations->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $translations;
    }

    /**
     * Get all source strings
     */
    protected function getAllSourceString(int $projectId, int $fileId): Collection
    {
        $sourceStrings = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->sourceString->list($projectId, [
                'fileId' => $fileId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $sourceStrings = $sourceStrings->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $sourceStrings;
    }

    /**
     * Get all approvals
     */
    protected function getApprovals(int $projectId, ?int $fileId = null, ?string $languageId = null): Collection
    {
        $approvals = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->stringTranslation->listApprovals($projectId, [
                'fileId' => $fileId,
                'languageId' => $languageId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $approvals = $approvals->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $approvals;
    }

    /**
     * Add translation
     */
    protected function addTranslation(int $projectId, int $stringId, string $languageId, string $text, ?string $context = null): object
    {
        return $this->crowdin->stringTranslation->create($projectId, [
            'stringId' => $stringId,
            'languageId' => $languageId,
            'text' => $text,
        ]);
    }

    /**
     * Delete translation
     */
    protected function delTranslation(int $projectId, int $translationId): bool
    {
        return $this->crowdin->stringTranslation->delete($projectId, $translationId);
    }

    /**
     * Get all translations
     */
    protected function getAllTranslations(int $projectId, int $stringId, string $languageId): Collection
    {
        $translations = collect([]);
        $page = 1;

        do {
            $response = $this->crowdin->stringTranslation->list($projectId, [
                'stringId' => $stringId,
                'languageId' => $languageId,
                'limit' => 100,
                'offset' => ($page - 1) * 100,
            ]);
            $translations = $translations->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $translations;
    }
}
