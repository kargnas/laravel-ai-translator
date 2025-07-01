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

test('can get existing locales', function () {
    $command = new TranslateStrings;
    $command->setLaravel(app());

    // sourceDirectory 설정
    $reflection = new \ReflectionClass($command);
    $property = $reflection->getProperty('sourceDirectory');
    $property->setAccessible(true);
    $property->setValue($command, $this->testLangPath);

    $method = $reflection->getMethod('getAvailableLocales');
    $method->setAccessible(true);
    $locales = $method->invoke($command);
    expect($locales)->toContain('en');
});

test('can get string file paths', function () {
    $command = new TranslateStrings;
    $command->setLaravel(app());

    // Set sourceDirectory
    $reflection = new \ReflectionClass($command);
    $property = $reflection->getProperty('sourceDirectory');
    $property->setAccessible(true);
    $property->setValue($command, $this->testLangPath);

    $method = $reflection->getMethod('getLanguageFiles');
    $method->setAccessible(true);
    $files = $method->invoke($command, 'en');
    expect($files)
        ->toBeArray()
        ->toHaveCount(2);

    // Check if both test.php and empty.php exist in the files array (relative paths)
    expect($files)->toContain('test.php');
    expect($files)->toContain('empty.php');
});

test('handles show prompt option', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    artisan('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['ko'],
        '--non-interactive' => true,
        '--show-prompt' => true,
    ])->assertSuccessful();
});

test('captures console output', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    // Capture console output using BufferedOutput
    $output = new BufferedOutput;

    Artisan::call('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['ko'],
        '--non-interactive' => true,
        '--show-prompt' => true,
    ], $output);

    // Get captured output content
    $outputContent = $output->fetch();

    // Display full output content for debugging
    fwrite(STDERR, "\n=== Captured Output ===\n");
    fwrite(STDERR, $outputContent);
    fwrite(STDERR, "\n=====================\n");

    // Verify that output contains specific phrases
    expect($outputContent)
        ->toContain('Laravel AI Translator')
        ->toContain('Translating PHP language files');
});

test('verifies Chinese translations format with dot notation', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    Config::set('ai-translator.dot_notation', true);

    // Execute Chinese Simplified translation
    Artisan::call('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['zh_CN'],
        '--non-interactive' => true,
    ]);

    // Check translated file
    $translatedFile = $this->testLangPath.'/zh_CN/test.php';
    expect(file_exists($translatedFile))->toBeTrue();

    // Load translated content
    $translations = require $translatedFile;

    // Verify translation content structure
    expect($translations)
        ->toBeArray()
        ->toHaveKey('welcome')
        ->toHaveKey('hello')
        ->toHaveKey('goodbye')
        ->toHaveKey('buttons.submit')
        ->toHaveKey('buttons.cancel')
        ->toHaveKey('messages.success')
        ->toHaveKey('messages.error');

    // Check if variables are preserved correctly
    expect($translations['hello'])->toContain(':name');

    // Verify that translations exist and are non-empty strings
    expect($translations['buttons.submit'])->toBeString()->not->toBeEmpty();
    expect($translations['buttons.cancel'])->toBeString()->not->toBeEmpty();
    expect($translations['messages.success'])->toBeString()->not->toBeEmpty();
    expect($translations['messages.error'])->toBeString()->not->toBeEmpty();
});

test('verifies Chinese translations format with nested arrays', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    Config::set('ai-translator.dot_notation', false);

    // Execute Chinese Simplified translation
    Artisan::call('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['zh_CN'],
        '--non-interactive' => true,
    ]);

    // Check translated file
    $translatedFile = $this->testLangPath.'/zh_CN/test.php';
    expect(file_exists($translatedFile))->toBeTrue();

    // Load translated content
    $translations = require $translatedFile;

    // Verify translation content structure
    expect($translations)
        ->toBeArray()
        ->toHaveKey('welcome')
        ->toHaveKey('hello')
        ->toHaveKey('goodbye')
        ->toHaveKey('buttons')
        ->toHaveKey('messages');

    // Check if variables are preserved correctly
    expect($translations['hello'])->toContain(':name');

    // Verify nested array structure is maintained
    expect($translations['buttons'])
        ->toBeArray()
        ->toHaveKey('submit')
        ->toHaveKey('cancel');

    expect($translations['messages'])
        ->toBeArray()
        ->toHaveKey('success')
        ->toHaveKey('error');

    // Verify that translations exist and are non-empty strings
    expect($translations['buttons']['submit'])->toBeString()->not->toBeEmpty();
    expect($translations['buttons']['cancel'])->toBeString()->not->toBeEmpty();
    expect($translations['messages']['success'])->toBeString()->not->toBeEmpty();
    expect($translations['messages']['error'])->toBeString()->not->toBeEmpty();
});

test('compares Chinese variants translations', function () {
    if (! $this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    // Translate zh_CN with dot notation
    Config::set('ai-translator.dot_notation', true);
    Artisan::call('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['zh_CN'],
        '--non-interactive' => true,
    ]);

    // Translate zh_TW with nested arrays
    Config::set('ai-translator.dot_notation', false);
    Artisan::call('ai-translator:translate-strings', [
        '--source' => 'en',
        '--locale' => ['zh_TW'],
        '--non-interactive' => true,
    ]);

    // Load translation files
    $zhCNTranslations = require $this->testLangPath.'/zh_CN/test.php';
    $zhTWTranslations = require $this->testLangPath.'/zh_TW/test.php';

    // Verify zh_CN (dot notation format)
    expect($zhCNTranslations)
        ->toBeArray()
        ->toHaveKey('welcome')
        ->toHaveKey('hello')
        ->toHaveKey('goodbye')
        ->toHaveKey('buttons.submit')
        ->toHaveKey('buttons.cancel')
        ->toHaveKey('messages.success')
        ->toHaveKey('messages.error');

    // Verify zh_TW (nested arrays format)
    expect($zhTWTranslations)
        ->toBeArray()
        ->toHaveKey('welcome')
        ->toHaveKey('hello')
        ->toHaveKey('goodbye')
        ->toHaveKey('buttons')
        ->toHaveKey('messages');

    expect($zhTWTranslations['buttons'])
        ->toBeArray()
        ->toHaveKey('submit')
        ->toHaveKey('cancel');

    expect($zhTWTranslations['messages'])
        ->toBeArray()
        ->toHaveKey('success')
        ->toHaveKey('error');

    // Display output for debugging
    fwrite(STDERR, "\n=== Chinese Variants Comparison ===\n");
    fwrite(STDERR, "ZH_CN (dot notation): {$zhCNTranslations['welcome']}\n");
    fwrite(STDERR, "ZH_TW (nested): {$zhTWTranslations['welcome']}\n");
    fwrite(STDERR, "\n================================\n");
});
