<?php

use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

use function Pest\Laravel\artisan;

test('extended thinking configures OpenRouter reasoning and direct Anthropic thinking', function () {
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');
    Http::fake([
        'https://openrouter.ai/api/v1/models' => Http::response([
            'data' => [[
                'id' => 'anthropic/claude-opus-5',
                'name' => 'Claude Opus 5',
                'pricing' => [
                    'prompt' => '0.000005',
                    'completion' => '0.000025',
                ],
            ]],
        ]),
    ]);
    $fake = Prism::fake([
        TextResponseFake::make()
            ->withText('<translations><item><key>Test.test</key><trx><![CDATA[안녕하세요]]></trx></item></translations>')
            ->withUsage(new Usage(12, 8)),
    ]);

    artisan('ai-translator:test-translate', [
        '--text' => 'Hello',
        '--extended-thinking' => true,
    ])->assertSuccessful();

    expect(config('ai-translator.ai.reasoning'))->toBe(['effort' => 'high'])
        ->and(config('ai-translator.ai.use_extended_thinking'))->toBeTrue();
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->providerOptions('reasoning'))->toBe(['effort' => 'high']);
    });
});
