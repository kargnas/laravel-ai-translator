<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Symfony\Component\Process\Process;

class TranslateJsonStructuredParallel extends TranslateJsonStructured
{
    protected $signature = 'ai-translator:translate-json-structured-parallel'
        .' {--locale=* : Target language code(s) (e.g., ko, ja, zh-CN). Multiple locales can be specified}'
        .' {--chunk-size=50 : Chunk size for translation}'
        .' {--max-context=100 : Maximum number of context items}'
        .' {--force-big-files : Skip confirmation for files with many strings}'
        .' {--max-processes=5 : Number of languages to translate simultaneously}'
        .' {--show-prompt : Show AI prompts during translation}';

    protected $description = 'Translate JSON files with nested directory structures to multiple languages in parallel';

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->displayHeader();

        // Initialize source directory from config
        $this->sourceDirectory = config('ai-translator.source_directory');
        $this->sourceLocale = config('ai-translator.source_locale', 'en');

        // Get specified locales or use all available
        $specifiedLocales = $this->option('locale');
        $availableLocales = $this->getExistingLocales();

        // Validate and filter locales
        $locales = ! empty($specifiedLocales)
            ? $this->validateAndFilterLocales($specifiedLocales, $availableLocales)
            : $availableLocales;

        // Filter out source locale and skip locales
        $locales = array_filter($locales, function ($locale) {
            return $locale !== $this->sourceLocale && ! in_array($locale, config('ai-translator.skip_locales', []));
        });

        if (empty($locales)) {
            $this->error('No valid locales found for translation.');
            return 1;
        }

        $this->line($this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Starting Parallel Translation '.$this->colors['reset']);
        $this->line($this->colors['yellow'].'Source locale: '.$this->colors['reset'].$this->colors['bold'].$this->sourceLocale.$this->colors['reset']);
        $this->line($this->colors['yellow'].'Target locales: '.$this->colors['reset'].$this->colors['bold'].implode(', ', $locales).$this->colors['reset']);
        $this->line('');

        $maxProcesses = (int) ($this->option('max-processes') ?? 5);
        $queue = $locales;
        $running = [];
        $completed = [];
        $failed = [];

        while (! empty($queue) || ! empty($running)) {
            // Start new processes if under limit
            while (count($running) < $maxProcesses && ! empty($queue)) {
                $locale = array_shift($queue);
                $process = new Process(
                    $this->buildLanguageCommand($locale),
                    base_path()
                );
                $process->setTimeout(null);
                $process->start(function ($type, $buffer) use ($locale) {
                    // Display output in real-time if verbose
                    if ($this->output->isVerbose()) {
                        $this->output->write("[{$locale}] {$buffer}");
                    }
                });
                $running[$locale] = $process;
                $this->info($this->colors['blue'].'▶ Started translation for '.$this->colors['reset'].$this->colors['bold'].$locale.$this->colors['reset']);
            }

            // Check running processes
            foreach ($running as $locale => $process) {
                if (! $process->isRunning()) {
                    // Process completed
                    $output = $process->getOutput();
                    $error = $process->getErrorOutput();
                    
                    if ($process->isSuccessful()) {
                        $completed[] = $locale;
                        $this->info($this->colors['green'].'✓ Completed translation for '.$this->colors['reset'].$this->colors['bold'].$locale.$this->colors['reset']);
                        
                        // Show output if verbose
                        if ($this->output->isVerbose()) {
                            $this->line($output);
                        }
                    } else {
                        $failed[] = $locale;
                        $this->error('✗ Failed translation for '.$locale);
                        if ($error) {
                            $this->error('Error: '.$error);
                        }
                        if ($output) {
                            $this->error('Output: '.$output);
                        }
                    }
                    
                    unset($running[$locale]);
                }
            }

            // Small delay to prevent CPU spinning
            usleep(100000); // 0.1 second
        }

        // Display final summary
        $this->line('');
        $this->line(str_repeat('═', 80));
        $this->line($this->colors['green_bg'].$this->colors['white'].$this->colors['bold'].' Parallel Translation Complete '.$this->colors['reset']);
        $this->line('');
        
        if (! empty($completed)) {
            $this->line($this->colors['green'].'✓ Successfully translated: '.$this->colors['reset'].implode(', ', $completed));
        }
        
        if (! empty($failed)) {
            $this->line($this->colors['red'].'✗ Failed translations: '.$this->colors['reset'].implode(', ', $failed));
        }
        
        $this->line('');
        $this->line($this->colors['yellow'].'Total locales processed: '.$this->colors['reset'].count($locales));
        $this->line($this->colors['green'].'Successful: '.$this->colors['reset'].count($completed));
        $this->line($this->colors['red'].'Failed: '.$this->colors['reset'].count($failed));

        return empty($failed) ? 0 : 1;
    }

    /**
     * Build the command for translating a single language
     */
    private function buildLanguageCommand(string $locale): array
    {
        $cmd = [
            'php',
            '-d',
            'memory_limit=2G',
            'artisan',
            'ai-translator:translate-json-structured',
            '--locale='.$locale,
            '--chunk='.$this->option('chunk-size'),
            '--max-context='.$this->option('max-context'),
        ];

        if ($this->option('force-big-files')) {
            $cmd[] = '--force-big-files';
        }

        if ($this->option('show-prompt')) {
            $cmd[] = '--show-prompt';
        }

        // Add no-interaction flag to prevent prompts in subprocess
        $cmd[] = '--no-interaction';

        return $cmd;
    }

    /**
     * Validate and filter specified locales against available ones
     */
    protected function validateAndFilterLocales(array $specifiedLocales, array $availableLocales): array
    {
        $validLocales = [];
        $invalidLocales = [];

        foreach ($specifiedLocales as $locale) {
            if (in_array($locale, $availableLocales)) {
                $validLocales[] = $locale;
            } else {
                $invalidLocales[] = $locale;
            }
        }

        if (! empty($invalidLocales)) {
            $this->warn('Warning: The following locales are not available and will be skipped: '.implode(', ', $invalidLocales));
        }

        return $validLocales;
    }
}