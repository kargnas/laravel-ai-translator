<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;

class TranslationService
{
    protected ProjectService $projectService;
    protected LanguageService $languageService;
    protected FileService $fileService;
    protected Command $command;
    protected int $chunkSize;
    protected int $maxContextItems;
    protected bool $showPrompt;

    public function __construct(
        ProjectService $projectService,
        LanguageService $languageService,
        FileService $fileService,
        Command $command,
        int $chunkSize = 30,
        int $maxContextItems = 100,
        bool $showPrompt = false
    ) {
        $this->projectService = $projectService;
        $this->languageService = $languageService;
        $this->fileService = $fileService;
        $this->command = $command;
        $this->chunkSize = $chunkSize;
        $this->maxContextItems = $maxContextItems;
        $this->showPrompt = $showPrompt;
    }

    /**
     * Translate operation execution
     */
    public function translate(): void
    {
        foreach ($this->languageService->getTargetLanguages() as $targetLanguage) {
            $this->command->newLine();
            $this->command->info("\n Translating to {$targetLanguage['name']} ");
            $this->command->line("Locale: {$targetLanguage['locale']}");

            $directoryCount = 0;
            $fileCount = 0;
            $stringCount = 0;
            $translatedCount = 0;

            try {
                // First try to get directories
                $this->command->line("Fetching directories...");
                $directories = $this->fileService->getAllDirectories();
                $this->command->line("Found " . count($directories) . " directories");

                if (empty($directories)) {
                    // If no directories found, try to get files from root
                    $this->command->line("No directories found, searching for files in root...");
                    $files = collect($this->fileService->getAllFiles(0));

                    if ($files->isNotEmpty()) {
                        $this->command->line("Found " . $files->count() . " files in root");
                        $this->processFiles($files, $targetLanguage, $fileCount, $stringCount, $translatedCount);
                    } else {
                        $this->command->warn("No files found in the project.");
                    }
                } else {
                    foreach ($directories as $directory) {
                        $directoryCount++;

                        // Get file list
                        $files = collect($this->fileService->getAllFiles($directory->getId()));

                        if ($files->isEmpty()) {
                            continue;
                        }

                        $this->command->line("\n📁 Directory: {$directory->getName()} ({$directory->getId()})");
                        $this->command->line("    {$files->count()} files found");
                        $this->processFiles($files, $targetLanguage, $fileCount, $stringCount, $translatedCount);
                    }
                }
            } catch (\Exception $e) {
                $this->command->error("Error during translation process: " . $e->getMessage());
                if (config('app.debug')) {
                    $this->command->line($e->getTraceAsString());
                }
                continue;
            }
        }
    }

    /**
     * Process files for translation
     */
    protected function processFiles(Collection $files, array $targetLanguage, int &$fileCount = 0, int &$stringCount = 0, int &$translatedCount = 0): void
    {
        foreach ($files as $file) {
            $fileCount++;
            $this->command->line("  📄 File: {$file->getName()} ({$file->getId()})");

            // Get string and translation information
            $allStrings = $this->fileService->getAllSourceString($file->getId());
            $allTranslations = $this->fileService->getAllLanguageTranslations($file->getId(), $targetLanguage['id']);
            $approvals = $this->fileService->getApprovals($file->getId(), $targetLanguage['id']);

            // Get reference language translations
            $referenceApprovals = $this->getReferenceApprovals($file, $allStrings);

            // Filter untranslated strings
            $untranslatedStrings = $this->filterUntranslatedStrings($allStrings, $approvals, $allTranslations);
            $stringCount += $untranslatedStrings->count();

            $this->command->info("➤ Untranslated: {$untranslatedStrings->count()} strings");

            // Translate in chunks
            $untranslatedStrings
                ->chunk($this->chunkSize)
                ->each(function ($chunk, $chunkIndex) use ($file, $targetLanguage, $untranslatedStrings, $referenceApprovals, &$translatedCount) {
                    $chunkSize = $chunk->count();
                    $this->command->info("✎ Translating chunk " .
                        ($chunkIndex + 1) . "/" . ceil($untranslatedStrings->count() / $this->chunkSize) .
                        " ({$chunkSize} strings)");

                    // Get global translation context
                    $globalContext = $this->getGlobalContext($file, $targetLanguage);

                    // AIProvider setup
                    $translator = $this->createTranslator($file, $chunk, $referenceApprovals, $targetLanguage, $globalContext);

                    try {
                        // Translate
                        $translated = $translator->translate();
                        $translatedCount += count($translated);

                        // Process translation results
                        $this->processTranslationResults($translated, $untranslatedStrings, $targetLanguage);
                    } catch (\Exception $e) {
                        $this->command->error("Translation failed: " . $e->getMessage());
                    }
                });
        }
    }

