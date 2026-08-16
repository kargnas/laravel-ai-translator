<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Usage;

function providerKeys(): array
{
    $hasKey = function (string $name): bool {
        $key = env($name);

        return is_string($key) && $key !== '' && ! str_starts_with($key, 'your-');
    };

    return [
        'openrouter' => $hasKey('OPENROUTER_API_KEY'),
        'openai' => $hasKey('OPENAI_API_KEY'),
        'anthropic' => $hasKey('ANTHROPIC_API_KEY'),
        'gemini' => $hasKey('GEMINI_API_KEY'),
    ];
}

beforeEach(function () {
    $keys = providerKeys();
    $this->hasOpenRouter = $keys['openrouter'];
    $this->hasOpenAI = $keys['openai'];
    $this->hasAnthropic = $keys['anthropic'];
    $this->hasGemini = $keys['gemini'];
});

test('environment variables are loaded from .env.testing', function () {
    if (! ($this->hasOpenRouter || $this->hasOpenAI || $this->hasAnthropic || $this->hasGemini)) {
        $this->markTestSkipped('API keys not found in environment. Skipping test.');
    }

    if ($this->hasOpenRouter) {
        expect(env('OPENROUTER_API_KEY'))->not()->toBeNull()
            ->toBeString();
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

test('can translate strings using OpenRouter', function () {
    if (! $this->hasOpenRouter) {
        $this->markTestSkipped('OpenRouter API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'google/gemini-3.7-flash');
    config()->set('ai-translator.ai.api_key', env('OPENROUTER_API_KEY'));
    config()->set('ai-translator.ai.disable_stream', true);

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider->translate();

    expect($result)->toBeArray()->toHaveCount(1);
});

test('can translate strings using OpenAI', function () {
    if (! $this->hasOpenAI) {
        $this->markTestSkipped('OpenAI API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-5.6-sol');
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
    if (! $this->hasAnthropic) {
        $this->markTestSkipped('Anthropic API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'anthropic');
    config()->set('ai-translator.ai.model', 'claude-opus-5');
    config()->set('ai-translator.ai.api_key', env('ANTHROPIC_API_KEY'));
    config()->set('ai-translator.ai.max_tokens', 128000);

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
    if (! $this->hasGemini) {
        $this->markTestSkipped('Gemini API key not found in environment. Skipping test.');
    }

    config()->set('ai-translator.ai.provider', 'gemini');
    config()->set('ai-translator.ai.model', 'gemini-3.7-flash');
    config()->set('ai-translator.ai.api_key', env('GEMINI_API_KEY'));
    config()->set('ai-translator.ai.max_tokens', 65536);

    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider->translate();
    expect($result)->toBeArray()->toHaveCount(1);
});

test('uses OpenRouter with the default frontier model', function () {
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.temperature', 0.2);
    config()->set('ai-translator.ai.max_tokens', 4096);
    config()->set('ai-translator.ai.reasoning', ['effort' => 'high']);

    $fake = Prism::fake([
        TextResponseFake::make()
            ->withText('<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>')
            ->withUsage(new Usage(12, 8, cacheReadInputTokens: 4)),
    ]);

    $usageEvents = [];
    $translationEvents = [];
    $provider = (new AIProvider(
        'test.php',
        ['greeting' => 'Hello'],
        'en',
        'ko'
    ))->setOnTranslated(function ($item, string $status) use (&$translationEvents): void {
        $translationEvents[] = $status;
    })->setOnTokenUsage(function (array $usage) use (&$usageEvents): void {
        $usageEvents[] = $usage;
    });

    $result = $provider->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($provider->getTokenUsage())->toMatchArray([
            'input_tokens' => 8,
            'output_tokens' => 8,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 4,
            'total_tokens' => 20,
        ])
        ->and(array_column($usageEvents, 'final'))->toBe([false, true])
        ->and($translationEvents)->toBe([
            TranslationStatus::STARTED,
            TranslationStatus::COMPLETED,
        ]);

    $fake->assertProviderConfig(['api_key' => 'test-openrouter-key']);
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0])->toBeInstanceOf(Request::class)
            ->and($requests[0]->provider())->toBe('openrouter')
            ->and($requests[0]->model())->toBe('anthropic/claude-opus-5')
            ->and($requests[0]->temperature())->toBe(0.2)
            ->and($requests[0]->maxTokens())->toBe(4096)
            ->and($requests[0]->providerOptions('reasoning'))->toBe(['effort' => 'high']);
    });
});

test('omits unconfigured optional OpenRouter parameters', function () {
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->offsetUnset('ai-translator.ai.temperature');
    config()->offsetUnset('ai-translator.ai.max_tokens');
    config()->offsetUnset('ai-translator.ai.reasoning');

    $fake = Prism::fake([
        TextResponseFake::make()
            ->withText('<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>')
            ->withUsage(new Usage(12, 8)),
    ]);

    $result = (new AIProvider(
        'test.php',
        ['greeting' => 'Hello'],
        'en',
        'ko'
    ))->translate();

    expect($result)->toHaveCount(1);
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->temperature())->toBeNull()
            ->and($requests[0]->maxTokens())->toBeNull()
            ->and($requests[0]->providerOptions())->toBe([]);
    });
});

test('streams OpenRouter translations through existing callbacks', function () {
    config()->set('ai-translator.ai.provider', 'openrouter');
    config()->set('ai-translator.ai.model', 'openai/gpt-5.6-sol');
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');
    config()->set('ai-translator.ai.disable_stream', false);

    Prism::fake([
        TextResponseFake::make()
            ->withText('<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>'),
    ])->withFakeChunkSize(9);

    $progress = [];
    $translationEvents = [];
    $provider = (new AIProvider(
        'test.php',
        ['greeting' => 'Hello'],
        'en',
        'ko'
    ))->setOnTranslated(function ($item, string $status) use (&$translationEvents): void {
        $translationEvents[] = $status;
    })->setOnProgress(function (string $chunk) use (&$progress): void {
        $progress[] = $chunk;
    });

    $result = $provider->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and(implode('', $progress))->toContain('<translations>')
        ->and($translationEvents)->toBe([
            TranslationStatus::STARTED,
            TranslationStatus::COMPLETED,
        ]);
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

    expect(fn () => $method->invoke($provider))
        ->toThrow(\Exception::class, 'Provider unsupported is not supported.');
});
