<?php

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\Translation\ConsensusTranslator;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

function consensusTranslatorConfig(string $model): array
{
    return [
        'provider' => 'openai',
        'model' => $model,
        'api_key' => 'test-key',
        'temperature' => 0.3,
    ];
}

function consensusTextResponse(array $translations): TextResponseFake
{
    $items = collect($translations)->map(function (string $translation, string $key): string {
        return "<item><key>test.{$key}</key><trx><![CDATA[{$translation}]]></trx></item>";
    })->implode('');

    return TextResponseFake::make()->withText("<translations>{$items}</translations>");
}

function consensusTranslate(
    ConsensusTranslator $translator,
    ?callable $onProgress = null,
    ?callable $onTokenUsage = null,
): array {
    return $translator->translate(
        'test.php',
        ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
        'en',
        'ko',
        [],
        [],
        null,
        null,
        null,
        $onProgress,
        $onTokenUsage,
        null,
    );
}

test('single translator returns its result without calling judge', function () {
    $fake = Prism::fake([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '안녕히 가세요']),
    ]);

    $translator = new ConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
    ], consensusTranslatorConfig('judge'));

    $result = consensusTranslate($translator);

    expect($result)->toHaveCount(2)
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($result[1]->translated)->toBe('안녕히 가세요');
    $fake->assertCallCount(1);
});

test('judge chooses a different candidate for one key', function () {
    $fake = Prism::fake([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '잘 가'])
            ->withUsage(new Usage(10, 20)),
        consensusTextResponse(['greeting' => '안녕', 'farewell' => '안녕히 가세요'])
            ->withUsage(new Usage(11, 21)),
        StructuredResponseFake::make()
            ->withStructured([
                'greeting' => 'A',
                'farewell' => 'B',
            ])
            ->withUsage(new Usage(5, 7)),
    ]);

    $translator = new ConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ], consensusTranslatorConfig('judge'));

    $usage = [];
    $result = consensusTranslate($translator, null, function (array $currentUsage) use (&$usage): void {
        if ($currentUsage['final'] ?? false) {
            $usage = $currentUsage;
        }
    });

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요'])
        ->and($usage['input_tokens'])->toBe(26)
        ->and($usage['output_tokens'])->toBe(48);
    $fake->assertCallCount(3);
});

test('invalid judge label falls back to first candidate', function () {
    $fake = Prism::fake([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '잘 가']),
        consensusTextResponse(['greeting' => '안녕', 'farewell' => '안녕히 가세요']),
        StructuredResponseFake::make()->withStructured([
            'greeting' => 'invalid',
            'farewell' => 'B',
        ]),
    ]);

    $translator = new ConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ], consensusTranslatorConfig('judge'));

    $result = consensusTranslate($translator);

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요']);
    $fake->assertCallCount(3);
});

test('failed translator is skipped and surviving candidate is returned', function () {
    Log::spy();
    $fake = Prism::fake([
        TextResponseFake::make()->withText('not xml'),
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '안녕히 가세요']),
    ]);

    $translator = new ConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ], consensusTranslatorConfig('judge'));

    $result = consensusTranslate($translator);

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요']);
    Log::shouldHaveReceived('warning')->with('Translator translator-a produced no result; continuing with remaining candidates');
    $fake->assertCallCount(2);
});

test('judge failure falls back to first translator for every key', function () {
    Log::spy();
    $fake = Prism::fake([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '잘 가']),
        consensusTextResponse(['greeting' => '안녕', 'farewell' => '안녕히 가세요']),
    ]);

    $progress = [];
    $translator = new ConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ], consensusTranslatorConfig('judge'));

    $result = consensusTranslate($translator, function (string $message) use (&$progress): void {
        $progress[] = $message;
    });

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '잘 가'])
        ->and($progress)->toContain('Judge failed; using first translator results for all keys.');
    Log::shouldHaveReceived('warning')->withArgs(function (string $message): bool {
        return $message === 'Judge failed; using first translator results for all keys.';
    });
    $fake->assertCallCount(3);
});
