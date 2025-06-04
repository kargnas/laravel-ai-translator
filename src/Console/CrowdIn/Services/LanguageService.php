<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use Illuminate\Console\Command;

class LanguageService
{
    protected ProjectService $projectService;

    protected Command $command;

    protected string $sourceLocale;

    protected array $targetLanguages = [];

    protected array $referenceLanguages = [];

    public function __construct(ProjectService $projectService, Command $command)
    {
        $this->projectService = $projectService;
        $this->command = $command;
    }

    /**
     * Select languages
     */
    public function selectLanguages(?string $sourceLanguage = null, ?string $targetLanguage = null): bool
    {
        $project = $this->projectService->getSelectedProject();
        if (! $project) {
            $this->command->error('No project selected.');

            return false;
        }

        // Select source language
        if (! empty($sourceLanguage)) {
            foreach ($project['targetLanguages'] as $lang) {
                if ($lang['name'] === $sourceLanguage) {
                    $project['sourceLanguage'] = $lang;
                    break;
                }
            }

            if (empty($project['sourceLanguage'])) {
                $this->command->error("Source language {$sourceLanguage} not found in project.");

                return false;
            }
        }

        $this->sourceLocale = $project['sourceLanguage']['id'];

        // Select target languages
        if (! empty($targetLanguage)) {
            $targetLang = null;
            foreach ($project['targetLanguages'] as $lang) {
                if ($lang['id'] === $targetLanguage || $lang['name'] === $targetLanguage) {
                    $targetLang = $lang;
                    break;
                }
            }

            if ($targetLang) {
                $this->targetLanguages = [$targetLang];
            } else {
                $this->command->error("Target language {$targetLanguage} not found in project.");

                return false;
            }
        } else {
            $targetLanguageChoices = [];
            foreach ($project['targetLanguages'] as $lang) {
                if ($lang['id'] !== $project['sourceLanguage']['id']) {
                    $targetLanguageChoices[$lang['id']] = "{$lang['name']} ({$lang['id']})";
                }
            }

            $selectedTargetLanguageIds = $this->command->choice(
                'Select target languages (comma-separated)',
                $targetLanguageChoices,
                null,
                null,
                true
            );

            $this->targetLanguages = [];
            foreach ($project['targetLanguages'] as $lang) {
                if (in_array($lang['id'], $selectedTargetLanguageIds)) {
                    $this->targetLanguages[] = $lang;
                }
            }
        }

        return true;
    }

    /**
     * Select reference languages
     */
    public function selectReferenceLanguages(): void
    {
        // Skip reference language selection
        $this->referenceLanguages = [];
    }

    /**
     * Get source locale
     */
    public function getSourceLocale(): string
    {
        return $this->sourceLocale;
    }

    /**
     * Get target languages
     */
    public function getTargetLanguages(): array
    {
        return $this->targetLanguages;
    }

    /**
     * Get reference languages
     */
    public function getReferenceLanguages(): array
    {
        return $this->referenceLanguages;
    }
}
