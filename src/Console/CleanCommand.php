<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class CleanCommand extends Command
{
    protected $signature = 'ai-translator:clean
        {pattern? : Pattern to match files/keys (e.g., "enums" for */enums.php, "foo/bar" for subdirectory, "enums.heroes" for specific key)}
        {--s|source= : Source locale to exclude from cleaning}
        {--f|force : Skip confirmation prompt}
        {--no-backup : Skip creating backup files}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove translated strings from all locale files (except source) to prepare for re-translation';

    protected string $source_directory;
    protected string $source_locale;
    protected string $backup_directory;
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
        try {
            $this->displayHeader();
            $this->initializeConfiguration();

            $pattern = $this->argument('pattern');
            $target_locales = $this->getAllTargetLocales();
            $is_dry_run = $this->option('dry-run');
            $create_backup = !$this->option('no-backup');

            if (empty($target_locales)) {
                $this->error('No target locales found.');
                return self::FAILURE;
            }

            // Check for existing backup if backup is enabled
            if ($create_backup && !$is_dry_run && $this->backupExists()) {
                $this->error("Backup directory already exists at: {$this->backup_directory}");
                $this->error('Please remove or rename the existing backup directory before proceeding.');
                $this->info('You can skip backup creation with --no-backup flag.');
                return self::FAILURE;
            }

            $stats = $this->analyzePattern($pattern, $target_locales);
            
            if ($stats['total_strings'] === 0) {
                $this->info('No strings found matching the pattern.');
                return self::SUCCESS;
            }

            $this->displayStats($stats, $pattern, $is_dry_run);

            if (!$is_dry_run && !$this->confirmDeletion($stats)) {
                $this->displayWarning('⚠ Clean operation cancelled');
                return self::SUCCESS;
            }

            if (!$is_dry_run) {
                // Create backups if enabled
                if ($create_backup) {
                    $this->createBackups($target_locales, $stats);
                }
                
                $this->performClean($pattern, $target_locales, $stats);
                $this->displaySuccess('✓ Clean operation completed successfully!');
                
                if ($create_backup) {
                    $this->displayInfo("Backups created at: {$this->backup_directory}");
                }
            } else {
                $this->displayInfo('ℹ Dry run completed. No files were modified.');
            }

            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('An error occurred during the clean operation:');
            $this->error($e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            
            return self::FAILURE;
        }
    }

    protected function initializeConfiguration(): void
    {
        $this->source_directory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $this->source_locale = $this->option('source') ?? config('ai-translator.source_locale', 'en');
        $this->backup_directory = $this->source_directory . '/backup';
    }

    protected function backupExists(): bool
    {
        return is_dir($this->backup_directory);
    }

    protected function createBackups(array $locales, array $stats): void
    {
        $this->newLine();
        $this->displayInfo('Creating backups...');
        
        // Create backup directory
        if (!is_dir($this->backup_directory)) {
            if (!mkdir($this->backup_directory, 0755, true)) {
                throw new \RuntimeException("Failed to create backup directory: {$this->backup_directory}");
            }
        }

        // Add timestamp to backup info
        $timestamp = date('Y-m-d_H-i-s');
        $info_file = $this->backup_directory . '/backup_info.txt';
        file_put_contents($info_file, "Backup created: {$timestamp}\n");
        file_put_contents($info_file, "Pattern: " . ($this->argument('pattern') ?? 'ALL') . "\n", FILE_APPEND);
        file_put_contents($info_file, "Locales: " . implode(', ', $locales) . "\n\n", FILE_APPEND);

        foreach ($locales as $locale) {
            if (!isset($stats['locales'][$locale])) {
                continue;
            }

            $locale_stats = $stats['locales'][$locale];
            
            foreach ($locale_stats['details'] as $detail) {
                try {
                    if ($detail['type'] === 'php') {
                        $source_path = "{$this->source_directory}/{$locale}/{$detail['file']}";
                        $backup_path = "{$this->backup_directory}/{$locale}/{$detail['file']}";
                        $this->backupFile($source_path, $backup_path);
                    } else {
                        $source_path = "{$this->source_directory}/{$locale}.json";
                        $backup_path = "{$this->backup_directory}/{$locale}.json";
                        $this->backupFile($source_path, $backup_path);
                    }
                    
                    $this->line("  {$this->colors['green']}✓{$this->colors['reset']} Backed up {$detail['file']}");
                    
                } catch (\Exception $e) {
                    throw new \RuntimeException("Failed to backup {$detail['file']}: " . $e->getMessage());
                }
            }
        }
    }

    protected function backupFile(string $source_path, string $backup_path): void
    {
        if (!file_exists($source_path)) {
            throw new \RuntimeException("Source file does not exist: {$source_path}");
        }

        // Create directory if needed
        $backup_dir = dirname($backup_path);
        if (!is_dir($backup_dir)) {
            if (!mkdir($backup_dir, 0755, true)) {
                throw new \RuntimeException("Failed to create backup directory: {$backup_dir}");
            }
        }

        // Copy the file
        if (!copy($source_path, $backup_path)) {
            throw new \RuntimeException("Failed to copy file from {$source_path} to {$backup_path}");
        }
    }

    protected function getAllTargetLocales(): array
    {
        return $this->getAvailableLocales();
    }


    protected function getAvailableLocales(): array
    {
        $locales = [];
        
        // Check PHP directories
        $directories = glob("{$this->source_directory}/*", GLOB_ONLYDIR);
        foreach ($directories as $dir) {
            $locale = basename($dir);
            // Skip backup directory
            if ($locale !== $this->source_locale && $locale !== 'backup') {
                $locales[] = $locale;
            }
        }

        // Check JSON files
        $json_files = glob("{$this->source_directory}/*.json");
        foreach ($json_files as $file) {
            $locale = basename($file, '.json');
            if ($locale !== $this->source_locale && !in_array($locale, $locales)) {
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
            $locale_stats = [
                'files' => 0,
                'strings' => 0,
                'details' => [],
            ];

            // Analyze PHP files
            $php_files = $this->getMatchingPhpFiles($locale, $pattern);
            foreach ($php_files as $file) {
                $file_path = "{$this->source_directory}/{$locale}/{$file}";
                if (file_exists($file_path)) {
                    try {
                        $string_count = $this->countStringsInFile($file_path, $pattern, 'php');
                        if ($string_count > 0) {
                            $locale_stats['files']++;
                            $locale_stats['strings'] += $string_count;
                            $locale_stats['details'][] = [
                                'file' => $file,
                                'type' => 'php',
                                'strings' => $string_count,
                            ];
                        }
                    } catch (\Exception $e) {
                        $this->warn("Warning: Failed to analyze {$file_path}: " . $e->getMessage());
                    }
                }
            }

            // Analyze JSON file
            $json_file = "{$this->source_directory}/{$locale}.json";
            if (file_exists($json_file)) {
                try {
                    $string_count = $this->countStringsInFile($json_file, $pattern, 'json');
                    if ($string_count > 0) {
                        $locale_stats['files']++;
                        $locale_stats['strings'] += $string_count;
                        $locale_stats['details'][] = [
                            'file' => "{$locale}.json",
                            'type' => 'json',
                            'strings' => $string_count,
                        ];
                    }
                } catch (\Exception $e) {
                    $this->warn("Warning: Failed to analyze {$json_file}: " . $e->getMessage());
                }
            }

            if ($locale_stats['strings'] > 0) {
                $stats['locales'][$locale] = $locale_stats;
                $stats['total_files'] += $locale_stats['files'];
                $stats['total_strings'] += $locale_stats['strings'];
            }
        }

        return $stats;
    }

    protected function getMatchingPhpFiles(string $locale, ?string $pattern): array
    {
        $locale_dir = "{$this->source_directory}/{$locale}";
        if (!is_dir($locale_dir)) {
            return [];
        }

        $files = [];
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($locale_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                // Skip symlinks to avoid potential issues
                if ($file->isLink()) {
                    continue;
                }
                
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative_path = str_replace($locale_dir . '/', '', $file->getPathname());
                    
                    if ($this->fileMatchesPattern($relative_path, $pattern)) {
                        $files[] = $relative_path;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn("Warning: Failed to scan directory {$locale_dir}: " . $e->getMessage());
        }

        return $files;
    }

    protected function fileMatchesPattern(string $file, ?string $pattern): bool
    {
        if (!$pattern) {
            return true; // No pattern means all files
        }

        // Remove .php extension for comparison
        $file_without_ext = preg_replace('/\.php$/', '', $file);
        
        // Check if pattern contains a dot (key pattern)
        if (str_contains($pattern, '.')) {
            // For key patterns like "test.welcome", we need to match the file
            // Extract file part from pattern (before the first dot)
            $parts = explode('.', $pattern);
            $file_pattern = $parts[0];
            
            // Check if it's a subdirectory pattern (contains /)
            if (str_contains($file_pattern, '/')) {
                // Direct path match or ends with pattern
                return $file_without_ext === $file_pattern || 
                       str_ends_with($file_without_ext, "/{$file_pattern}");
            } else {
                // File name only match - check basename without extension
                $file_name = basename($file_without_ext);
                return $file_name === $file_pattern;
            }
        }
        
        // Check for subdirectory pattern (contains /)
        if (str_contains($pattern, '/')) {
            // Exact match or ends with the pattern
            return $file_without_ext === $pattern || 
                   str_ends_with($file_without_ext, "/{$pattern}");
        }
        
        // Simple file name pattern
        $file_name = basename($file_without_ext);
        return $file_name === $pattern || str_ends_with($file_without_ext, "/{$pattern}");
    }

    protected function countStringsInFile(string $file_path, ?string $pattern, string $type): int
    {
        if ($type === 'php') {
            $transformer = new PHPLangTransformer($file_path);
        } else {
            $transformer = new JSONLangTransformer($file_path);
        }

        $flat = $transformer->flatten();

        if (!$pattern) {
            return count($flat);
        }

        // Check for specific key pattern
        if (str_contains($pattern, '.')) {
            // Extract the file pattern and key pattern
            $pattern_parts = explode('.', $pattern);
            $file_pattern = $pattern_parts[0];
            
            // Get the file name without extension
            $file_without_ext = preg_replace('/\.(php|json)$/', '', basename($file_path));
            
            // For subdirectory patterns like foo/bar.key
            if (str_contains($file_pattern, '/')) {
                // Extract the file part and key part
                $file_dir = dirname(str_replace($this->source_directory . '/', '', $file_path));
                $file_relative = ($file_dir === '.' || str_ends_with($file_dir, "/{$file_pattern}")) 
                    ? $file_without_ext 
                    : "{$file_dir}/{$file_without_ext}";
                
                if (!str_ends_with($file_relative, $file_pattern) && $file_relative !== $file_pattern) {
                    return 0;
                }
            } else {
                // Simple file pattern - check if basename matches
                if ($file_without_ext !== $file_pattern) {
                    return 0;
                }
            }
            
            // Now count keys that match the pattern
            $key_pattern = implode('.', array_slice($pattern_parts, 1));
            $count = 0;
            foreach (array_keys($flat) as $key) {
                if (str_starts_with($key, $key_pattern . '.') || $key === $key_pattern) {
                    $count++;
                }
            }
            return $count;
        }

        // For file pattern, count all strings if file matches
        $file_name = basename($file_path, '.php');
        $file_name = basename($file_name, '.json');
        
        // Check subdirectory pattern
        if (str_contains($pattern, '/')) {
            $file_relative = str_replace($this->source_directory . '/', '', $file_path);
            $file_relative = preg_replace('/\.php$/', '', $file_relative);
            $file_relative = preg_replace('/^[^\/]+\//', '', $file_relative); // Remove locale prefix
            
            if ($file_relative === $pattern || str_ends_with($file_relative, "/{$pattern}")) {
                return count($flat);
            }
        } else if ($file_name === $pattern) {
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
            
            $locale_stats = $stats['locales'][$locale];
            foreach ($locale_stats['details'] as $detail) {
                try {
                    if ($detail['type'] === 'php') {
                        $file_path = "{$this->source_directory}/{$locale}/{$detail['file']}";
                        $this->cleanPhpFile($file_path, $pattern);
                    } else {
                        $file_path = "{$this->source_directory}/{$locale}.json";
                        $this->cleanJsonFile($file_path, $pattern);
                    }
                    
                    $this->line("  {$this->colors['green']}✓{$this->colors['reset']} Cleaned {$detail['strings']} strings from {$detail['file']}");
                    
                } catch (\Exception $e) {
                    $this->error("  Failed to clean {$detail['file']}: " . $e->getMessage());
                    throw $e;
                }
            }
        }
    }

    protected function cleanPhpFile(string $file_path, ?string $pattern): void
    {
        if (!is_writable($file_path)) {
            throw new \RuntimeException("File is not writable: {$file_path}");
        }

        $transformer = new PHPLangTransformer($file_path);
        $flat = $transformer->flatten();
        $data = $this->unflattenArray($flat);

        if (!$pattern) {
            // Remove all strings (empty array) but preserve file structure
            $this->savePhpFile($file_path, []);
            return;
        }

        // Handle specific key pattern
        if (str_contains($pattern, '.')) {
            // Extract the file pattern and key pattern
            $pattern_parts = explode('.', $pattern);
            $file_pattern = $pattern_parts[0];
            
            // Get the file name without extension
            $file_without_ext = preg_replace('/\.php$/', '', basename($file_path));
            
            // For subdirectory patterns like foo/bar.key
            if (str_contains($file_pattern, '/')) {
                // Check if this file matches the file pattern
                $file_dir = dirname(str_replace($this->source_directory . '/', '', $file_path));
                $file_relative = ($file_dir === '.' || str_contains($file_dir, $file_pattern)) 
                    ? $file_without_ext 
                    : "{$file_dir}/{$file_without_ext}";
                
                if (!str_ends_with($file_relative, $file_pattern) && $file_relative !== $file_pattern) {
                    return; // File doesn't match pattern
                }
            } else {
                // Simple file pattern - check if basename matches
                if ($file_without_ext !== $file_pattern) {
                    return; // File doesn't match pattern
                }
            }
            
            // Now remove the matching keys
            $key_pattern = implode('.', array_slice($pattern_parts, 1));
            $flat = $transformer->flatten($data);
            $keys_to_remove = [];
            
            foreach (array_keys($flat) as $key) {
                if (str_starts_with($key, $key_pattern . '.') || $key === $key_pattern) {
                    $keys_to_remove[] = $key;
                }
            }

            foreach ($keys_to_remove as $key) {
                $data = $this->removeKeyFromArray($data, $key);
            }
            
            // Clean up any empty arrays after all removals
            $data = $this->cleanEmptyArrays($data);

            $this->savePhpFile($file_path, $data);
            return;
        }

        // For file pattern without specific key
        $file_name = basename($file_path, '.php');
        $file_relative = str_replace($this->source_directory . '/', '', $file_path);
        $file_relative = preg_replace('/\.php$/', '', $file_relative);
        $file_relative = preg_replace('/^[^\/]+\//', '', $file_relative); // Remove locale prefix
        
        // Check subdirectory pattern
        if (str_contains($pattern, '/')) {
            if ($file_relative === $pattern || str_ends_with($file_relative, "/{$pattern}")) {
                // Clear the file but show warning for complete file deletion
                $this->warn("  Warning: Clearing entire file contents for {$file_path}");
                $this->savePhpFile($file_path, []);
            }
        } else if ($file_name === $pattern) {
            // Clear the file but show warning for complete file deletion
            $this->warn("  Warning: Clearing entire file contents for {$file_path}");
            $this->savePhpFile($file_path, []);
        }
    }

    protected function cleanJsonFile(string $file_path, ?string $pattern): void
    {
        if (!is_writable($file_path)) {
            throw new \RuntimeException("File is not writable: {$file_path}");
        }

        $transformer = new JSONLangTransformer($file_path);
        $flat = $transformer->flatten();
        $data = $this->unflattenArray($flat);

        if (!$pattern) {
            // Remove all strings (empty object)
            $this->saveJsonFile($file_path, []);
            return;
        }

        // Handle specific key pattern
        if (str_contains($pattern, '.')) {
            // Extract the file pattern (locale) and key pattern
            $pattern_parts = explode('.', $pattern, 2);
            $locale_pattern = $pattern_parts[0];
            
            // Check if this JSON file matches the locale pattern
            $file_name = basename($file_path, '.json');
            if ($file_name !== $locale_pattern) {
                return; // File doesn't match pattern
            }
            
            // If there's a key pattern, remove matching keys
            if (isset($pattern_parts[1])) {
                $key_pattern = $pattern_parts[1];
                $keys_to_remove = [];
                
                foreach (array_keys($data) as $key) {
                    if (str_starts_with($key, $key_pattern . '.') || $key === $key_pattern) {
                        $keys_to_remove[] = $key;
                    }
                }

                foreach ($keys_to_remove as $key) {
                    unset($data[$key]);
                }
            } else {
                // No key pattern, clear entire file
                $data = [];
            }

            $this->saveJsonFile($file_path, $data);
        }
    }

    protected function removeKeyFromArray(array $array, string $dot_key): array
    {
        $keys = explode('.', $dot_key);
        $current = &$array;
        
        for ($i = 0; $i < count($keys) - 1; $i++) {
            if (!isset($current[$keys[$i]])) {
                return $array;
            }
            $current = &$current[$keys[$i]];
        }
        
        unset($current[$keys[count($keys) - 1]]);
        
        // Clean up empty arrays after removal
        return $this->cleanEmptyArrays($array);
    }

    protected function confirmDeletion(array $stats): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $locale_count = count($stats['locales']);
        return $this->confirm(
            "This will delete {$stats['total_strings']} strings from {$stats['total_files']} files across {$locale_count} locales. Continue?",
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

    protected function displayStats(array $stats, ?string $pattern, bool $is_dry_run): void
    {
        $this->newLine();
        
        if ($is_dry_run) {
            $this->line($this->colors['bg_yellow'] . $this->colors['white'] . ' DRY RUN MODE ' . $this->colors['reset']);
        }
        
        $this->line($this->colors['bold'] . 'Clean Analysis Results' . $this->colors['reset']);
        $this->newLine();
        
        if ($pattern) {
            $this->line("Pattern: {$this->colors['cyan']}{$pattern}{$this->colors['reset']}");
        } else {
            $this->line("Pattern: {$this->colors['cyan']}ALL FILES{$this->colors['reset']}");
        }
        
        $this->line("Source locale (excluded): {$this->colors['green']}{$this->source_locale}{$this->colors['reset']}");
        $this->line("Total locales to clean: {$this->colors['yellow']}" . count($stats['locales']) . "{$this->colors['reset']}");
        $this->line("Total files affected: {$this->colors['yellow']}{$stats['total_files']}{$this->colors['reset']}");
        $this->line("Total strings to delete: {$this->colors['red']}{$stats['total_strings']}{$this->colors['reset']}");
        
        if (!$this->option('no-backup') && !$this->option('dry-run')) {
            $this->line("Backup location: {$this->colors['green']}{$this->backup_directory}{$this->colors['reset']}");
        }
        
        $this->newLine();
        $this->line($this->colors['bold'] . 'Details by locale:' . $this->colors['reset']);
        
        foreach ($stats['locales'] as $locale => $locale_stats) {
            $this->newLine();
            $this->line("  {$this->colors['cyan']}{$locale}{$this->colors['reset']} - {$locale_stats['strings']} strings in {$locale_stats['files']} files");
            
            foreach ($locale_stats['details'] as $detail) {
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

    /**
     * Save PHP language file with proper formatting
     */
    protected function savePhpFile(string $file_path, array $data): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        $source_language = $this->source_locale;
        
        $lines = [
            "<?php\n",
            '/**',
            ' * WARNING: This is an auto-generated file.',
            ' * Do not modify this file manually as your changes will be lost.',
            " * This file was automatically cleaned from {$source_language} at {$timestamp}.",
            " */\n",
            'return '.$this->arrayExport($data, 0).";\n",
        ];

        file_put_contents($file_path, implode("\n", $lines));
    }

    /**
     * Export array to PHP syntax
     */
    protected function arrayExport(array $array, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $output = "[\n";

        $items = [];
        foreach ($array as $key => $value) {
            $formattedKey = is_int($key) ? $key : "'".str_replace("'", "\\'", $key)."'";
            if (is_array($value)) {
                $items[] = $indent."    {$formattedKey} => ".$this->arrayExport($value, $level + 1);
            } else {
                $formattedValue = "'".str_replace("'", "\\'", $value)."'";
                $items[] = $indent."    {$formattedKey} => {$formattedValue}";
            }
        }

        $output .= implode(",\n", $items);
        $output .= "\n".$indent.']';

        return $output;
    }

    /**
     * Save JSON language file with proper formatting
     */
    protected function saveJsonFile(string $file_path, array $data): void
    {
        $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($file_path, json_encode($data, $json_options) . "\n");
    }

    /**
     * Convert flattened array with dot notation to nested array
     */
    protected function unflattenArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $parts = explode('.', $key);
            $current = &$result;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $current[$part] = $value;
                } else {
                    if (!isset($current[$part]) || !is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        return $result;
    }

    /**
     * Recursively remove empty arrays from the data structure
     */
    protected function cleanEmptyArrays(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->cleanEmptyArrays($value);
                if (empty($value)) {
                    unset($array[$key]);
                }
            }
        }
        
        return $array;
    }
}