<?php

use Kargnas\LaravelAiTranslator\Console\FindUnusedTranslations;

beforeEach(function () {
    // Create test directories
    $this->testLangDir = __DIR__.'/../../temp/lang';
    $this->testAppDir = __DIR__.'/../../temp/app';
    
    if (!file_exists($this->testLangDir)) {
        mkdir($this->testLangDir, 0755, true);
    }
    if (!file_exists($this->testAppDir)) {
        mkdir($this->testAppDir, 0755, true);
    }
    
    // Mock config
    config(['ai-translator.source_directory' => $this->testLangDir]);
    
    // Create test translation files
    $enDir = $this->testLangDir.'/en';
    if (!file_exists($enDir)) {
        mkdir($enDir, 0755, true);
    }
    
    // Test PHP translation file
    file_put_contents($enDir.'/test.php', "<?php\n\nreturn [\n    'used_key' => 'Used translation',\n    'unused_key' => 'Unused translation',\n    'nested' => [\n        'used' => 'Used nested',\n        'unused' => 'Unused nested'\n    ]\n];");
    
    // Test JSON translation file
    file_put_contents($this->testLangDir.'/en.json', json_encode([
        'json_used' => 'Used JSON translation',
        'json_unused' => 'Unused JSON translation'
    ]));
    
    // Create test PHP file with translation usage
    file_put_contents($this->testAppDir.'/TestController.php', "<?php\n\nclass TestController {\n    public function index() {\n        echo __('test.used_key');\n        echo trans('test.nested.used');\n        echo __('json_used');\n    }\n}");
});

afterEach(function () {
    // Clean up test directories
    $testDir = __DIR__.'/../../temp';
    if (file_exists($testDir)) {
        exec("rm -rf {$testDir}");
    }
});

it('can find unused translation keys', function () {
    $command = new FindUnusedTranslations();
    
    $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ])
    ->expectsOutput(function ($output) {
        return str_contains($output, 'unused_key') && 
               str_contains($output, 'json_unused') &&
               str_contains($output, 'nested.unused');
    })
    ->assertExitCode(0);
});

it('shows summary when requested', function () {
    $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'summary'
    ])
    ->expectsOutput(function ($output) {
        return str_contains($output, 'Analysis Results') && 
               str_contains($output, 'Total translation keys');
    })
    ->assertExitCode(0);
});

it('handles missing source directory gracefully', function () {
    $this->artisan('ai-translator:find-unused', [
        '--source' => 'nonexistent',
        '--scan-path' => [$this->testAppDir]
    ])
    ->expectsOutput(function ($output) {
        return str_contains($output, 'No translation files found');
    })
    ->assertExitCode(1);
});

it('can export results to file', function () {
    $exportFile = __DIR__.'/../../temp/export.json';
    
    $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--export' => $exportFile
    ])
    ->assertExitCode(0);
    
    expect(file_exists($exportFile))->toBeTrue();
    
    $exportData = json_decode(file_get_contents($exportFile), true);
    expect($exportData)->toHaveKey('unused_keys');
    expect($exportData['unused_keys'])->toBeArray();
});

it('detects various translation patterns', function () {
    // Create file with different translation patterns
    $testFile = $this->testAppDir.'/PatternTest.php';
    file_put_contents($testFile, "<?php\n\nclass PatternTest {\n    public function test() {\n        __('pattern1');\n        trans('pattern2');\n        Lang::get('pattern3');\n        \$t('pattern4');\n    }\n}");
    
    // Create blade file
    $bladeFile = $this->testAppDir.'/test.blade.php';
    file_put_contents($bladeFile, "@lang('blade_pattern')\n{{ __('blade_pattern2') }}");
    
    // Add these patterns to translation file
    $enDir = $this->testLangDir.'/en';
    file_put_contents($enDir.'/patterns.php', "<?php\n\nreturn [\n    'pattern1' => 'Pattern 1',\n    'pattern2' => 'Pattern 2',\n    'pattern3' => 'Pattern 3',\n    'pattern4' => 'Pattern 4',\n    'blade_pattern' => 'Blade Pattern',\n    'blade_pattern2' => 'Blade Pattern 2',\n    'unused_pattern' => 'Unused Pattern'\n];");
    
    $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ])
    ->expectsOutput(function ($output) {
        return str_contains($output, 'unused_pattern') && 
               !str_contains($output, 'pattern1') &&
               !str_contains($output, 'blade_pattern');
    })
    ->assertExitCode(0);
});