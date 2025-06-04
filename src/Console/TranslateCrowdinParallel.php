<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Symfony\Component\Process\Process;

class TranslateCrowdinParallel extends TranslateCrowdin
{
    protected $signature = 'ai-translator:translate-crowdin-parallel'
        .' {--token= : Crowdin API token (optional, will use CROWDIN_API_KEY env by default)}'
        .' {--organization= : Crowdin organization (optional)}'
        .' {--project= : Crowdin project ID}'
        .' {--source-language= : Source language code}'
        .' {--target-language= : Target language code}'
        .' {--chunk-size=50 : Chunk size for translation}'
        .' {--max-context-items=10000 : Maximum number of context items}'
        .' {--show-prompt : Show AI prompts during translation}'
        .' {--max-processes=5 : Number of languages to translate simultaneously}';

    protected $description = 'Translate Crowdin strings in parallel.';

    public function handle()
    {
        try {
            $token = $this->option('token') ?: env('CROWDIN_API_KEY');
            $organization = $this->option('organization');

            if (empty($token)) {
                $this->warn('No API token provided. You can:');
                $this->line('1. Set CROWDIN_API_KEY in your .env file');
                $this->line('2. Use --token option');
                $this->line('3. Enter it interactively');
                $token = $this->secret('Enter your Crowdin API token');

                if (empty($token)) {
                    throw new \RuntimeException('API token is required to connect to Crowdin.');
                }
            }

            $this->displayHeader();

            $this->initializeServices($token, $organization);

            if (! $this->projectService->selectProject($this->option('project'))) {
                return 1;
            }

            if (! $this->languageService->selectLanguages(
                $this->option('source-language'),
                $this->option('target-language')
            )) {
                return 1;
            }

            $this->languageService->selectReferenceLanguages();

            $languages = $this->languageService->getTargetLanguages();
            $queue = [];
            foreach ($languages as $language) {
                $queue[] = $language['id'];
            }

            $projectId = $this->projectService->getProjectId();
            $sourceLanguage = $this->languageService->getSourceLocale();

            $maxProcesses = (int) ($this->option('max-processes') ?? 5);
            $running = [];

            while (! empty($queue) || ! empty($running)) {
                while (count($running) < $maxProcesses && ! empty($queue)) {
                    $lang = array_shift($queue);
                    $process = new Process(
                        $this->buildLanguageCommand($lang, $token, $organization, $projectId, $sourceLanguage),
                        base_path()
                    );
                    $process->setTimeout(null);
                    $process->start();
                    $running[$lang] = $process;
                    $this->info('▶ Started translation for '.$lang);
                }

                foreach ($running as $lang => $process) {
                    if (! $process->isRunning()) {
                        $this->output->write($process->getOutput());
                        $error = $process->getErrorOutput();
                        if ($error) {
                            $this->error($error);
                        }
                        unset($running[$lang]);
                    }
                }

                usleep(100000);
            }

            $this->line('\n'.$this->colors['green_bg'].$this->colors['white'].$this->colors['bold'].' All translations completed '.$this->colors['reset']);

            return 0;
        } catch (\Exception $e) {
            $this->displayError($e);

            return 1;
        }
    }

    private function buildLanguageCommand(
        string $language,
        string $token,
        ?string $organization,
        ?int $projectId,
        string $sourceLanguage
    ): array {
        $cmd = [
            'php',
            'artisan',
            'ai-translator:translate-crowdin',
            '--token='.$token,
        ];

        if ($organization) {
            $cmd[] = '--organization='.$organization;
        }
        if ($projectId !== null) {
            $cmd[] = '--project='.$projectId;
        }
        $cmd[] = '--source-language='.$sourceLanguage;
        $cmd[] = '--target-language='.$language;
        $cmd[] = '--chunk-size='.$this->option('chunk-size');
        $cmd[] = '--max-context-items='.$this->option('max-context-items');
        if ($this->option('show-prompt')) {
            $cmd[] = '--show-prompt';
        }
        $cmd[] = '--no-interaction';

        return $cmd;
    }
}
