<?php

use Illuminate\Support\Facades\Artisan;
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
    
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ]);
    
    $output = Artisan::output();
    
    expect($output)->toContain('unused_key')
        ->and($output)->toContain('json_unused')
        ->and($output)->toContain('nested.unused');
    
    $result->assertExitCode(0);
});

it('shows summary when requested', function () {
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'summary'
    ]);
    
    $output = Artisan::output();
    
    expect($output)->toContain('Analysis Results')
        ->and($output)->toContain('Total translation keys');
    
    $result->assertExitCode(0);
});

it('handles missing source directory gracefully', function () {
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'nonexistent',
        '--scan-path' => [$this->testAppDir]
    ]);
    
    $output = Artisan::output();
    
    expect($output)->toContain('No translation files found');
    
    $result->assertExitCode(1);
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
    
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ]);
    
    $output = Artisan::output();
    
    expect($output)->toContain('unused_pattern')
        ->and($output)->not->toContain('"pattern1"')
        ->and($output)->not->toContain('"blade_pattern"');
    
    $result->assertExitCode(0);
});

it('detects dynamic translation keys with template literals', function () {
    // Create file with dynamic translation patterns
    $testFile = $this->testAppDir.'/DynamicTest.js';
    file_put_contents($testFile, "// JavaScript file with dynamic keys\nconst heroId = 'warrior';\nconst type = 'validation';\nconst code = '404';\n\n// These should be detected as dynamic patterns\nt(`enums.hero.\${heroId}`);\n__(`errors.\${type}.\${code}`);\n\$t(`messages.\${messageType}`);\n\n// Regular static keys\nt('static.key');\n__('another.static');\n");
    
    // Create PHP file with template literals
    $phpFile = $this->testAppDir.'/DynamicTest.php';
    file_put_contents($phpFile, "<?php\n\nclass DynamicTest {\n    public function test(\$status, \$level) {\n        // Dynamic keys with template literals\n        echo __(`status.\${\$status}`);\n        echo t(`levels.\${\$level}.name`);\n        \n        // Static keys\n        echo __('fixed.key');\n    }\n}");
    
    // Create translation files with keys that match dynamic patterns
    $enDir = $this->testLangDir.'/en';
    file_put_contents($enDir.'/enums.php', "<?php\n\nreturn [\n    'hero' => [\n        'warrior' => 'Warrior Class',\n        'mage' => 'Mage Class',\n        'rogue' => 'Rogue Class'\n    ]\n];");
    
    file_put_contents($enDir.'/errors.php', "<?php\n\nreturn [\n    'validation' => [\n        '404' => 'Not found',\n        '422' => 'Unprocessable entity'\n    ],\n    'auth' => [\n        '401' => 'Unauthorized'\n    ]\n];");
    
    file_put_contents($enDir.'/messages.php', "<?php\n\nreturn [\n    'success' => 'Operation successful',\n    'error' => 'Operation failed',\n    'warning' => 'Warning message'\n];");
    
    file_put_contents($enDir.'/status.php', "<?php\n\nreturn [\n    'active' => 'Active',\n    'inactive' => 'Inactive',\n    'pending' => 'Pending'\n];");
    
    file_put_contents($enDir.'/levels.php', "<?php\n\nreturn [\n    'beginner' => [\n        'name' => 'Beginner Level',\n        'description' => 'For new users'\n    ],\n    'advanced' => [\n        'name' => 'Advanced Level',\n        'description' => 'For experienced users'\n    ]\n];");
    
    file_put_contents($enDir.'/static.php', "<?php\n\nreturn [\n    'key' => 'Static value',\n    'unused_static' => 'This should be marked as unused'\n];");
    
    file_put_contents($enDir.'/fixed.php', "<?php\n\nreturn [\n    'key' => 'Fixed value'\n];");
    
    file_put_contents($enDir.'/another.php', "<?php\n\nreturn [\n    'static' => 'Another static value'\n];");
    
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ]);
    
    $output = Artisan::output();
    
    // The unused_static key should be detected as unused
    expect($output)->toContain('unused_static');
    
    // Keys matching dynamic patterns should NOT be marked as unused
    expect($output)->not->toContain('"enums.hero.warrior"')
        ->and($output)->not->toContain('"enums.hero.mage"')
        ->and($output)->not->toContain('"errors.validation.404"')
        ->and($output)->not->toContain('"messages.success"')
        ->and($output)->not->toContain('"status.active"')
        ->and($output)->not->toContain('"levels.beginner.name"');
    
    // Static keys that are used should NOT be marked as unused
    expect($output)->not->toContain('"static.key"')
        ->and($output)->not->toContain('"fixed.key"')
        ->and($output)->not->toContain('"another.static"');
    
    $result->assertExitCode(0);
});

it('handles edge cases with dynamic keys', function () {
    // Create file with edge cases
    $testFile = $this->testAppDir.'/EdgeCases.js';
    file_put_contents($testFile, "// Edge cases\n// Multiple dynamic parts\nt(`section.\${section}.\${subsection}.\${item}`);\n\n// Dynamic at the end\nt(`prefix.\${suffix}`);\n\n// Dynamic at the beginning\nt(`\${prefix}.suffix`);\n\n// No dots\nt(`\${singleKey}`);\n\n// With static middle part\nt(`start.\${dynamic}.middle.\${end}`);\n");
    
    // Create matching translation files
    $enDir = $this->testLangDir.'/en';
    file_put_contents($enDir.'/section.php', "<?php\n\nreturn [\n    'main' => [\n        'header' => [\n            'title' => 'Title',\n            'subtitle' => 'Subtitle'\n        ]\n    ],\n    'sidebar' => [\n        'menu' => [\n            'home' => 'Home',\n            'about' => 'About'\n        ]\n    ]\n];");
    
    file_put_contents($enDir.'/prefix.php', "<?php\n\nreturn [\n    'something' => 'Value',\n    'another' => 'Another value'\n];");
    
    file_put_contents($enDir.'/start.php', "<?php\n\nreturn [\n    'value1' => [\n        'middle' => [\n            'end1' => 'End value 1',\n            'end2' => 'End value 2'\n        ]\n    ],\n    'value2' => [\n        'middle' => [\n            'end3' => 'End value 3'\n        ]\n    ],\n    'unused' => [\n        'middle' => [\n            'unused_end' => 'This should be unused'\n        ]\n    ]\n];");
    
    // Add JSON translations for edge cases
    file_put_contents($this->testLangDir.'/en.json', json_encode([\n        'home.suffix' => 'Home suffix',\n        'about.suffix' => 'About suffix',\n        'singleValue' => 'Single value',\n        'anotherValue' => 'Another value',\n        'unusedJson' => 'Should be unused'\n    ]));
    
    $result = $this->artisan('ai-translator:find-unused', [
        '--source' => 'en',
        '--scan-path' => [$this->testAppDir],
        '--format' => 'json'
    ]);
    
    $output = Artisan::output();
    
    // Should detect truly unused keys
    expect($output)->toContain('unused.middle.unused_end')
        ->and($output)->toContain('unusedJson');
    
    // Should NOT mark keys matching dynamic patterns as unused
    expect($output)->not->toContain('"section.main.header.title"')
        ->and($output)->not->toContain('"prefix.something"')
        ->and($output)->not->toContain('"start.value1.middle.end1"');
    
    $result->assertExitCode(0);
});