<?php

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

test('translates Prism stream response and fires translated callback', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', false);

    Prism::fake([
        TextResponseFake::make()->withText(
            '<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>'
        ),
    ]);

    $callbacks = [];
    $provider = new AIProvider(
        'test.php',
        ['greeting' => 'Hello, world!'],
        'en',
        'ko'
    );

    $result = $provider
        ->setOnTranslated(function ($item, string $status) use (&$callbacks): void {
            $callbacks[] = [$item->key, $item->translated, $status];
        })
        ->translate();

    expect($result)->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($callbacks)->not->toBeEmpty()
        ->and($callbacks)->toContain(['test.greeting', '안녕하세요', 'completed']);
});

test('retries when most translations fail post-translation validation', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.retries', 2);
    Log::spy();

    $invalidResponse = TextResponseFake::make()->withText(
        '<translations>'
        .'<item><key>test.first</key><trx><![CDATA[첫 번째]]></trx></item>'
        .'<item><key>test.second</key><trx><![CDATA[두 번째]]></trx></item>'
        .'</translations>'
    );

    $fake = Prism::fake([$invalidResponse, $invalidResponse]);

    $provider = new AIProvider(
        'test.php',
        ['first' => 'Count: :count', 'second' => 'Other count: :count'],
        'en',
        'ko'
    );

    expect($provider->translate())->toBe([]);
    $fake->assertCallCount(2);
    Log::shouldHaveReceived('error')->twice();
});
