<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Mockery\MockInterface;

function providerKeys(): array
{
    return [
        'openai' => !empty(env('OPENAI_API_KEY')),
        'anthropic' => !empty(env('ANTHROPIC_API_KEY')),
        'gemini' => !empty(env('GEMINI_API_KEY')),
    ];
}

beforeEach(function () {
    $keys = providerKeys();
    $this->hasOpenAI = $keys['openai'];
    $this->hasAnthropic = $keys['anthropic'];
    $this->hasGemini = $keys['gemini'];
});

test('environment variables are loaded from .env.testing', function () {
    if (!($this->hasOpenAI || $this->hasAnthropic || $this->hasGemini)) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    if ($this->hasOpenAI) {
        expect(env('OPENAI_API_KEY'))->not()->toBeNull()
            ->toBeString();
    }

    if ($this->hasAnthropic) {
        expect(env('ANTHROPIC_API_KEY'))->not()->toBeNull()
            ->toBeString();
    }

    if ($this->hasGemini) {
        expect(env('GEMINI_API_KEY'))->not()->toBeNull()
            ->toBeString();
    }
});

test('can translate strings using OpenAI', function () {
    if (!$this->hasOpenAI) {
        $this->markTestSkipped('OpenAI API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', env('OPENAI_API_KEY'));

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider->translate();
    expect($result)->toBeArray();
});

test('can translate strings using Anthropic', function () {
    if (!$this->hasAnthropic) {
        $this->markTestSkipped('Anthropic API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'anthropic');
    config()->set('ai-translator.ai.model', 'claude-3-haiku-20240307');
    config()->set('ai-translator.ai.api_key', env('ANTHROPIC_API_KEY'));

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider->translate();
    expect($result)->toBeArray()->toHaveCount(1);
});

test('can translate strings using Gemini', function () {
    if (!$this->hasGemini) {
        $this->markTestSkipped('Gemini API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'gemini');
    config()->set('ai-translator.ai.model', 'gemini-2.5-pro-preview-05-06');
    config()->set('ai-translator.ai.model', 'gemini-2.5-flash-preview-04-17');
    config()->set('ai-translator.ai.api_key', env('GEMINI_API_KEY'));

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider->translate();
    expect($result)->toBeArray()->toHaveCount(1);
});

test('throws exception for unsupported provider', function () {
    config()->set('ai-translator.ai.provider', 'unsupported');

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $method = new \ReflectionMethod($provider, 'getTranslatedObjects');
    $method->setAccessible(true);

    expect(fn() => $method->invoke($provider))
        ->toThrow(\Exception::class, 'Provider unsupported is not supported.');
});