    /**
     * Get reference language approved translations
     */
    protected function getReferenceApprovals(File $file, Collection $allStrings): Collection
    {
        $referenceApprovals = collect([]);

        foreach ($this->languageService->getReferenceLanguages() as $refLocale) {
            $this->command->line("    ↳ Loading reference language: {$refLocale}");

            $approvals = $this->fileService->getApprovals($file->getId(), $refLocale);
            $refTranslations = $this->fileService->getAllLanguageTranslations($file->getId(), $refLocale);

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
    protected function getGlobalContext(File $file, array $targetLanguage): array
    {
        if ($this->maxContextItems <= 0) {
            return [];
        }

        $contextProvider = new TranslationContextProvider();
        $globalContext = $contextProvider->getGlobalTranslationContext(
            $this->projectService->getSelectedProject()['sourceLanguage']['name'],
            $targetLanguage['name'],
            $file->getName(),
            $this->maxContextItems
        );

        if (!empty($globalContext)) {
            $contextItemCount = collect($globalContext)->map(fn($items) => count($items))->sum();
            $this->command->info("    ℹ Using context: " . count($globalContext) . " files, " . $contextItemCount . " items");
        }

        return $globalContext;
    }

    /**
     * AIProvider setup
     */
    protected function createTranslator(File $file, Collection $chunk, Collection $referenceApprovals, array $targetLanguage, array $globalContext): AIProvider
    {
        $translator = new AIProvider(
            filename: $file->getName(),
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
                        'text' => $references->only($this->languageService->getSourceLocale())->first() ?? $string['text'],
                        'context' => $context,
                        'references' => $references->except($this->languageService->getSourceLocale())->toArray(),
                    ],
                ];
            })->toArray(),
            sourceLanguage: $this->projectService->getSelectedProject()['sourceLanguage']['name'],
            targetLanguage: $targetLanguage['name'],
            additionalRules: [],
            globalTranslationContext: $globalContext
        );

        // Set up thinking callbacks
        $translator->setOnThinking(function ($thinking) {
            echo $thinking;
        });

        $translator->setOnThinkingStart(function () {
            $this->command->line("    🧠 AI Thinking...");
        });

        $translator->setOnThinkingEnd(function () {
            $this->command->line("    Thinking completed.");
        });

        // Set up translation progress callback
        $translator->setOnTranslated(function ($item, $status, $translatedItems) use ($chunk) {
            if ($status === 'completed') {
                $totalCount = $chunk->count();
                $completedCount = count($translatedItems);

                $this->command->line("  ⟳ " .
                    $item->key .
                    " → " .
                    $item->translated .
                    " ({$completedCount}/{$totalCount})");
            }
        });

        // Set up prompt logging callback if enabled
        if ($this->showPrompt) {
            $translator->setOnPromptGenerated(function ($prompt, $type) {
                $typeText = match ($type) {
                    'system' => '🤖 System Prompt',
                    'user' => '👤 User Prompt',
                };

                print ("\n    {$typeText}:\n");
                print ("    " . str_replace("\n", "\n    ", $prompt) . "\n");
            });
        }

        return $translator;
    }

    /**
     * Process translation results
     */
    protected function processTranslationResults(array $translated, Collection $untranslatedStrings, array $targetLanguage): void
    {
        $this->command->newLine();
        $this->command->info("🔄 Saving translations to Crowdin...");

        foreach ($translated as $item) {
            $targetString = $untranslatedStrings->where('identifier', $item->key)->first();

            if (!$targetString) {
                $this->command->line("    ↳ Skipping: {$item->key} (Not found)");
                continue;
            }

            $existsTranslations = $this->fileService->getAllTranslations($targetString['id'], $targetLanguage['id']);
            $existsTranslations = $existsTranslations->sortByDesc(fn(StringTranslation $t) => Carbon::make($t->getDataProperty('created_at')))->values();

            // Skip if identical translation exists
            if ($existsTranslations->filter(fn(StringTranslation $t) => $t->getText() === $item->translated)->isNotEmpty()) {
                $this->command->line("    ↳ Skipping: {$item->key} (Duplicate)");
                continue;
            }

            // Delete existing translations by the same user
            $myTransitions = $existsTranslations->filter(fn(StringTranslation $t) => $t->getUser()['id'] === 16501205);

            if ($myTransitions->count() > 0) {
                $this->fileService->deleteTranslation($myTransitions->first()->getId());
            }

            // Add new translation
            $this->command->info("    ✓ Added: {$item->key} => {$item->translated}");

            $this->fileService->addTranslation($targetString['id'], $targetLanguage['id'], $item->translated);
        }

        $this->command->info("✓ All translations have been successfully saved to Crowdin");
    }

    protected function processTranslationResult(string $key, string $translatedText, int &$translatedCount, int $totalCount): void
    {
        try {
            // Add translation to Crowdin
            $this->fileService->addTranslation(
                $this->currentStringId,
                $this->currentLanguageId,
                $translatedText
            );

            // Display success message with green checkmark
            $this->command->line("  ⟳ {$key} → {$translatedText} ({$translatedCount}/{$totalCount})");
            $this->command->info("    ✓ Successfully saved to Crowdin");
            $translatedCount++;
        } catch (\Exception $e) {
            // Display error message with red X
            $this->command->error("    ✗ Failed to save to Crowdin: {$e->getMessage()}");
            $this->command->warn("    Retrying in 5 seconds...");

            // Wait 5 seconds before retry
            sleep(5);

            try {
                // Retry adding translation
                $this->fileService->addTranslation(
                    $this->currentStringId,
                    $this->currentLanguageId,
                    $translatedText
                );

                // Display retry success message
                $this->command->info("    ✓ Successfully saved to Crowdin after retry");
                $translatedCount++;
            } catch (\Exception $retryException) {
                $this->command->error("    ✗ Failed to save to Crowdin after retry: {$retryException->getMessage()}");
            }
        }
    }
}