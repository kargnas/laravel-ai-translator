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
use GuzzleHttp\Promise as GuzzlePromise;
use GuzzleHttp\Pool;
use React\Promise\Promise;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;

class TranslationService
{
    protected ProjectService $projectService;
    protected LanguageService $languageService;
    protected FileService $fileService;
    protected CrowdinAsyncApiService $asyncApiService;
    protected Command $command;
    protected int $chunkSize;
    protected int $maxContextItems;
    protected bool $showPrompt;
    protected Collection $untranslatedStrings;

    public function __construct(
        ProjectService $projectService,
        LanguageService $languageService,
        FileService $fileService,
        CrowdinAsyncApiService $asyncApiService,
        Command $command,
        int $chunkSize = 30,
        int $maxContextItems = 100,
        bool $showPrompt = false
    ) {
        $this->projectService = $projectService;
        $this->languageService = $languageService;
        $this->fileService = $fileService;
        $this->asyncApiService = $asyncApiService;
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
        $filteredStrings = $allStrings->filter(function (SourceString $sourceString) use ($approvals, $allTranslations) {
            if ($sourceString->isHidden()) {
                return false;
            }

            $approved = $approvals->filter(fn(StringTranslationApproval $ap) => $ap->getStringId() == $sourceString->getId());

            if ($approved->count() > 0) {
                $translation = $allTranslations->filter(fn(LanguageTranslation $t) => $t->getTranslationId() == $approved->first()->getTranslationId())->first();

                if ($translation) {
                    return false;
                }
            }

            return true;
        });

        return $filteredStrings->map(function (SourceString $sourceString) {
            $data = $sourceString->getData();
            // HTML 파일의 경우 text를 identifier로 사용
            if (empty($data['identifier'])) {
                $data['identifier'] = $data['text'];
            }
            return $data;
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
            sourceLanguage: $this->languageService->getSourceLocale(),
            targetLanguage: $targetLanguage['id'],
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
     * Check for duplicate translations
     */
    private function checkDuplicateTranslations(array $translations, array $targetLanguage): array
    {
        $promises = [];
        $duplicates = [];
        $nonDuplicates = [];

        // 모든 번역에 대해 동시에 중복 체크
        foreach ($translations as $item) {
            $targetString = $this->untranslatedStrings->where('identifier', $item->key)->first();
            if (!$targetString) {
                continue;
            }

            $promises[$item->key] = $this->asyncApiService->getClient()->getAsync(
                "projects/{$this->projectService->getProjectId()}/translations",
                [
                    'query' => [
                        'stringId' => $targetString['id'],
                        'languageId' => $targetLanguage['id'],
                        'limit' => 100
                    ]
                ]
            );
        }

        // 병렬로 실행
        $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        // 결과 처리
        foreach ($results as $key => $result) {
            $item = collect($translations)->firstWhere('key', $key);

            if ($result['state'] === 'fulfilled') {
                $response = json_decode($result['value']->getBody(), true);
                $existingTranslations = collect($response['data'] ?? []);

                // 중복 체크 (trim 적용)
                $duplicate = $existingTranslations->first(function ($t) use ($item) {
                    return trim($t['data']['text']) === trim($item->translated);
                });

                if ($duplicate) {
                    $duplicates[] = $item;
                    $this->command->line("    ↳ Skipping: {$item->key} (Duplicate found: {$duplicate['data']['text']}, User: {$duplicate['data']['user']['id']})");
                } else {
                    $nonDuplicates[] = $item;
                    $this->command->line("    ✓ New translation: {$item->key} → {$item->translated}");
                }
            } else {
                // API 호출 실패 시 중복이 아닌 것으로 처리
                $nonDuplicates[] = $item;
                \Log::warning("Failed to check duplicates for {$key}", [
                    'error' => $result['reason']->getMessage()
                ]);
            }
        }

        return [
            'duplicates' => $duplicates,
            'nonDuplicates' => $nonDuplicates,
            'stringMap' => collect($translations)->mapWithKeys(function ($item) {
                $targetString = $this->untranslatedStrings->where('identifier', $item->key)->first();
                return [$item->key => $targetString['id'] ?? null];
            })->filter()->toArray()
        ];
    }

    /**
     * Delete existing translations for current user
     */
    private function deleteExistingTranslations(array $translations, array $targetLanguage, array $stringMap): array
    {
        $currentUserId = $this->asyncApiService->getCurrentUserId();
        $promises = [];
        $deletionPromises = [];

        // 1. 먼저 각 번역의 기존 버전들을 가져옴
        foreach ($translations as $item) {
            if (!isset($stringMap[$item->key])) {
                continue;
            }

            $promises[$item->key] = $this->asyncApiService->getClient()->getAsync(
                "projects/{$this->projectService->getProjectId()}/translations",
                [
                    'query' => [
                        'stringId' => $stringMap[$item->key],
                        'languageId' => $targetLanguage['id'],
                        'limit' => 100
                    ]
                ]
            );
        }

        $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        // 2. 현재 사용자의 번역들을 찾아서 삭제 요청 준비
        foreach ($results as $key => $result) {
            if ($result['state'] === 'fulfilled') {
                $response = json_decode($result['value']->getBody(), true);
                $userTranslations = collect($response['data'] ?? [])
                    ->filter(fn($t) => $t['data']['user']['id'] === $currentUserId);

                foreach ($userTranslations as $translation) {
                    $deletionPromises[] = $this->asyncApiService->getClient()->deleteAsync(
                        "projects/{$this->projectService->getProjectId()}/translations/{$translation['data']['id']}"
                    );
                }

                if ($userTranslations->isNotEmpty()) {
                    $this->command->line("    ↳ Queued {$userTranslations->count()} translation(s) for deletion: {$key}");
                }
            }
        }

        // 3. 모든 삭제 요청을 병렬로 실행
        if (!empty($deletionPromises)) {
            $deletionResults = \GuzzleHttp\Promise\Utils::settle($deletionPromises)->wait();
            $successCount = count(array_filter($deletionResults, fn($r) => $r['state'] === 'fulfilled'));
            $this->command->line("    ✓ Successfully deleted {$successCount} translation(s)");
        }

        return $translations;
    }

    /**
     * Add new translations in batches
     */
    private function addNewTranslations(array $translations, array $targetLanguage, array $stringMap): array
    {
        $validTranslations = array_filter($translations, fn($item) => isset($stringMap[$item->key]));
        $chunks = array_chunk($validTranslations, 10);
        $results = [];
        $promises = [];

        foreach ($validTranslations as $item) {
            $promises[$item->key] = $this->asyncApiService->getClient()->postAsync(
                "projects/{$this->projectService->getProjectId()}/translations",
                [
                    'json' => [
                        'stringId' => $stringMap[$item->key],
                        'languageId' => $targetLanguage['id'],
                        'text' => $item->translated
                    ]
                ]
            );
        }

        // 병렬로 실행
        $promiseResults = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

        foreach ($promiseResults as $key => $result) {
            if ($result['state'] === 'fulfilled') {
                $this->command->line("    ✓ Added: {$key}");
                $results[] = true;
            } else {
                $error = $result['reason']->getMessage();
                if (str_contains($error, 'identical translation')) {
                    $this->command->line("    ↳ Skipping: {$key} (Duplicate)");
                    $results[] = true;
                } else {
                    $this->command->error("    ✗ Failed: {$key} - {$error}");
                    $results[] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Process translation results
     */
    protected function processTranslationResults(array $translated, Collection $untranslatedStrings, array $targetLanguage): void
    {
        $this->untranslatedStrings = $untranslatedStrings;
        $this->command->newLine();
        $this->command->info("🔄 Processing translations...");

        try {
            // 1. 중복 체크
            $this->command->line("    Checking for duplicates...");
            $checkResult = $this->checkDuplicateTranslations($translated, $targetLanguage);
            $duplicateCount = count($checkResult['duplicates']);

            if (empty($checkResult['nonDuplicates'])) {
                $this->command->line("    ℹ All translations are duplicates");
                return;
            }

            // 2. 기존 번역 삭제
            $this->command->line("    Removing existing translations...");
            $this->deleteExistingTranslations($checkResult['nonDuplicates'], $targetLanguage, $checkResult['stringMap']);

            // 3. 새 번역 추가
            $this->command->line("    Adding new translations...");
            $addResults = $this->addNewTranslations($checkResult['nonDuplicates'], $targetLanguage, $checkResult['stringMap']);
            $successCount = count(array_filter($addResults));

            // 4. 결과 요약
            $this->command->newLine();
            $this->command->info("✓ Translation Summary:");
            $this->command->line("    - Total processed: " . count($translated));
            $this->command->line("    - Duplicates skipped: {$duplicateCount}");
            $this->command->line("    - Successfully added: {$successCount}");
            $this->command->line("    - Failed: " . (count($checkResult['nonDuplicates']) - $successCount));

            \Log::info("Translation process completed", [
                'total' => count($translated),
                'duplicates' => $duplicateCount,
                'success' => $successCount,
                'failed' => (count($checkResult['nonDuplicates']) - $successCount)
            ]);
        } catch (\Exception $e) {
            $errorMessage = "Translation processing failed";
            $errorDetails = $e->getMessage();

            \Log::error($errorMessage, [
                'error' => $errorDetails,
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException("{$errorMessage}\nDetails: {$errorDetails}");
        }
    }
}