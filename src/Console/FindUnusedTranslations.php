<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

class FindUnusedTranslations extends Command
{
    protected $signature = 'ai-translator:find-unused
        {--source=en : Source language directory to analyze}
        {--scan-path=* : Directories to scan for translation usage (default: app, resources/views)}
        {--format=table : Output format (table, json, summary)}
        {--show-files : Show which files unused translations come from}
        {--f|force : Automatically delete without confirmation}';

    protected $description = 'Find unused translation keys by scanning PHP files and templates';

    protected array $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m",
        'bold' => "\033[1m",
        'dim' => "\033[2m",
        'bg_green' => "\033[42m",
        'bg_blue' => "\033[44m",
        'bg_yellow' => "\033[43m",
        'bg_red' => "\033[41m",
    ];

    protected array $translationPatterns = [
        // Laravel translation functions
        '/__\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        '/trans\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        '/Lang::get\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        '/@lang\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        // Vue i18n patterns
        '/\$t\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        '/\$tc\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        // Blade directive patterns
        '/@t\(\s*[\'"]([^\'"\)]+)[\'"][\s,\)]*.*?\)/i',
        // Template literal patterns for dynamic keys
        '/__\(\s*`([^`]+)`[\s,\)]*.*?\)/i',
        '/trans\(\s*`([^`]+)`[\s,\)]*.*?\)/i',
        '/\$t\(\s*`([^`]+)`[\s,\)]*.*?\)/i',
        '/t\(\s*`([^`]+)`[\s,\)]*.*?\)/i',
    ];

    protected array $fileExtensions = [
        'php',
        'blade.php',
        'vue',
        'js',
        'ts',
        'jsx',
        'tsx',
    ];

    public function handle(): int
    {
        $this->displayHeader();

        $sourceLocale = $this->option('source');
        $scanPaths = $this->getScanPaths();
        $format = $this->option('format');
        
        $this->info("🔍 Analyzing translations for locale: {$this->colors['cyan']}{$sourceLocale}{$this->colors['reset']}");
        $this->info("📁 Scanning paths: {$this->colors['dim']}" . implode(', ', $scanPaths) . "{$this->colors['reset']}");
        $this->newLine();

        // Step 1: Get all translation keys from language files
        $this->line("📚 Loading translation keys...");
        $translationKeys = $this->getTranslationKeys($sourceLocale);
        
        if (empty($translationKeys)) {
            $this->error("No translation files found for locale '{$sourceLocale}'");
            return self::FAILURE;
        }
        
        $this->info("Found {$this->colors['green']}" . count($translationKeys) . "{$this->colors['reset']} translation keys");

        // Step 2: Scan files for translation usage
        $this->line("🔍 Scanning for translation usage...");
        $usageData = $this->scanForUsedKeys($scanPaths);
        $staticCount = count($usageData['static']);
        $dynamicCount = count($usageData['dynamic_prefixes']);
        $this->info("Found {$this->colors['green']}{$staticCount}{$this->colors['reset']} static translation keys");
        if ($dynamicCount > 0) {
            $this->info("Found {$this->colors['cyan']}{$dynamicCount}{$this->colors['reset']} dynamic translation patterns");
        }

        // Step 3: Find unused keys
        $this->line("🧹 Identifying unused translations...");
        $unusedKeys = $this->findUnusedKeys($translationKeys, $usageData);
        
        // Step 4: Display results
        $this->displayResults($unusedKeys, $translationKeys, $usageData['static'], $format);

        // Step 5: Ask about deletion if there are unused keys
        if (!empty($unusedKeys)) {
            $this->handleDeletion($unusedKeys, $sourceLocale);
        }

        return self::SUCCESS;
    }

    protected function displayHeader(): void
    {
        $this->newLine();
        $title = ' Laravel AI Translator - Unused Translation Finder ';
        $line = str_repeat('─', 60);
        
        $this->line($this->colors['blue'] . $line . $this->colors['reset']);
        $this->line($this->colors['blue'] . '│' . $this->colors['reset'] . 
                   str_pad($this->colors['bold'] . $title . $this->colors['reset'], 68, ' ', STR_PAD_BOTH) . 
                   $this->colors['blue'] . '│' . $this->colors['reset']);
        $this->line($this->colors['blue'] . $line . $this->colors['reset']);
        $this->newLine();
    }

    protected function getScanPaths(): array
    {
        $paths = $this->option('scan-path');
        
        if (empty($paths)) {
            $paths = ['app', 'resources/views'];
        }

        return array_filter($paths, function ($path) {
            if (!is_dir($path)) {
                $this->warn("Directory does not exist: {$path}");
                return false;
            }
            return true;
        });
    }

    protected function getTranslationKeys(string $locale): array
    {
        $sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $localeDir = "{$sourceDirectory}/{$locale}";
        
        if (!is_dir($localeDir)) {
            return [];
        }

        $translationKeys = [];
        
        // Scan PHP files
        $phpFiles = glob("{$localeDir}/*.php");
        foreach ($phpFiles as $file) {
            try {
                $transformer = new PHPLangTransformer($file);
                $keys = $transformer->flatten();
                $filename = basename($file, '.php');
                
                foreach ($keys as $key => $value) {
                    $fullKey = "{$filename}.{$key}";
                    $translationKeys[$fullKey] = [
                        'file' => $file,
                        'key' => $key,
                        'value' => $value,
                        'type' => 'php'
                    ];
                }
            } catch (\Exception $e) {
                $this->warn("Failed to load PHP file: {$file}");
            }
        }

        // Scan JSON files
        $jsonFiles = glob("{$sourceDirectory}/*.json");
        foreach ($jsonFiles as $file) {
            $filename = basename($file, '.json');
            if ($filename === $locale) {
                try {
                    $transformer = new JSONLangTransformer($file);
                    $keys = $transformer->flatten();
                    
                    foreach ($keys as $key => $value) {
                        $translationKeys[$key] = [
                            'file' => $file,
                            'key' => $key,
                            'value' => $value,
                            'type' => 'json'
                        ];
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed to load JSON file: {$file}");
                }
            }
        }

        return $translationKeys;
    }

    protected function scanForUsedKeys(array $scanPaths): array
    {
        $usedKeys = [];
        $dynamicPrefixes = [];
        
        foreach ($scanPaths as $path) {
            $files = $this->getFilesToScan($path);
            
            $this->withProgressBar($files, function ($file) use (&$usedKeys, &$dynamicPrefixes) {
                $content = file_get_contents($file);
                $extracted = $this->extractTranslationKeys($content, $file);
                
                foreach ($extracted['static'] as $key) {
                    if (!isset($usedKeys[$key])) {
                        $usedKeys[$key] = [];
                    }
                    $usedKeys[$key][] = $file;
                }
                
                foreach ($extracted['dynamic_prefixes'] as $prefix) {
                    if (!isset($dynamicPrefixes[$prefix])) {
                        $dynamicPrefixes[$prefix] = [];
                    }
                    $dynamicPrefixes[$prefix][] = $file;
                }
            });
        }
        
        $this->newLine();
        
        return ['static' => $usedKeys, 'dynamic_prefixes' => $dynamicPrefixes];
    }

    protected function getFilesToScan(string $path): array
    {
        $files = [];
        
        if (is_file($path)) {
            return [$path];
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            // Skip files in backup directories
            $pathname = $file->getPathname();
            if (str_contains($pathname, '/backup') || str_contains($pathname, '\\backup')) {
                continue;
            }
            
            $filename = $file->getFilename();
            $extension = $file->getExtension();
            
            // Check for blade files specifically
            if (str_ends_with($filename, '.blade.php')) {
                $files[] = $pathname;
                continue;
            }
            
            // Check other extensions
            if (in_array($extension, $this->fileExtensions)) {
                $files[] = $pathname;
            }
        }
        
        return $files;
    }

    protected function extractTranslationKeys(string $content, string $filePath): array
    {
        $staticKeys = [];
        $dynamicPrefixes = [];
        
        // Remove commented lines based on file type to avoid false positives
        $contentWithoutComments = $this->removeCommentedCode($content, $filePath);
        
        foreach ($this->translationPatterns as $pattern) {
            if (preg_match_all($pattern, $contentWithoutComments, $matches)) {
                foreach ($matches[1] as $key) {
                    $key = trim($key);
                    
                    if (empty($key)) {
                        continue;
                    }
                    
                    // Check if it's a dynamic key with template literals
                    if (preg_match('/\$\{[^}]+\}/', $key)) {
                        // Extract the static prefix from dynamic keys
                        // Examples:
                        // "enums.hero.${heroId}" -> "enums.hero."
                        // "errors.${type}.${code}" -> "errors."
                        $parts = preg_split('/\.?\$\{/', $key, 2);
                        if (!empty($parts[0])) {
                            $prefix = rtrim($parts[0], '.');
                            if ($prefix) {
                                $dynamicPrefixes[] = $prefix;
                            }
                        }
                    } elseif (str_contains($key, '$') || str_contains($key, '{')) {
                        // Skip other types of variables (PHP variables, etc.)
                        continue;
                    } else {
                        // Static key
                        $staticKeys[] = $key;
                    }
                }
            }
        }
        
        return [
            'static' => array_unique($staticKeys),
            'dynamic_prefixes' => array_unique($dynamicPrefixes)
        ];
    }
    
    protected function removeCommentedCode(string $content, string $filePath): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filename = basename($filePath);
        
        // Determine file type and apply appropriate comment removal
        if (str_ends_with($filename, '.blade.php')) {
            // Blade files: PHP + Blade comments + HTML comments
            $content = $this->removePhpComments($content);
            $content = preg_replace('/\{\{--.*?--\}\}/s', '', $content); // Blade comments
            $content = preg_replace('/<!--.*?-->/s', '', $content); // HTML comments
        } elseif ($extension === 'php') {
            // PHP files: PHP comments only
            $content = $this->removePhpComments($content);
        } elseif (in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
            // JavaScript/TypeScript files: JS comments + JSX comments
            $content = $this->removeJsComments($content);
            if (in_array($extension, ['jsx', 'tsx'])) {
                // Also remove JSX comments in JSX/TSX files
                $content = preg_replace('/\{\s*\/\*.*?\*\/\s*\}/s', '', $content); // {/* comment */}
            }
        } elseif ($extension === 'vue') {
            // Vue files: JS comments + HTML comments
            $content = $this->removeJsComments($content);
            $content = preg_replace('/<!--.*?-->/s', '', $content); // HTML comments
        } elseif ($extension === 'json') {
            // JSON files: no comments allowed, return as-is
            // JSON doesn't support comments
            return $content;
        }
        
        return $content;
    }
    
    protected function removePhpComments(string $content): string
    {
        // Remove single-line comments (// and #)
        $content = preg_replace('/\/\/.*$/m', '', $content);
        $content = preg_replace('/^\s*#.*$/m', '', $content);
        
        // Remove multi-line comments (/* ... */)
        $content = preg_replace('/\/\*[^*]*\*+(?:[^\/*][^*]*\*+)*\//', '', $content);
        
        return $content;
    }
    
    protected function removeJsComments(string $content): string
    {
        // Remove single-line comments (//)
        $content = preg_replace('/\/\/.*$/m', '', $content);
        
        // Remove multi-line comments (/* ... */)
        $content = preg_replace('/\/\*[^*]*\*+(?:[^\/*][^*]*\*+)*\//', '', $content);
        
        return $content;
    }

    protected function findUnusedKeys(array $translationKeys, array $usageData): array
    {
        $usedKeys = $usageData['static'] ?? [];
        $dynamicPrefixes = $usageData['dynamic_prefixes'] ?? [];
        $unusedKeys = [];
        
        foreach ($translationKeys as $key => $data) {
            // Check exact match first
            if (isset($usedKeys[$key])) {
                continue;
            }
            
            // Check if this key matches any dynamic prefix pattern
            $isUsedByDynamic = false;
            foreach ($dynamicPrefixes as $prefix => $files) {
                // Check if the translation key starts with a dynamic prefix
                // e.g., "enums.hero.warrior" starts with "enums.hero"
                if (str_starts_with($key, $prefix . '.')) {
                    $isUsedByDynamic = true;
                    break;
                }
            }
            
            if ($isUsedByDynamic) {
                continue;
            }
            
            // Check partial matches (for nested keys)
            $isUsed = false;
            foreach (array_keys($usedKeys) as $usedKey) {
                // Only check if the full key is a prefix of the used key
                // This prevents false positives like "user" matching "users"
                if (str_starts_with($usedKey, $key . '.')) {
                    $isUsed = true;
                    break;
                }
            }
            
            if (!$isUsed) {
                $unusedKeys[$key] = $data;
            }
        }
        
        return $unusedKeys;
    }

    protected function displayResults(array $unusedKeys, array $translationKeys, array $usedKeys, string $format): void
    {
        $this->newLine();
        $this->line($this->colors['bg_blue'] . $this->colors['white'] . ' Analysis Results ' . $this->colors['reset']);
        $this->newLine();
        
        $totalKeys = count($translationKeys);
        $usedCount = count($usedKeys);
        $unusedCount = count($unusedKeys);
        $usagePercentage = $totalKeys > 0 ? round(($usedCount / $totalKeys) * 100, 1) : 0;
        
        $this->line("📊 {$this->colors['bold']}Summary:{$this->colors['reset']}");
        $this->line("   Total translation keys: {$this->colors['cyan']}{$totalKeys}{$this->colors['reset']}");
        $this->line("   Used translation keys: {$this->colors['green']}{$usedCount}{$this->colors['reset']}");
        $this->line("   Unused translation keys: {$this->colors['yellow']}{$unusedCount}{$this->colors['reset']}");
        $this->line("   Usage percentage: {$this->colors['magenta']}{$usagePercentage}%{$this->colors['reset']}");
        $this->newLine();
        
        if ($unusedCount === 0) {
            $this->line("{$this->colors['green']}🎉 Great! All translation keys are being used.{$this->colors['reset']}");
            return;
        }
        
        switch ($format) {
            case 'json':
                $this->displayJsonResults($unusedKeys);
                break;
            case 'summary':
                $this->displaySummaryResults($unusedKeys);
                break;
            default:
                $this->displayTableResults($unusedKeys);
                break;
        }
        
        $this->displaySuggestions($unusedKeys);
    }

    protected function displayTableResults(array $unusedKeys): void
    {
        if (empty($unusedKeys)) {
            return;
        }
        
        $this->line("{$this->colors['yellow']}📋 Unused Translation Keys:{$this->colors['reset']}");
        $this->newLine();
        
        $headers = ['Translation Key', 'Value'];
        if ($this->option('show-files')) {
            $headers[] = 'File';
        }
        
        $rows = [];
        foreach ($unusedKeys as $key => $data) {
            $value = mb_strlen($data['value']) > 50 
                ? mb_substr($data['value'], 0, 47) . '...' 
                : $data['value'];
                
            $row = [$key, $value];
            if ($this->option('show-files')) {
                $row[] = basename($data['file']);
            }
            $rows[] = $row;
        }
        
        $this->table($headers, $rows);
    }

    protected function displayJsonResults(array $unusedKeys): void
    {
        $this->line(json_encode($unusedKeys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function displaySummaryResults(array $unusedKeys): void
    {
        if (empty($unusedKeys)) {
            return;
        }
        
        $groupedByFile = [];
        foreach ($unusedKeys as $key => $data) {
            $file = basename($data['file']);
            if (!isset($groupedByFile[$file])) {
                $groupedByFile[$file] = 0;
            }
            $groupedByFile[$file]++;
        }
        
        $this->line("{$this->colors['yellow']}📋 Unused Keys by File:{$this->colors['reset']}");
        foreach ($groupedByFile as $file => $count) {
            $this->line("   {$file}: {$this->colors['yellow']}{$count}{$this->colors['reset']} unused keys");
        }
    }

    protected function displaySuggestions(array $unusedKeys): void
    {
        if (empty($unusedKeys)) {
            return;
        }
        
        $this->newLine();
        $this->line($this->colors['bg_yellow'] . $this->colors['white'] . ' Cleanup Suggestions ' . $this->colors['reset']);
        $this->newLine();
        
        $this->line("🧹 {$this->colors['bold']}Consider these cleanup actions:{$this->colors['reset']}");
        $this->line("   1. Review unused keys to ensure they're truly not needed");
        $this->line("   2. Remove unused keys to reduce file size and improve maintainability");
        $this->line("   3. Check if keys are used dynamically (not detectable by static analysis)");
        $this->line("   4. Consider organizing keys into more logical groups");
        $this->newLine();
        
        if (count($unusedKeys) > 10) {
            $this->warn("⚠️  Large number of unused keys detected. Consider gradual cleanup.");
        }
        
        $this->line("💡 {$this->colors['cyan']}Tip:{$this->colors['reset']} The command will ask if you want to delete unused keys");
        $this->line("💡 {$this->colors['cyan']}Tip:{$this->colors['reset']} Use --force to delete without confirmation");
    }

    protected function handleDeletion(array $unusedKeys, string $sourceLocale): void
    {
        $totalKeys = count($unusedKeys);
        
        // If force option is not set, ask for confirmation
        if (!$this->option('force')) {
            $this->newLine();
            if (!$this->confirm("Do you want to delete {$totalKeys} unused translation keys?", false)) {
                $this->info('No action taken.');
                return;
            }
        }
        
        $this->newLine();
        $this->line($this->colors['bg_red'] . $this->colors['white'] . ' ⚠️  WARNING - BETA FEATURE ' . $this->colors['reset']);
        $this->newLine();
        $this->warn("This feature is in BETA. Data loss may occur!");
        $this->newLine();
        
        $this->info("This will delete {$this->colors['red']}{$totalKeys}{$this->colors['reset']} unused translation keys.");
        
        if (!$this->option('force')) {
            if (!$this->confirm('Are you absolutely sure you want to proceed with deletion?', false)) {
                $this->info('Deletion cancelled.');
                return;
            }
        }
        
        // Create backup before deletion
        $backupDir = $this->createBackup();
        if ($backupDir) {
            $this->info("✓ Backup created at: {$this->colors['green']}{$backupDir}{$this->colors['reset']}");
        } else {
            $this->error('Failed to create backup.');
            if (!$this->option('force') && !$this->confirm('Continue without backup?', false)) {
                $this->info('Deletion cancelled.');
                return;
            }
        }
        
        $this->newLine();
        $this->info('Preparing to delete unused translations...');
        
        // Group unused keys by file for CleanCommand pattern
        $patterns = $this->prepareCleanPatterns($unusedKeys);
        
        // First, delete from source language files
        $this->newLine();
        $this->info("Deleting from source language ({$sourceLocale})...");
        $this->deleteFromSourceLanguage($unusedKeys, $sourceLocale);
        
        // Then, delete from other languages using CleanCommand
        $this->newLine();
        $this->info('Deleting from other languages...');
        
        $progressBar = $this->output->createProgressBar(count($patterns));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Deleting unused keys...');
        $progressBar->start();
        
        foreach ($patterns as $pattern) {
            $progressBar->setMessage("Deleting: {$pattern}");
            $this->callSilently('ai-translator:clean', [
                'pattern' => $pattern,
                '--source' => $sourceLocale,
                '--force' => true,
                '--no-backup' => true  // Disable CleanCommand's backup as we already created one
            ]);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        
        $this->newLine();
        $this->line("{$this->colors['green']}✓ Successfully deleted {$totalKeys} unused translation keys.{$this->colors['reset']}");
        if ($backupDir) {
            $this->info("Backup saved at: {$backupDir}");
        }
    }
    
    protected function prepareCleanPatterns(array $unusedKeys): array
    {
        $patterns = [];
        
        foreach ($unusedKeys as $key => $data) {
            // For PHP files, we need to create a pattern like "filename.keypath"
            // For JSON files, the key is already in the right format
            $patterns[] = $key;
        }
        
        return array_unique($patterns);
    }
    
    protected function createBackup(): ?string
    {
        $sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = "{$sourceDirectory}/backup-before-unused/{$timestamp}";
        
        try {
            // Create backup directory
            if (!is_dir($backupDir)) {
                if (!mkdir($backupDir, 0755, true)) {
                    return null;
                }
            }
            
            // Copy all language directories and files
            $items = glob("{$sourceDirectory}/*");
            foreach ($items as $item) {
                $basename = basename($item);
                
                // Skip backup directories
                if (str_starts_with($basename, 'backup')) {
                    continue;
                }
                
                if (is_dir($item)) {
                    // Copy directory recursively
                    $this->copyDirectory($item, "{$backupDir}/{$basename}");
                } elseif (is_file($item) && str_ends_with($basename, '.json')) {
                    // Copy JSON file
                    copy($item, "{$backupDir}/{$basename}");
                }
            }
            
            // Create info file
            $infoFile = "{$backupDir}/backup_info.txt";
            $info = "Backup created before unused translation deletion\n";
            $info .= "Date: {$timestamp}\n";
            $info .= "Command: ai-translator:find-unused\n";
            file_put_contents($infoFile, $info);
            
            return $backupDir;
            
        } catch (\Exception $e) {
            $this->error("Backup failed: " . $e->getMessage());
            return null;
        }
    }
    
    protected function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $destination . '/' . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item, $destPath);
            }
        }
    }
    
    protected function deleteFromSourceLanguage(array $unusedKeys, string $sourceLocale): void
    {
        $sourceDirectory = rtrim(config('ai-translator.source_directory', 'lang'), '/');
        $deletedCount = 0;
        $filesModified = [];
        
        // Group keys by file
        $keysByFile = [];
        foreach ($unusedKeys as $key => $data) {
            $file = $data['file'];
            if (!isset($keysByFile[$file])) {
                $keysByFile[$file] = [];
            }
            $keysByFile[$file][$key] = $data;
        }
        
        foreach ($keysByFile as $file => $keys) {
            try {
                // Get the first key's data to determine file type
                $firstKeyData = reset($keys);
                
                if ($firstKeyData['type'] === 'php') {
                    // Handle PHP files
                    $transformer = new PHPLangTransformer($file);
                    $flat = $transformer->flatten();
                    $original = $this->unflattenArray($flat);
                    
                    foreach ($keys as $fullKey => $keyData) {
                        // Remove the file prefix from the key
                        $keyToRemove = $keyData['key'];
                        $original = $this->removeKeyFromArray($original, $keyToRemove);
                        $deletedCount++;
                    }
                    
                    // Clean up empty arrays
                    $original = $this->cleanEmptyArrays($original);
                    
                    // Save the file
                    $this->savePhpFile($file, $original);
                    $filesModified[] = basename($file);
                    
                } elseif ($firstKeyData['type'] === 'json') {
                    // Handle JSON files
                    $transformer = new JSONLangTransformer($file);
                    $data = $transformer->flatten();
                    
                    foreach ($keys as $fullKey => $keyData) {
                        unset($data[$fullKey]);
                        $deletedCount++;
                    }
                    
                    // Save the file
                    $this->saveJsonFile($file, $data);
                    $filesModified[] = basename($file);
                }
            } catch (\Exception $e) {
                $this->error("Failed to delete keys from {$file}: " . $e->getMessage());
            }
        }
        
        if ($deletedCount > 0) {
            $this->info("{$this->colors['green']}✓{$this->colors['reset']} Deleted {$deletedCount} keys from {$sourceLocale} (" . implode(', ', array_unique($filesModified)) . ")");
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
        
        return $array;
    }
    
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
    
    protected function savePhpFile(string $file_path, array $data): void
    {
        $timestamp = date('Y-m-d H:i:s T');
        
        $lines = [
            "<?php\n",
            '/**',
            ' * WARNING: This is an auto-generated file.',
            ' * Do not modify this file manually as your changes will be lost.',
            " * This file was automatically modified at {$timestamp}.",
            " */\n",
            'return '.$this->arrayExport($data, 0).";\n",
        ];

        file_put_contents($file_path, implode("\n", $lines));
    }
    
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
    
    protected function saveJsonFile(string $file_path, array $data): void
    {
        $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($file_path, json_encode($data, $json_options) . "\n");
    }
    
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
}