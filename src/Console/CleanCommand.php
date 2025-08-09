<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class CleanCommand extends Command
{
    protected $signature = 'ai-translator:clean
        {pattern? : Pattern to match files/keys (e.g., "enums" for */enums.php, "enums.heroes" for specific key)}
        {--l|locale=* : Target locales to clean (e.g. --locale=ko,ja). If not provided, will ask interactively}
        {--s|source= : Source locale to exclude from cleaning}
        {--f|force : Skip confirmation prompt}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove translated strings from locale files to prepare for re-translation';

    protected string $sourceDirectory;
    protected string $sourceLocale;
    protected array $colors = [
        'reset' => "\033[0m",
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'bg_red' => "\033[41m",
        'bg_yellow' => "\033[43m",
        'bg_green' => "\033[42m",
    ];

    public function handle(): int
    {
        $this->displayHeader();
        $this->initializeConfiguration();

        $pattern = $this->argument('pattern');
        $targetLocales = $this->getTargetLocales();
        $isDryRun = $this->option('dry-run');

        if (empty($targetLocales)) {
            $this->error('No target locales selected.');
            return self::FAILURE;
        }

        $stats = $this->analyzePattern($pattern, $targetLocales);
        
        if ($stats['total_strings'] === 0) {
            $this->info('No strings found matching the pattern.');
            return self::SUCCESS;
        }

        $this->displayStats($stats, $pattern, $isDryRun);

        if (!$isDryRun && !$this->confirmDeletion($stats)) {
            $this->displayWarning('⚠ Clean operation cancelled');
            return self::SUCCESS;
        }

        if (!$isDryRun) {
            $this->performClean($pattern, $targetLocales, $stats);
            $this->displaySuccess('✓ Clean operation completed successfully!');
        } else {
            $this->displayInfo('ℹ Dry run completed. No files were modified.');
        }

        return self::SUCCESS;
    }

    protected function initializeConfiguration(): void
    {
        $this->sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $this->sourceLocale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
    }

    protected function getTargetLocales(): array
    {
        if ($this->option('locale')) {
            return is_array($this->option('locale')) 
                ? $this->option('locale') 
                : explode(',', $this->option('locale'));
        }

        return $this->askForTargetLocales();
    }

    protected function askForTargetLocales(): array
    {
        $availableLocales = $this->getAvailableLocales();
        
        if (empty($availableLocales)) {
            $this->error('No target locales available.');
            return [];
        }

        $choices = [];
        foreach ($availableLocales as $locale) {
            $name = LanguageConfig::getLanguageName($locale);
            $choices[] = "{$this->colors['cyan']}{$locale}{$this->colors['reset']} ({$name})";
        }

        $selected = $this->choice(
            'Select target locales to clean (comma-separated numbers for multiple)',
            $choices,
            null,
            null,
            true
        );

        return array_map(function ($choice) {
            preg_match('/^([a-z_]+)/i', strip_tags($choice), $matches);
            return $matches[1] ?? '';
        }, $selected);
    }

    protected function getAvailableLocales(): array
    {
        $locales = [];
        
        // Check PHP directories
        $directories = glob("{$this->sourceDirectory}/*", GLOB_ONLYDIR);
        foreach ($directories as $dir) {
            $locale = basename($dir);
            if ($locale !== $this->sourceLocale) {
                $locales[] = $locale;
            }
        }

        // Check JSON files
        $jsonFiles = glob("{$this->sourceDirectory}/*.json");
        foreach ($jsonFiles as $file) {
            $locale = basename($file, '.json');
            if ($locale !== $this->sourceLocale && !in_array($locale, $locales)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    protected function analyzePattern(?string $pattern, array $locales): array
    {
        $stats = [
            'total_files' => 0,
            'total_strings' => 0,
            'files' => [],
            'locales' => [],
        ];

        foreach ($locales as $locale) {
            $localeStats = [
                'files' => 0,
                'strings' => 0,
                'details' => [],
            ];

            // Analyze PHP files
            $phpFiles = $this->getMatchingPHPFiles($locale, $pattern);
            foreach ($phpFiles as $file) {
                $filePath = "{$this->sourceDirectory}/{$locale}/{$file}";
                if (file_exists($filePath)) {
                    $stringCount = $this->countStringsInFile($filePath, $pattern, 'php');
                    if ($stringCount > 0) {
                        $localeStats['files']++;
                        $localeStats['strings'] += $stringCount;
                        $localeStats['details'][] = [
                            'file' => $file,
                            'type' => 'php',
                            'strings' => $stringCount,
                        ];
                    }
                }
            }

            // Analyze JSON file
            $jsonFile = "{$this->sourceDirectory}/{$locale}.json";
            if (file_exists($jsonFile)) {
                $stringCount = $this->countStringsInFile($jsonFile, $pattern, 'json');
                if ($stringCount > 0) {
                    $localeStats['files']++;
                    $localeStats['strings'] += $stringCount;
                    $localeStats['details'][] = [
                        'file' => "{$locale}.json",
                        'type' => 'json',
                        'strings' => $stringCount,
                    ];
                }
            }

            if ($localeStats['strings'] > 0) {
                $stats['locales'][$locale] = $localeStats;
                $stats['total_files'] += $localeStats['files'];
                $stats['total_strings'] += $localeStats['strings'];
            }
        }

        return $stats;
    }

    protected function getMatchingPHPFiles(string $locale, ?string $pattern): array
    {
        $localeDir = "{$this->sourceDirectory}/{$locale}";
        if (!is_dir($localeDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localeDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($localeDir . '/', '', $file->getPathname());
                
                if ($this->fileMatchesPattern($relativePath, $pattern)) {
                    $files[] = $relativePath;
                }
            }
        }

        return $files;
    }

    protected function fileMatchesPattern(string $file, ?string $pattern): bool
    {
        if (!$pattern) {
            return true; // No pattern means all files
        }

        // Extract file part from pattern (before the first dot)
        $parts = explode('.', $pattern);
        $filePattern = $parts[0];

        // Check if file matches the pattern
        $fileName = basename($file, '.php');
        return $fileName === $filePattern || str_ends_with($file, "/{$filePattern}.php");
    }

    protected function countStringsInFile(string $filePath, ?string $pattern, string $type): int
    {
        if ($type === 'php') {
            $transformer = new PHPLangTransformer();
        } else {
            $transformer = new JSONLangTransformer();
        }

        $data = $transformer->parse($filePath);
        $flat = $transformer->flatten($data);

        if (!$pattern) {
            return count($flat);
        }

        // Check for specific key pattern
        if (str_contains($pattern, '.')) {
            $keyPattern = str_replace('.', '.', $pattern); // Keep dots as is for matching
            $count = 0;
            foreach (array_keys($flat) as $key) {
                if (str_starts_with($key, $keyPattern . '.') || $key === $keyPattern) {
                    $count++;
                }
            }
            return $count;
        }

        // For file pattern, count all strings if file matches
        $fileName = basename($filePath, '.php');
        $fileName = basename($fileName, '.json');
        if ($fileName === $pattern) {
            return count($flat);
        }

        return 0;
    }

    protected function performClean(?string $pattern, array $locales, array $stats): void
    {
        $this->newLine();
        $this->displayInfo('Starting clean operation...');
        
        foreach ($locales as $locale) {
            if (!isset($stats['locales'][$locale])) {
                continue;
            }

            $this->newLine();
            $this->line("{$this->colors['yellow']}Processing locale: {$locale}{$this->colors['reset']}");
            
            $localeStats = $stats['locales'][$locale];
            foreach ($localeStats['details'] as $detail) {
                if ($detail['type'] === 'php') {
                    $filePath = "{$this->sourceDirectory}/{$locale}/{$detail['file']}";
                    $this->cleanPHPFile($filePath, $pattern);
                } else {
                    $filePath = "{$this->sourceDirectory}/{$locale}.json";
                    $this->cleanJSONFile($filePath, $pattern);
                }
                
                $this->line("  {$this->colors['green']}✓{$this->colors['reset']} Cleaned {$detail['strings']} strings from {$detail['file']}");
            }
        }
    }

    protected function cleanPHPFile(string $filePath, ?string $pattern): void
    {
        $transformer = new PHPLangTransformer();
        $data = $transformer->parse($filePath);

        if (!$pattern) {
            // Remove all strings (empty array)
            $transformer->save($filePath, []);
            return;
        }

        // Handle specific key pattern
        if (str_contains($pattern, '.')) {
            $flat = $transformer->flatten($data);
            $keysToRemove = [];
            
            foreach (array_keys($flat) as $key) {
                if (str_starts_with($key, $pattern . '.') || $key === $pattern) {
                    $keysToRemove[] = $key;
                }
            }

            foreach ($keysToRemove as $key) {
                $data = $this->removeKeyFromArray($data, $key);
            }

            $transformer->save($filePath, $data);
            return;
        }

        // For file pattern without specific key, remove entire file if it matches
        $fileName = basename($filePath, '.php');
        if ($fileName === $pattern) {
            $transformer->save($filePath, []);
        }
    }

    protected function cleanJSONFile(string $filePath, ?string $pattern): void
    {
        $transformer = new JSONLangTransformer();
        $data = $transformer->parse($filePath);

        if (!$pattern) {
            // Remove all strings (empty object)
            $transformer->save($filePath, []);
            return;
        }

        // Handle specific key pattern
        if (str_contains($pattern, '.')) {
            $keysToRemove = [];
            
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, $pattern . '.') || $key === $pattern) {
                    $keysToRemove[] = $key;
                }
            }

            foreach ($keysToRemove as $key) {
                unset($data[$key]);
            }

            $transformer->save($filePath, $data);
        }
    }

    protected function removeKeyFromArray(array $array, string $dotKey): array
    {
        $keys = explode('.', $dotKey);
        $current = &$array;
        
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if (!isset($current[$keys[$i]])) {
                return $array;
            }
            $current = &$current[$keys[$i]];
        }
        
        unset($current[$keys[count($keys) - 1]]);
        
        return $array;
    }

    protected function confirmDeletion(array $stats): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm(
            "This will delete {$stats['total_strings']} strings from {$stats['total_files']} files. Continue?",
            false
        );
    }

    protected function displayHeader(): void
    {
        $this->newLine();
        $title = ' Laravel AI Translator - Clean ';
        $line = str_repeat('─', 50);
        
        $this->line($this->colors['yellow'] . $line . $this->colors['reset']);
        $this->line($this->colors['yellow'] . '│' . $this->colors['reset'] . 
                   str_pad($this->colors['bold'] . $title . $this->colors['reset'], 58, ' ', STR_PAD_BOTH) . 
                   $this->colors['yellow'] . '│' . $this->colors['reset']);
        $this->line($this->colors['yellow'] . $line . $this->colors['reset']);
    }

    protected function displayStats(array $stats, ?string $pattern, bool $isDryRun): void
    {
        $this->newLine();
        
        if ($isDryRun) {
            $this->line($this->colors['bg_yellow'] . $this->colors['white'] . ' DRY RUN MODE ' . $this->colors['reset']);
        }
        
        $this->line($this->colors['bold'] . 'Clean Analysis Results' . $this->colors['reset']);
        $this->newLine();
        
        if ($pattern) {
            $this->line("Pattern: {$this->colors['cyan']}{$pattern}{$this->colors['reset']}");
        } else {
            $this->line("Pattern: {$this->colors['cyan']}ALL FILES{$this->colors['reset']}");
        }
        
        $this->line("Total files affected: {$this->colors['yellow']}{$stats['total_files']}{$this->colors['reset']}");
        $this->line("Total strings to delete: {$this->colors['red']}{$stats['total_strings']}{$this->colors['reset']}");
        
        $this->newLine();
        $this->line($this->colors['bold'] . 'Details by locale:' . $this->colors['reset']);
        
        foreach ($stats['locales'] as $locale => $localeStats) {
            $this->newLine();
            $this->line("  {$this->colors['cyan']}{$locale}{$this->colors['reset']} - {$localeStats['strings']} strings in {$localeStats['files']} files");
            
            foreach ($localeStats['details'] as $detail) {
                $this->line("    • {$detail['file']}: {$detail['strings']} strings");
            }
        }
    }

    protected function displaySuccess(string $message): void
    {
        $this->line($this->colors['green'] . $message . $this->colors['reset']);
    }

    protected function displayInfo(string $message): void
    {
        $this->line($this->colors['cyan'] . $message . $this->colors['reset']);
    }

    protected function displayWarning(string $message): void
    {
        $this->line($this->colors['yellow'] . $message . $this->colors['reset']);
    }
}