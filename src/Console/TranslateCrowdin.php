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
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageRules;
use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;

class TranslateCrowdin extends Command
{
    protected $signature = 'ai-translator:translate-crowdin
                            {--token= : Crowdin API token}
                            {--organization= : Crowdin organization}
                            {--project= : Crowdin project ID}
                            {--source-language= : Source language code}
                            {--target-language= : Target language code}
                            {--chunk-size=30 : Chunk size for translation}
                            {--max-context-items=100 : Maximum number of context items}';

    protected $description = 'Translate strings in Crowdin';

    protected Crowdin $crowdin;

    protected array $referenceLanguages = [];

    protected string $targetLanguage;

    protected Project $selectedProject;

    protected int $chunkSize;

    public function handle()
    {
        // 옵션 값 가져오기
        $token = $this->option('token');
        $organization = $this->option('organization');
        $projectId = $this->option('project');
        $sourceLanguage = $this->option('source-language');
        $targetLanguage = $this->option('target-language');
        $chunkSize = (int) $this->option('chunk-size') ?: 30;

        // 토큰이 없으면 입력 받기
        if (empty($token)) {
            $token = $this->secret('Enter your Crowdin API token');
        }

        // 조직이 없으면 입력 받기
        if (empty($organization)) {
            $organization = $this->ask('Enter your Crowdin organization');
        }

        $this->crowdinToken = $token;
        $this->crowdinOrganization = $organization;
        $this->chunkSize = $chunkSize;

        // 프로젝트 목록 가져오기
        $projects = $this->getProjects();

        // 프로젝트 선택
        if (!empty($projectId)) {
            $this->selectedProject = collect($projects)->firstWhere('id', $projectId);
            if (empty($this->selectedProject)) {
                $this->error("Project with ID {$projectId} not found.");
                return;
            }
        } else {
            $projectChoices = collect($projects)->mapWithKeys(function ($project) {
                return [$project['id'] => "{$project['name']} ({$project['id']})"];
            })->toArray();

            $selectedProjectId = $this->choice('Select a project', $projectChoices);
            $this->selectedProject = collect($projects)->firstWhere('id', $selectedProjectId);
        }

        // 소스 언어 선택
        if (!empty($sourceLanguage)) {
            $this->selectedProject['sourceLanguage'] = collect($this->selectedProject['targetLanguages'])
                ->firstWhere('name', $sourceLanguage);

            if (empty($this->selectedProject['sourceLanguage'])) {
                $this->error("Source language {$sourceLanguage} not found in project.");
                return;
            }
        }

        // 타겟 언어 선택
        if (!empty($targetLanguage)) {
            $this->selectedTargetLanguages = [
                collect($this->selectedProject['targetLanguages'])
                    ->firstWhere('name', $targetLanguage)
            ];

            if (empty($this->selectedTargetLanguages[0])) {
                $this->error("Target language {$targetLanguage} not found in project.");
                return;
            }
        } else {
            // 기존 로직 유지
            $targetLanguageChoices = collect($this->selectedProject['targetLanguages'])
                ->filter(function ($language) {
                    return $language['id'] !== $this->selectedProject['sourceLanguage']['id'];
                })
                ->mapWithKeys(function ($language) {
                    return [$language['id'] => "{$language['name']} ({$language['id']})"];
                })
                ->toArray();

            $selectedTargetLanguageIds = $this->choice(
                'Select target languages (comma-separated)',
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

        if ($this->ask('Do you want to choose reference languages? (y/n)', 'n') === 'y') {
            $this->referenceLanguages = $this->choiceLanguages("Choose a language to reference when translating, preferably one that has already been vetted and translated to a high quality. You can select multiple languages via ',' (e.g. '1, 2')", true);
        }

        $this->info("Target language: {$this->targetLanguage}");
        if ($this->referenceLanguages) {
            $this->info("Reference languages: " . implode(", ", $this->referenceLanguages));
        }

        $this->translate();
    }

    /**
     * @param $projectId
     * @param $fileId
     * @param $languageId
     * @return \Illuminate\Support\Collection|LanguageTranslation[]
     */
    private function getAllLanguageTranslations($projectId, $fileId, $languageId)
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

    private function getAllSourceString($projectId, $fileId)
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

    private function getApprovals($projectId, $fileId = null, $languageId = null)
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

    private function addTranslation($projectId, $stringId, $languageId, $text, $context = null)
    {
        $response = $this->crowdin->stringTranslation->create($projectId, [
            'stringId' => $stringId,
            'languageId' => $languageId,
            'text' => $text,
        ]);

        return $response;
    }

    private function delTranslation($projectId, $translationId)
    {
        $response = $this->crowdin->stringTranslation->delete($projectId, $translationId);

        return $response;
    }

    private function getAllTranslations($projectId, $stringId, $languageId)
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

    public function choiceProjects()
    {
        $projects = collect($this->getAllProjects())->map(function (Project $project) {
            return $project->getData();
        });

        $selectedProject = $projects->where('name', $this->choice('Select project', $projects->pluck('name')->toArray()))->first();
        if (!$selectedProject) {
            $this->error('Project not found');
            return;
        }

        $this->selectedProject = $selectedProject;
        $this->info("Selected project: {$this->selectedProject['name']} ({$this->selectedProject['id']})");
    }

    public function choiceLanguages($question, $multiple, $default = null)
    {
        $locales = collect($this->selectedProject['targetLanguages'])->sortBy('id')->pluck('id')->values()->toArray();

        $selectedLocales = $this->choice(
            $question,
            $locales,
            $default,
            3,
            $multiple
        );

        return $selectedLocales;
    }

    public function translate()
    {
        $targetLanguage = collect($this->selectedProject['targetLanguages'])->where('id', $this->targetLanguage)->first();

        $locale = $targetLanguage['locale'];
        $pluralRules = $targetLanguage['pluralRules'];
        $pluralExamples = $targetLanguage['pluralExamples'];

        $skipUntil = 'commingSoon.tft.btn';
        $skip = false;

        $this->info("Source Language: {$this->selectedProject['sourceLanguage']['name']} ({$this->selectedProject['sourceLanguage']['id']})");
        $this->info("Translating to {$targetLanguage['name']} ({$targetLanguage['id']})");
        $this->info("  Locale: {$locale}");
        $this->info("  Plural Rules: {$pluralRules}");
        $this->info("  Plural Examples: " . implode(", ", array_keys($pluralExamples)));

        foreach ($this->getAllDirectories($this->selectedProject['id']) as $directory) {
            $directory = $directory->getData();
            $files = collect($this->getAllFiles($this->selectedProject['id'], $directory['id']))->map(function ($file) {
                return $file->getData();
            });
            if ($files->count() === 0)
                continue;

            $this->info("  Directory: {$directory['path']} ({$files->count()} files)");

            foreach ($files as $file) {
                $this->info("    File: {$file['name']} ({$file['id']})");

                $this->line("      Retrieving strings...");
                $allStrings = $this->getAllSourceString($this->selectedProject['id'], $file['id']);

                $this->line("      Retrieving translations...");
                $allTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                $this->line("      Retrieving approvals...");
                $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                $referenceApprovals = collect($this->referenceLanguages)->mapWithKeys(function ($refLocale) use ($allStrings, $file) {
                    $this->line("      Retrieving approvals for reference language...: {$refLocale}");
                    $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $refLocale);

                    $this->line("      Retrieving translations for reference language...: {$refLocale}");
                    $allTranslations = $this->getAllLanguageTranslations($this->selectedProject['id'], $file['id'], $refLocale);

                    return [
                        $refLocale => collect($allStrings)->mapWithKeys(function (SourceString $sourceString) use ($approvals, $allTranslations) {
                            $approved = $approvals->map(fn(StringTranslationApproval $ap) => $ap->getData())->where('stringId', $sourceString->getId())->first();
                            if (!$approved)
                                return [];

                            $approvedTranslation = $allTranslations->map(fn(LanguageTranslation $t) => $t->getData())->where('translationId', $approved['translationId'])->first();
                            if (!$approvedTranslation)
                                return [];

                            return [
                                $sourceString->getIdentifier() => $approvedTranslation['text'],
                            ];
                        }),
                    ];
                });

                $untranslatedStrings = $allStrings
                    ->filter(function (SourceString $sourceString) use ($approvals, $allTranslations, $skipUntil, &$skip) {
                        if (!$sourceString->getIdentifier())
                            return false;
                        if ($skipUntil && $sourceString->getIdentifier() === $skipUntil)
                            $skip = false;
                        if ($skip)
                            return false;

                        if ($sourceString->isHidden()) {
                            return false;
                        }

                        $approved = $approvals->filter(fn(StringTranslationApproval $ap) => $ap->getStringId() == $sourceString->getId());
                        if ($approved->count() > 0) {
                            $translation = $allTranslations->filter(fn(LanguageTranslation $t) => $t->getTranslationId() == $approved->first()->getTranslationId())->first();
                            if ($translation) {
                                $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (approved)");
                                return false;
                            }
                        }

                        return true;
                    })
                    ->map(function (SourceString $sourceString) use ($targetLanguage) {
                        return $sourceString->getData();
                    });

                $this->info("      Untranslated: {$untranslatedStrings->count()} strings");

                $untranslatedStrings
                    ->chunk($this->chunkSize)
                    ->each(function ($chunk) use ($file, $targetLanguage, $untranslatedStrings, $referenceApprovals) {
                        $this->info("Starting translation for {$targetLanguage['name']}...");

                        $contextProvider = new TranslationContextProvider();
                        $maxContextItems = (int) $this->option('max-context-items') ?: 100;
                        $globalContext = $contextProvider->getGlobalTranslationContext(
                            $this->selectedProject['sourceLanguage']['name'],
                            $targetLanguage['name'],
                            basename($file['name']),
                            $maxContextItems
                        );

                        if (!empty($globalContext)) {
                            $this->info(" - Using global context: " . count($globalContext) . " files, " .
                                collect($globalContext)->map(fn($items) => count($items))->sum() . " items");
                        }

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
                                        'text' => $references->only($this->selectedProject['sourceLanguage']['id'])->first() ?? $string['text'],
                                        'context' => $context,
                                        'references' => $references->except($this->selectedProject['sourceLanguage']['id'])->toArray(),
                                    ],
                                ];
                            })->toArray(),
                            sourceLanguage: $this->selectedProject['sourceLanguage']['name'],
                            targetLanguage: $targetLanguage['name'],
                            additionalRules: [],
                            globalTranslationContext: $globalContext
                        );

                        $translated = $translator->translate();

                        foreach ($translated as $item) {
                            $targetString = $untranslatedStrings->where('identifier', $item->key)->first();
                            if (!$targetString) {
                                $this->info("Skipping translation: {$item->key} (Not found)");
                                continue;
                            }

                            $existsTranslations = $this->getAllTranslations($this->selectedProject['id'], $targetString['id'], $targetLanguage['id']);
                            $existsTranslations = $existsTranslations->sortByDesc(fn(StringTranslation $t) => Carbon::make($t->getDataProperty('created_at')))->values();

                            // 같은 번역이 있다면 패스
                            if ($existsTranslations->filter(fn(StringTranslation $t) => $t->getText() === $item->translated)->isNotEmpty()) {
                                $this->info("Skipping translation: {$item->key} [{$targetString['id']}]: {$item->translated} (Duplicated)");
                                continue;
                            }

                            $this->info("Adding translation: {$item->key} [{$targetString['id']}]: {$item->translated}");
                            $myTransitions = $existsTranslations->filter(fn(StringTranslation $t) => $t->getUser()['id'] === 16501205);
                            if ($myTransitions->count() > 0) {
                                $this->delTranslation($this->selectedProject['id'], $myTransitions->first()->getId());
                            }
                            $this->addTranslation($this->selectedProject['id'], $targetString['id'], $targetLanguage['id'], $item->translated);
                        }
                    });
            }
        }
    }

    private function getAllProjects()
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

        return $projects;
    }

    private function getAllDirectories($projectId)
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

        return $directories;
    }

    private function getAllFiles($projectId, $directoryId)
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

        return $files;
    }
}
