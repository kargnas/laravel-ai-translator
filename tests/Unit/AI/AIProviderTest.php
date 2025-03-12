<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Language\Language;
use Mockery\MockInterface;

test('environment variables are loaded from .env.testing', function () {
    // OpenAI
    expect(env('OPENAI_API_KEY'))->not()->toBeNull()
        ->toBeString()
        ->toStartWith('sk-');

    // Anthropic
    expect(env('ANTHROPIC_API_KEY'))->not()->toBeNull()
        ->toBeString()
        ->toStartWith('sk-ant-');
});

test('can translate strings using OpenAI', function () {
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
    expect($result)->toBeArray();
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
