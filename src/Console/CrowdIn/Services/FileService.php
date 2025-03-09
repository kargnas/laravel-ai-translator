<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use CrowdinApiClient\Model\Directory;
use CrowdinApiClient\Model\File;
use CrowdinApiClient\Model\LanguageTranslation;
use CrowdinApiClient\Model\SourceString;
use CrowdinApiClient\Model\StringTranslation;
use CrowdinApiClient\Model\StringTranslationApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FileService
{
    protected CrowdinApiService $apiService;
    protected ProjectService $projectService;
    protected Command $command;

    public function __construct(CrowdinApiService $apiService, ProjectService $projectService, Command $command)
    {
        $this->apiService = $apiService;
        $this->projectService = $projectService;
        $this->command = $command;
    }

    /**
     * Get all directories
     */
    public function getAllDirectories(): array
    {
        try {
            $this->command->line("Fetching directories...");

            $directories = collect([]);
            $page = 1;
            $limit = 100;

            do {
                $response = $this->apiService->getClient()->directory->list($this->projectService->getProjectId(), [
                    'limit' => $limit,
                    'offset' => ($page - 1) * $limit,
                ]);

                if (empty($response)) {
                    break;
                }

                $directories = $directories->merge(collect($response));
                $page++;
            } while (count($response) === $limit);

            $this->command->line("Found " . $directories->count() . " directories");
            return $directories->toArray();
        } catch (\Exception $e) {
            $this->command->warn("No directories found or error occurred: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all files
     */
    public function getAllFiles(int $directoryId): array
    {
        try {
            $this->command->line("    Fetching files in directory {$directoryId}...");

            $files = collect([]);
            $page = 1;
            $limit = 100;

            do {
                $response = $this->apiService->getClient()->file->list($this->projectService->getProjectId(), [
                    'directoryId' => $directoryId,
                    'limit' => $limit,
                    'offset' => ($page - 1) * $limit,
                ]);

                if (empty($response)) {
                    break;
                }

                $files = $files->merge(collect($response));
                $page++;
            } while (count($response) === $limit);

            $this->command->line("    Found " . $files->count() . " files");
            return $files->toArray();
        } catch (\Exception $e) {
            $this->command->warn("    No files found or error occurred: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all source strings
     */
    public function getAllSourceString(int $fileId): Collection
    {
        try {
            $this->command->line("      Fetching source strings for file {$fileId}...");

            $sourceStrings = collect([]);
            $page = 1;
            $limit = 100;

            do {
                $response = $this->apiService->getClient()->sourceString->list($this->projectService->getProjectId(), [
                    'fileId' => $fileId,
                    'limit' => $limit,
                    'offset' => ($page - 1) * $limit,
                ]);

                if (empty($response)) {
                    break;
                }

                $sourceStrings = $sourceStrings->merge(collect($response));
                $page++;
            } while (count($response) === $limit);

            $this->command->line("      Found " . $sourceStrings->count() . " source strings");
            return $sourceStrings;
        } catch (\Exception $e) {
            $this->command->warn("      No source strings found or error occurred: " . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get all language translations
     */
    public function getAllLanguageTranslations(int $fileId, string $languageId): Collection
    {
        try {
            $this->command->line("      Fetching translations for file {$fileId} in language {$languageId}...");

            $translations = collect([]);
            $page = 1;
            $limit = 100;

            do {
                $response = $this->apiService->getClient()->stringTranslation->listLanguageTranslations(
                    $this->projectService->getProjectId(),
                    $languageId,
                    [
                        'fileId' => $fileId,
                        'limit' => $limit,
                        'offset' => ($page - 1) * $limit,
                    ]
                );

                if (empty($response)) {
                    break;
                }

                $translations = $translations->merge(collect($response));
                $page++;
            } while (count($response) === $limit);

            $this->command->line("      Found " . $translations->count() . " translations");
            return $translations;
        } catch (\Exception $e) {
            $this->command->warn("      No translations found or error occurred: " . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Get all approvals
     */
    public function getApprovals(?int $fileId = null, ?string $languageId = null): Collection
    {
        $approvals = collect([]);
        $page = 1;

        do {
            $response = $this->apiService->getClient()->stringTranslation->listApprovals(
                $this->projectService->getProjectId(),
                [
                    'fileId' => $fileId,
                    'languageId' => $languageId,
                    'limit' => 100,
                    'offset' => ($page - 1) * 100,
                ]
            );
            $approvals = $approvals->merge(collect($response));
            $page++;
        } while (!$response->isEmpty());

        return $approvals;
    }

    /**
     * Add translation
     */
    public function addTranslation(int $stringId, string $languageId, string $text, ?string $context = null): object
    {
        return $this->apiService->getClient()->stringTranslation->create($this->projectService->getProjectId(), [
            'stringId' => $stringId,
            'languageId' => $languageId,
            'text' => $text,
        ]);
    }

    /**
     * Delete translation
     */
    public function deleteTranslation(int $translationId): bool
    {
        try {
            $result = $this->apiService->getClient()->stringTranslation->delete(
                $this->projectService->getProjectId(),
                $translationId
            );
            return $result === null ? true : $result;
        } catch (\Exception $e) {
            $this->command->warn("Failed to delete translation {$translationId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all translations
     */
    public function getAllTranslations(int $stringId, string $languageId): Collection
    {
        $translations = collect([]);
        $page = 1;

        do {
            $response = $this->apiService->getClient()->stringTranslation->list($this->projectService->getProjectId(), [
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