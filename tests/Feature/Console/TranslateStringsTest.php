<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Kargnas\LaravelAiTranslator\Console\TranslateStrings;
use Symfony\Component\Console\Output\BufferedOutput;

use function Pest\Laravel\artisan;

function checkApiKeysExistForFeature(): bool
{
    return ! empty(env('OPENAI_API_KEY')) && ! empty(env('ANTHROPIC_API_KEY'));
}

beforeEach(function () {
    // Check if API keys exist
    $this->hasApiKeys = checkApiKeysExistForFeature();

    // Set up test language file directory
    $this->testLangPath = __DIR__.'/../../Fixtures/lang';

    // For testing purpose, we use claude-3-haiku-20240307 model. (It's faster and cheaper than other models.)
    Config::set('ai-translator.model', 'claude-3-haiku-20240307');
    Config::set('ai-translator.source_directory', $this->testLangPath);
    Config::set('ai-translator.source_locale', 'en');

    // Clean up existing translation files first
    foreach (['ko', 'zh', 'zh_CN', 'zh_TW'] as $locale) {
        $dir = $this->testLangPath.'/'.$locale;
        if (is_dir($dir)) {
            array_map('unlink', glob($dir.'/*.*'));
            rmdir($dir);
        }
    }

    // Create test directories (auto-generated directories)
    foreach (['ko', 'zh', 'zh_CN', 'zh_TW'] as $locale) {
        if (! is_dir($this->testLangPath.'/'.$locale)) {
            mkdir($this->testLangPath.'/'.$locale, 0755, true);
        }
    }
});

afterEach(function () {
    // Clean up only auto-generated files after test
    // foreach (['ko', 'zh', 'zh_CN', 'zh_TW'] as $locale) {
    //     if (is_dir($this->testLangPath . '/' . $locale)) {
    //         array_map('unlink', glob($this->testLangPath . '/' . $locale . '/*.*'));
    //         rmdir($this->testLangPath . '/' . $locale);
    //     }
    // }
});

test('command exists', function () {
    $this->assertTrue(class_exists(TranslateStrings::class));
});

test('handles show prompt option', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    artisan('ai-translator:translate', [
        '--source' => 'en',
        '--locale' => ['ko'],
        '--file' => 'test.php',
        '--skip-copy' => true,
        '--show-prompt' => true,
        '--non-interactive' => true,
    ])->assertSuccessful();
})->skip('API keys not found in environment. Skipping test.');

test('captures console output', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    $output = new BufferedOutput;
    Artisan::call('ai-translator:translate', [
        '--source' => 'en',
        '--locale' => ['ko'],
        '--file' => 'test.php',
        '--skip-copy' => true,
        '--non-interactive' => true,
    ], $output);

    $content = $output->fetch();
    expect($content)->toContain('Translating test.php');
})->skip('API keys not found in environment. Skipping test.');

test('verifies Chinese translations format with dot notation', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    // Create an existing Chinese translation file with dot notation
    $existingFile = $this->testLangPath.'/zh/test.php';
    file_put_contents($existingFile, "<?php\n\nreturn [\n    'messages.welcome' => '欢迎',\n];");

    artisan('ai-translator:translate', [
        '--source' => 'en',
        '--locale' => ['zh'],
        '--file' => 'test.php',
        '--skip-copy' => true,
        '--non-interactive' => true,
    ])->assertSuccessful();

    $translatedFile = $this->testLangPath.'/zh/test.php';
    expect(file_exists($translatedFile))->toBeTrue();

    $translations = include $translatedFile;
    expect($translations)->toBeArray();

    // The format should remain in dot notation
    if (isset($translations['messages.welcome'])) {
        expect($translations['messages.welcome'])->toBeString();
    }
})->skip('API keys not found in environment. Skipping test.');

test('verifies Chinese translations format with nested arrays', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    // Create an existing Chinese translation file with nested arrays
    $existingFile = $this->testLangPath.'/zh/test.php';
    file_put_contents($existingFile, "<?php\n\nreturn [\n    'messages' => [\n        'welcome' => '欢迎',\n    ],\n];");

    artisan('ai-translator:translate', [
        '--source' => 'en',
        '--locale' => ['zh'],
        '--file' => 'test.php',
        '--skip-copy' => true,
        '--non-interactive' => true,
    ])->assertSuccessful();

    $translatedFile = $this->testLangPath.'/zh/test.php';
    expect(file_exists($translatedFile))->toBeTrue();

    $translations = include $translatedFile;
    expect($translations)->toBeArray();

    // The format should remain as nested arrays
    if (isset($translations['messages'])) {
        expect($translations['messages'])->toBeArray();
        if (isset($translations['messages']['welcome'])) {
            expect($translations['messages']['welcome'])->toBeString();
        }
    }
})->skip('API keys not found in environment. Skipping test.');

test('compares Chinese variants translations', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    // Test translating to both zh_CN and zh_TW
    artisan('ai-translator:translate', [
        '--source' => 'en',
        '--locale' => ['zh_CN', 'zh_TW'],
        '--file' => 'test.php',
        '--skip-copy' => true,
        '--non-interactive' => true,
    ])->assertSuccessful();

    $simplifiedFile = $this->testLangPath.'/zh_CN/test.php';
    $traditionalFile = $this->testLangPath.'/zh_TW/test.php';

    expect(file_exists($simplifiedFile))->toBeTrue();
    expect(file_exists($traditionalFile))->toBeTrue();

    $simplifiedTranslations = include $simplifiedFile;
    $traditionalTranslations = include $traditionalFile;

    expect($simplifiedTranslations)->toBeArray();
    expect($traditionalTranslations)->toBeArray();

    // Check that both have translations, but they should be different
    // (Simplified vs Traditional Chinese)
    expect(count($simplifiedTranslations))->toBeGreaterThan(0);
    expect(count($traditionalTranslations))->toBeGreaterThan(0);
})->skip('API keys not found in environment. Skipping test.');