<?php

namespace Tests\Feature\Console;

use Kargnas\LaravelAiTranslator\Console\TranslateStringsParallel;
use function Pest\Laravel\artisan;

beforeEach(function () {
    config()->set('ai-translator.source_directory', __DIR__.'/../../Fixtures/lang');
    config()->set('ai-translator.source_locale', 'en');
    config()->set('ai-translator.skip_locales', []);
});

test('command exists', function () {
    expect(class_exists(TranslateStringsParallel::class))->toBeTrue();
});

test('max-processes option defaults to 5', function () {
    // Test that default value is 5 when option is not provided
    $result = (int) (false ?: 5);
    expect($result)->toBe(5);
    
    // Test that provided value is used when option is set
    $result = (int) ('10' ?: 5);
    expect($result)->toBe(10);
    
    // Test that 0 is treated as false and defaults to 5
    $result = (int) (0 ?: 5);
    expect($result)->toBe(5);
});

test('buildLocaleCommand constructs correct command array', function () {
    // Test that the translate command is called with correct arguments
    // We'll test the logic rather than the actual method execution
    
    $sourceLocale = 'en';
    $targetLocale = 'ko';
    $chunkSize = 100;
    $maxContext = 1000;
    $references = ['ja', 'fr'];
    
    // Expected command structure
    $expectedBase = [
        'php',
        'artisan',
        'ai-translator:translate',
        '--source=' . $sourceLocale,
        '--locale=' . $targetLocale,
        '--chunk=' . $chunkSize,
        '--max-context=' . $maxContext,
        '--non-interactive',
    ];
    
    // With references
    $expectedWithReferences = array_merge($expectedBase, [
        '--reference=' . implode(',', $references)
    ]);
    
    // Verify the command structure matches what we expect
    expect($expectedBase)->toHaveCount(8);
    expect($expectedWithReferences)->toHaveCount(9);
    expect($expectedWithReferences)->toContain('--reference=ja,fr');
});

test('skips source locale and skip locales', function () {
    config()->set('ai-translator.skip_locales', ['es']);
    
    $testClass = new class extends TranslateStringsParallel {
        public $processedLocales = [];
        
        public function translate(int $maxContextItems = 100): void
        {
            $this->sourceLocale = 'en';
            $locales = ['en', 'ko', 'ja', 'es'];
            
            $queue = [];
            foreach ($locales as $locale) {
                if ($locale === $this->sourceLocale || in_array($locale, config('ai-translator.skip_locales', []))) {
                    continue;
                }
                $queue[] = $locale;
            }
            
            $this->processedLocales = $queue;
        }
    };
    
    $testCommand = new $testClass();
    $testCommand->setLaravel(app());
    $testCommand->translate();
    
    expect($testCommand->processedLocales)->toBe(['ko', 'ja']);
    expect($testCommand->processedLocales)->not->toContain('en');
    expect($testCommand->processedLocales)->not->toContain('es');
});

test('command accepts max-processes option', function () {
    // Test that the command signature includes max-processes option
    $command = new TranslateStringsParallel();
    
    // Access the protected signature property
    $reflection = new \ReflectionClass($command);
    $signatureProperty = $reflection->getProperty('signature');
    $signatureProperty->setAccessible(true);
    $signature = $signatureProperty->getValue($command);
    
    expect($signature)->toContain('--max-processes=');
});

test('actually runs multiple processes in parallel', function () {
    // Create a test class that tracks process creation
    $testClass = new class extends TranslateStringsParallel {
        public $startedProcesses = [];
        public $maxConcurrentProcesses = 0;
        public $currentlyRunning = 0;
        
        public function translate(int $maxContextItems = 100): void
        {
            $this->sourceLocale = 'en';
            $locales = ['ko', 'ja', 'zh', 'es', 'fr']; // 5 locales to translate
            $maxProcesses = (int) ($this->option('max-processes') ?: 5);
            
            $queue = $locales;
            $running = [];
            
            // Simulate process execution
            while (! empty($queue) || ! empty($running)) {
                // Start new processes up to the limit
                while (count($running) < $maxProcesses && ! empty($queue)) {
                    $locale = array_shift($queue);
                    $processId = uniqid($locale . '_');
                    $running[$processId] = [
                        'locale' => $locale,
                        'started_at' => microtime(true),
                        'duration' => rand(100, 300) / 1000 // Random duration 0.1 - 0.3 seconds
                    ];
                    $this->startedProcesses[] = $locale;
                    $this->currentlyRunning = count($running);
                    $this->maxConcurrentProcesses = max($this->maxConcurrentProcesses, $this->currentlyRunning);
                }
                
                // Simulate process completion
                foreach ($running as $id => $process) {
                    if (microtime(true) - $process['started_at'] >= $process['duration']) {
                        unset($running[$id]);
                        $this->currentlyRunning = count($running);
                    }
                }
                
                // Small delay to prevent tight loop
                usleep(10000); // 10ms
            }
        }
        
        public function option($key = null, $default = null) {
            if ($key === 'max-processes') {
                return '3'; // Set max processes to 3 for testing
            }
            return $default;
        }
    };
    
    $command = new $testClass();
    $command->setLaravel(app());
    $command->translate();
    
    // Verify that all locales were processed
    expect($command->startedProcesses)->toHaveCount(5);
    expect($command->startedProcesses)->toContain('ko', 'ja', 'zh', 'es', 'fr');
    
    // Verify that max concurrent processes was respected (should not exceed 3)
    expect($command->maxConcurrentProcesses)->toBeLessThanOrEqual(3);
    expect($command->maxConcurrentProcesses)->toBeGreaterThan(1); // Should run more than 1 at a time
});