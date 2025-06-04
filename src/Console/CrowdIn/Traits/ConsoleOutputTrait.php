<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Traits;

trait ConsoleOutputTrait
{
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
        'white_bg' => "\033[47m",
    ];

    /**
     * Display header
     */
    protected function displayHeader(): void
    {
        $this->line("\n".$this->colors['blue_bg'].$this->colors['white'].$this->colors['bold'].' Crowdin AI Translator '.$this->colors['reset']);
        $this->line($this->colors['gray'].'Translating strings using AI technology'.$this->colors['reset']);
        $this->line(str_repeat('─', 80)."\n");
    }

    /**
     * Display error message and stack trace if in debug mode
     */
    protected function displayError(\Exception $e): void
    {
        $this->error('Translation process failed: '.$e->getMessage());
        if (config('app.debug')) {
            $this->line($this->colors['gray'].$e->getTraceAsString().$this->colors['reset']);
        }
    }

    /**
     * Display directory information
     */
    protected function displayDirectoryInfo(string $name, int $id, int $fileCount, int $directoryCount, int $totalDirectories): void
    {
        $this->line($this->colors['purple']."\n📁 Directory: ".
            $this->colors['reset'].$this->colors['bold']."{$name}".
            $this->colors['reset']." ({$id})");
        $this->line($this->colors['gray']."    {$fileCount} files found".$this->colors['reset']);
    }

    /**
     * Display file information
     */
    protected function displayFileInfo(string $name, int $id, int $fileCount): void
    {
        $this->line($this->colors['purple'].'  📄 File: '.
            $this->colors['reset'].$this->colors['bold']."{$name}".
            $this->colors['reset']." ({$id})");
    }

    /**
     * Display translation summary
     */
    protected function displayTranslationSummary(array $targetLanguage, int $directoryCount, int $fileCount, int $stringCount, int $translatedCount): void
    {
        $this->line("\n".str_repeat('─', 80));
        $this->info(" Translation Complete: {$targetLanguage['name']} ");
        $this->line("Directories scanned: {$directoryCount}");
        $this->line("Files processed: {$fileCount}");
        $this->line("Strings found: {$stringCount}");
        $this->line("Strings translated and saved to Crowdin: {$translatedCount}");

        if ($translatedCount > 0) {
            $this->info('✓ All translations have been successfully saved to Crowdin');
        }
    }
}
