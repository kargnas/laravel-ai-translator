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
        {--export= : Export results to file}';

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
        $usedKeys = $this->scanForUsedKeys($scanPaths);
        $this->info("Found {$this->colors['green']}" . count($usedKeys) . "{$this->colors['reset']} used translation keys");

        // Step 3: Find unused keys
        $this->line("🧹 Identifying unused translations...");
        $unusedKeys = $this->findUnusedKeys($translationKeys, $usedKeys);
        
        // Step 4: Display results
        $this->displayResults($unusedKeys, $translationKeys, $usedKeys, $format);

        // Step 5: Export if requested
        if ($this->option('export')) {
            $this->exportResults($unusedKeys, $this->option('export'));
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
        
        foreach ($scanPaths as $path) {
            $files = $this->getFilesToScan($path);
            
            $this->withProgressBar($files, function ($file) use (&$usedKeys) {
                $content = file_get_contents($file);
                $keys = $this->extractTranslationKeys($content);
                
                foreach ($keys as $key) {
                    if (!isset($usedKeys[$key])) {
                        $usedKeys[$key] = [];
                    }
                    $usedKeys[$key][] = $file;
                }
            });
        }
        
        $this->newLine();
        
        return $usedKeys;
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
            
            $filename = $file->getFilename();
            $extension = $file->getExtension();
            
            // Check for blade files specifically
            if (str_ends_with($filename, '.blade.php')) {
                $files[] = $file->getPathname();
                continue;
            }
            
            // Check other extensions
            if (in_array($extension, $this->fileExtensions)) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }

    protected function extractTranslationKeys(string $content): array
    {
        $keys = [];
        
        foreach ($this->translationPatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $key) {
                    $key = trim($key);
                    if (!empty($key) && !str_contains($key, '$') && !str_contains($key, '{')) {
                        $keys[] = $key;
                    }
                }
            }
        }
        
        return array_unique($keys);
    }

    protected function findUnusedKeys(array $translationKeys, array $usedKeys): array
    {
        $unusedKeys = [];
        
        foreach ($translationKeys as $key => $data) {
            // Check exact match first
            if (isset($usedKeys[$key])) {
                continue;
            }
            
            // Check partial matches (for nested keys)
            $isUsed = false;
            foreach (array_keys($usedKeys) as $usedKey) {
                if (str_starts_with($key, $usedKey) || str_starts_with($usedKey, $key)) {
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
        
        $this->line("💡 {$this->colors['cyan']}Tip:{$this->colors['reset']} Use --export option to save results for review");
    }

    protected function exportResults(array $unusedKeys, string $filename): void
    {
        $data = [
            'timestamp' => now()->toISOString(),
            'summary' => [
                'total_unused' => count($unusedKeys),
                'analysis_completed' => true
            ],
            'unused_keys' => $unusedKeys
        ];
        
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        try {
            if ($extension === 'json') {
                file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                // Default to CSV format
                $fp = fopen($filename, 'w');
                fputcsv($fp, ['Key', 'Value', 'File', 'Type']);
                
                foreach ($unusedKeys as $key => $keyData) {
                    fputcsv($fp, [
                        $key,
                        $keyData['value'],
                        $keyData['file'],
                        $keyData['type']
                    ]);
                }
                fclose($fp);
            }
            
            $this->info("📁 Results exported to: {$this->colors['cyan']}{$filename}{$this->colors['reset']}");
        } catch (\Exception $e) {
            $this->error("Failed to export results: " . $e->getMessage());
        }
    }
}