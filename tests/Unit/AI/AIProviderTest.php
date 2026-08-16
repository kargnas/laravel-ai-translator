<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\Usage;

test('environment variables are loaded from .env.testing', function () {
    $keys = collect(['OPENROUTER_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GEMINI_API_KEY'])
        ->mapWithKeys(fn (string $name): array => [$name => env($name)])
        ->filter(fn ($value): bool => is_string($value) && $value !== '' && ! str_starts_with($value, 'your-'));

    if ($keys->isEmpty()) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    expect($keys)->not->toBeEmpty();
});

test('can translate strings using OpenRouter', function () {
    if (! env('OPENROUTER_API_KEY')) {
        $this->markTestSkipped('OpenRouter API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'google/gemini-3.7-flash');
    config()->set('ai-translator.ai.api_key', env('OPENROUTER_API_KEY'));
    config()->set('ai-translator.ai.disable_stream', true);

    expect((new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))->translate())
        ->toHaveCount(1);
});

test('can translate strings using OpenAI', function () {
    if (! env('OPENAI_API_KEY')) {
        $this->markTestSkipped('OpenAI API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-5.6-sol');
    config()->set('ai-translator.ai.api_key', env('OPENAI_API_KEY'));

    expect((new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))->translate())
        ->toBeArray();
});

test('can translate strings using Anthropic', function () {
    if (! env('ANTHROPIC_API_KEY')) {
        $this->markTestSkipped('Anthropic API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'anthropic');
    config()->set('ai-translator.ai.model', 'claude-opus-5');
    config()->set('ai-translator.ai.api_key', env('ANTHROPIC_API_KEY'));
    config()->set('ai-translator.ai.max_tokens', 128000);

    expect((new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))->translate())
        ->toHaveCount(1);
});

test('can translate strings using Gemini', function () {
    if (! env('GEMINI_API_KEY')) {
        $this->markTestSkipped('Gemini API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'gemini');
    config()->set('ai-translator.ai.model', 'gemini-3.7-flash');
    config()->set('ai-translator.ai.api_key', env('GEMINI_API_KEY'));
    config()->set('ai-translator.ai.max_tokens', 65536);

    expect((new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))->translate())
        ->toHaveCount(1);
});

test('translates through the Laravel AI agent and aggregates usage', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'anthropic/claude-opus-5');
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.temperature', 0.2);
    config()->set('ai-translator.ai.max_tokens', 4096);
    config()->set('ai-translator.ai.reasoning', ['effort' => 'high']);

    fakeAiProvider([
        aiTextResponse(
            '<translations> <item> <key>test.greeting</key> <trx><![CDATA[안녕하세요]]></trx> </item> </translations>',
            new Usage(12, 8, 0, 4),
        ),
    ]);

    $usageEvents = [];
    $translationEvents = [];
    $provider = (new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko'))
        ->setOnTranslated(function ($item, string $status) use (&$translationEvents): void {
            $translationEvents[] = $status;
        })
        ->setOnTokenUsage(function (array $usage) use (&$usageEvents): void {
            $usageEvents[] = $usage;
        });

    $result = $provider->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($provider->getTokenUsage())->toMatchArray([
            'input_tokens' => 8,
            'output_tokens' => 8,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 4,
            'total_tokens' => 20,
        ])
        ->and(array_column($usageEvents, 'final'))->toBe([false, true])
        ->and($translationEvents)->toBe([TranslationStatus::STARTED, TranslationStatus::COMPLETED]);
});

test('omits optional agent parameters when they are not configured', function () {
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->offsetUnset('ai-translator.ai.temperature');
    config()->offsetUnset('ai-translator.ai.max_tokens');
    config()->offsetUnset('ai-translator.ai.reasoning');

    fakeAiProvider([aiTextResponse('<translations> <item> <key>test.greeting</key> <trx><![CDATA[안녕하세요]]></trx> </item> </translations>')]);

    $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');
    $method = new ReflectionMethod($provider, 'makeAgent');
    $method->setAccessible(true);
    $agent = $method->invoke($provider, 'system');

    expect($agent->temperature())->toBeNull()
        ->and($agent->maxTokens())->toBeNull()
        ->and($agent->providerOptions(Lab::OpenRouter))->toBe([]);
});

test('streams translations through existing callbacks', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'openai/gpt-5.6-sol');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', false);

    fakeAiProvider([aiTextResponse('<translations> <item> <key>test.greeting</key> <trx><![CDATA[안녕하세요]]></trx> </item> </translations>')]);

    $progress = [];
    $translationEvents = [];
    $provider = (new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko'))
        ->setOnTranslated(function ($item, string $status) use (&$translationEvents): void {
            $translationEvents[] = $status;
        })
        ->setOnProgress(function (string $chunk) use (&$progress): void {
            $progress[] = $chunk;
        });

    $result = $provider->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and(implode('', $progress))->toContain('<translations>')
        ->and($translationEvents)->toBe([TranslationStatus::STARTED, TranslationStatus::COMPLETED]);
});

test('pins gpt-5 temperature to 1.0', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-5.6-luna');
    config()->set('ai-translator.ai.temperature', 0.2);

    $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');
    $method = new ReflectionMethod($provider, 'makeAgent');
    $method->setAccessible(true);
    $agent = $method->invoke($provider, 'system');

    expect($agent->temperature())->toBe(1.0);
});

test('maps extended thinking to the provider request shape', function () {
    config()->set('ai-translator.ai.provider', 'anthropic');
    config()->set('ai-translator.ai.model', 'claude-3-7-sonnet');
    config()->set('ai-translator.ai.use_extended_thinking', true);
    config()->set('ai-translator.ai.max_tokens', 10000);

    $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');
    $method = new ReflectionMethod($provider, 'makeAgent');
    $method->setAccessible(true);
    $agent = $method->invoke($provider, 'system');

    expect($agent->providerOptions(Lab::Anthropic))->toBe([
        'thinking' => [
            'type' => 'enabled',
            'budget_tokens' => 10000,
        ],
    ]);
});

test('translates strings through the configured provider', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'anthropic/claude-sonnet-4.5');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', true);

    fakeAiProvider([aiTextResponse('<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>')]);

    expect((new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))->translate())
        ->toHaveCount(1);
});

test('throws exception for unsupported provider', function () {
    config()->set('ai-translator.ai.provider', 'unsupported');

    $provider = new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko');
    $method = new ReflectionMethod($provider, 'getTranslatedObjects');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))
        ->toThrow(Exception::class, 'Provider unsupported is not supported.');
});
