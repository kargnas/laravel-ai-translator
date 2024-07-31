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
use Kargnas\LaravelAiTranslator\AI\AIProvider;

class TranslateCrowdin extends Command
{
    protected $signature = 'ai-translator:translate-crowdin';

    protected $description = 'Translate all strings in the selected Crowdin project';

    protected $chunkSize;

    protected Crowdin $crowdin;
    protected array $selectedProject;

    public function __construct() {
        parent::__construct();

        if (!env('CROWDIN_API_KEY')) {
            $this->error('CROWDIN_API_KEY is not set');
            exit(1);
        }

        $this->crowdin = new Crowdin([
            'access_token' => env('CROWDIN_API_KEY'),
        ]);
    }

    public function handle() {
        $this->chunkSize = $this->ask('How many strings to translate at once?', 30);

        $this->choiceProjects();
        $this->translate();
    }

    protected static function getAdditionalRules($locale): array {
        $list = config('ai-translator.additional_rules');
        $locale = strtolower(str_replace('-', '_', $locale));

        if (key_exists($locale, $list)) {
            return $list[$locale];
        } else if (key_exists(substr($locale, 0, 2), $list)) {
            return $list[substr($locale, 0, 2)];
        } else {
            return $list['default'] ?? [];
        }
    }

    private function getAllProjects() {
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

    private function getAllDirectories($projectId) {
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

    private function getAllFiles($projectId, $directoryId) {
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

    /**
     * @param $projectId
     * @param $fileId
     * @param $languageId
     * @return \Illuminate\Support\Collection|LanguageTranslation[]
     */
    private function getAllLanguageTranslations($projectId, $fileId, $languageId) {
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

    private function getAllSourceString($projectId, $fileId) {
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

    private function getApprovals($projectId, $fileId = null, $languageId = null) {
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

    private function addTranslation($projectId, $stringId, $languageId, $text, $context = null) {
        $response = $this->crowdin->stringTranslation->create($projectId, [
            'stringId' => $stringId,
            'languageId' => $languageId,
            'text' => $text,
        ]);

        return $response;
    }

    private function delTranslation($projectId, $translationId) {
        $response = $this->crowdin->stringTranslation->delete($projectId, $translationId);

        return $response;
    }

    private function getAllTranslations($projectId, $stringId, $languageId) {
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

    public function choiceProjects() {
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

    public function translate() {
        $languages = collect($this->selectedProject['targetLanguages'])->sortBy(function ($languagae) {
            $firstOrder = [
                'vi', 'en', 'ko', 'zh', 'ja',
            ];
            if (in_array($languagae['twoLettersCode'], $firstOrder)) {
                return array_search($languagae['twoLettersCode'], $firstOrder);
            }
            return 100;
        })->values();

        foreach ($languages as $targetLanguage) {
            $locale = $targetLanguage['locale'];
            $pluralRules = $targetLanguage['pluralRules'];
            $pluralExamples = $targetLanguage['pluralExamples'];

            $this->info("Translating to {$targetLanguage['name']} ({$targetLanguage['id']})");
            $this->info("  Locale: {$locale}");
            $this->info("  Plural Rules: {$pluralRules}");
            $this->info("  Plural Examples: " . implode(", ", array_keys($pluralExamples)));

            $this->ask('Press any key to continue', 'continue', ['continue', 'c']);

            foreach ($this->getAllDirectories($this->selectedProject['id']) as $directory) {
                $directory = $directory->getData();
                $files = collect($this->getAllFiles($this->selectedProject['id'], $directory['id']))->map(function ($file) {
                    return $file->getData();
                });
                if ($files->count() === 0) continue;

                $this->info("  Directory: {$directory['path']} ({$files->count()} files)");

                foreach ($files as $file) {
                    $this->info("    File: {$file['name']} ({$file['id']})");

                    $allStrings = $this->getAllSourceString($this->selectedProject['id'], $file['id']);
                    $approvals = $this->getApprovals($this->selectedProject['id'], $file['id'], $targetLanguage['id']);

                    $untranslatedStrings = $allStrings
                        ->filter(function (SourceString $sourceString) use ($approvals) {
                            if (!$sourceString->getIdentifier()) return false;

                            if ($sourceString->isHidden()) {
//                                $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (hidden)");
                                return false;
                            }

                            if (!$approvals->filter(fn(StringTranslationApproval $ap) => $ap->getStringId() == $sourceString->getId())->isEmpty()) {
//                                $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (approved)");
                                return false;
                            }

//                            if (!$translations->filter(fn(LanguageTranslation $l) => $l->getStringId() == $sourceString->getId())->isEmpty()) {
//                                $this->line("      Skip: {$sourceString->getIdentifier()}: {$sourceString->getText()} (translated)");
//                                return false;
//                            }

                            return true;
                        })
                        ->map(function (SourceString $sourceString) use ($targetLanguage) {
                            return $sourceString->getData();
                        });

                    $this->info("      Total: {$allStrings->count()} strings");
                    $this->info("      Untranslated: {$untranslatedStrings->count()} strings");

                    $untranslatedStrings
                        ->chunk($this->chunkSize)
                        ->each(function ($chunk) use ($file, $targetLanguage, $untranslatedStrings) {
                            $translator = new AIProvider(
                                filename: $file['name'],
                                strings: $chunk->mapWithKeys(function ($string) {
                                    $context = $string['context'] ?? null;
                                    $context = preg_replace("/[\.\s\->]/", "", $context);
                                    if (preg_replace("/[\.\s\->]/", "", $string['identifier']) === $context) {
                                        $context = null;
                                    }

                                    return [
                                        $string['identifier'] => [
                                            'text' => $string['text'],
                                            'context' => $context,
                                        ],
                                    ];
                                })->toArray(),
                                sourceLanguage: $this->selectedProject['sourceLanguage']['name'],
                                targetLanguage: $targetLanguage['name'],
                                additionalRules: static::getAdditionalRules($targetLanguage['locale']),
                            );

                            $translated = $translator->translate();

                            foreach ($translated as $item) {
                                $targetString = $untranslatedStrings->where('identifier', $item->key)->first();

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

                    // dd($sourceStrings);
                }
            }
        }
    }
}
