<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Usage;

use function Pest\Laravel\artisan;

test('registers the test translation command', function () {
    expect(app(Kernel::class)->all())
        ->toHaveKey('ai-translator:test');
});

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
    fakeAiProvider([
        aiTextResponse(
            '<translations><item><key>Test.test</key><trx><![CDATA[안녕하세요]]></trx></item></translations>',
            new Usage(12, 8),
        ),
    ]);

    artisan('ai-translator:test', [
        '--text' => 'Hello',
        '--extended-thinking' => true,
    ])->assertSuccessful();

    expect(config('ai-translator.ai.reasoning'))->toBe(['effort' => 'high'])
        ->and(config('ai-translator.ai.use_extended_thinking'))->toBeTrue();
});
