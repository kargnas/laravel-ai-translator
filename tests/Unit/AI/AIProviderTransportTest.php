<?php

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Exceptions\TranslationFailedException;

test('translates a streamed response and fires the translated callback', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', false);

    fakeAiProvider([
        aiTextResponse('<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>'),
    ]);

    $callbacks = [];
    $result = (new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko'))
        ->setOnTranslated(function ($item, string $status) use (&$callbacks): void {
            $callbacks[] = [$item->key, $item->translated, $status];
        })
        ->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($callbacks)->toContain(['test.greeting', '안녕하세요', 'completed']);
});

test('retries when most translations fail post-translation validation', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.retries', 2);
    Log::spy();

    $invalidResponse = aiTextResponse(
        '<translations>'
        .'<item><key>test.first</key><trx><![CDATA[첫 번째]]></trx></item>'
        .'<item><key>test.second</key><trx><![CDATA[두 번째]]></trx></item>'
        .'</translations>'
    );

    fakeAiProvider([$invalidResponse, $invalidResponse]);
    $provider = new AIProvider(
        'test.php',
        ['first' => 'Count: :count', 'second' => 'Other count: :count'],
        'en',
        'ko',
    );

    expect(fn () => $provider->translate())
        ->toThrow(TranslationFailedException::class, 'Post-translation validation failed');
    Log::shouldHaveReceived('error')->twice();
});

// Issue #20: transport errors (401/404/timeouts) used to surface as an empty result,
// letting commands print a green summary with nothing saved.
test('throws after exhausting retries on transport errors', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.retries', 2);
    Log::spy();

    // The fake gateway returns non-closure values as-is, so throwing requires closures.
    $transportError = function (): never {
        throw new RuntimeException('HTTP request returned status code 404');
    };
    fakeAiProvider([$transportError, $transportError]);

    $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');

    expect(fn () => $provider->translate())
        ->toThrow(TranslationFailedException::class, 'HTTP request returned status code 404');
});
