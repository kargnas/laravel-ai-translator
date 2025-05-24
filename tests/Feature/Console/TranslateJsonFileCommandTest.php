<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Kargnas\LaravelAiTranslator\Console\TranslateJsonFileCommand;

beforeEach(function () {
    $this->testDir = __DIR__ . '/../../Fixtures/json';
    $this->hasApiKeys = !empty(env('OPENAI_API_KEY')) || !empty(env('ANTHROPIC_API_KEY'));
});

afterEach(function () {
    foreach (glob($this->testDir . '/*.json') as $file) {
        if (basename($file) !== 'en.json') {
            unlink($file);
        }
    }
});

test('command exists', function () {
    expect(class_exists(TranslateJsonFileCommand::class))->toBeTrue();
});

test('translates json file and creates output', function () {
    if (!$this->hasApiKeys) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    Config::set('ai-translator.ai.provider', 'openai');
    Config::set('ai-translator.ai.model', 'gpt-4o-mini');
    Config::set('ai-translator.ai.api_key', env('OPENAI_API_KEY'));

    $output = new BufferedOutput();

    Artisan::call('ai-translator:translate-json', [
        'file' => $this->testDir . '/en.json',
        '--source-language' => 'en',
        '--target-language' => 'ko',
    ], $output);

    $translatedPath = $this->testDir . '/ko.json';
    expect(file_exists($translatedPath))->toBeTrue();
});

