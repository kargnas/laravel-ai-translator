<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Symfony\Component\Process\Process;

class TranslateStringsParallel extends TranslateStrings
{
    protected $signature = 'ai-translator:translate-parallel
        {--s|source= : Source language to translate from (e.g. --source=en)}
        {--l|locale=* : Target locales to translate (e.g. --locale=ko,ja)}
        {--r|reference= : Reference languages for translation guidance (e.g. --reference=fr,es)}
        {--c|chunk= : Chunk size for translation (e.g. --chunk=100)}
        {--m|max-context= : Maximum number of context items to include (e.g. --max-context=1000)}
        {--force-big-files : Force translation of files with more than 500 strings}
        {--show-prompt : Show the whole AI prompts during translation}
        {--non-interactive : Run in non-interactive mode, using default or provided values}';

    protected $description = 'Translates PHP language files in parallel for multiple locales.';

    public function translate(int $maxContextItems = 100): void
    {
        $specifiedLocales = $this->option('locale');
        $nonInteractive = $this->option('non-interactive');
        $availableLocales = $this->getExistingLocales();

        if (!$nonInteractive && empty($specifiedLocales)) {
            $locales = $availableLocales;
            $this->info($this->colors['green'] . '✓ Selected locales: ' .
                $this->colors['reset'] . $this->colors['bold'] . implode(', ', $locales) .
                $this->colors['reset']);
        } else {
            $locales = !empty($specifiedLocales)
                ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
                : $availableLocales;
        }

        if (empty($locales)) {
            $this->error('No valid locales specified or found for translation.');
            return;
        }

        $queue = [];
        foreach ($locales as $locale) {
            if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                $this->warn('Skipping locale ' . $locale . '.');
                continue;
            }
            $queue[] = $locale;
        }

        $maxProcesses = (int) ($this->option('max-processes') ?? 100); // This doesn't work
        $running = [];

        while (!empty($queue) || !empty($running)) {
            while (count($running) < $maxProcesses && !empty($queue)) {
                $locale = array_shift($queue);
                $process = new Process($this->buildLocaleCommand($locale, $maxContextItems), base_path());
                $process->start();
                $running[$locale] = $process;
                $this->info('▶ Started translation for ' . $locale);
            }

            foreach ($running as $locale => $process) {
                if (!$process->isRunning()) {
                    $this->output->write($process->getOutput());
                    $error = $process->getErrorOutput();
                    if ($error) {
                        $this->error($error);
                    }
                    unset($running[$locale]);
                }
            }

            usleep(100000);
        }

        $this->line(PHP_EOL . $this->colors['green_bg'] . $this->colors['white'] . $this->colors['bold'] . ' All translations completed ' . $this->colors['reset']);
    }

    private function buildLocaleCommand(string $locale, int $maxContextItems): array
    {
        $cmd = [
            'php',
            'artisan',
            'ai-translator:translate',
            '--source=' . $this->sourceLocale,
            '--locale=' . $locale,
            '--chunk=' . $this->chunkSize,
            '--max-context=' . $maxContextItems,
            '--non-interactive',
        ];

        if (!empty($this->referenceLocales)) {
            $cmd[] = '--reference=' . implode(',', $this->referenceLocales);
        }
        if ($this->option('force-big-files')) {
            $cmd[] = '--force-big-files';
        }
        if ($this->option('show-prompt')) {
            $cmd[] = '--show-prompt';
        }

        return $cmd;
    }
}
