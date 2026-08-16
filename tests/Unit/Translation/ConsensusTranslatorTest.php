<?php

use Illuminate\Support\Facades\Log;
use Kargnas\LaravelAiTranslator\Translation\ConsensusTranslator;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

function consensusTranslatorConfig(string $model): array
{
    return [
        'provider' => 'openai',
        'model' => $model,
        'api_key' => 'test-key',
        'temperature' => 0.3,
    ];
}

function consensusTextResponse(array $translations): TextResponse
{
    $items = collect($translations)->map(function (string $translation, string $key): string {
        return "<item><key>test.{$key}</key><trx><![CDATA[{$translation}]]></trx></item>";
    })->implode('');

    return aiTextResponse("<translations>{$items}</translations>");
}

function consensusTranslate(
    ConsensusTranslator $translator,
    ?callable $onProgress = null,
    ?callable $onTokenUsage = null,
): array {
    return $translator
        ->setOnProgress($onProgress)
        ->setOnTokenUsage($onTokenUsage)
        ->translate();
}

function makeConsensusTranslator(array $configs): ConsensusTranslator
{
    return new ConsensusTranslator(
        'test.php',
        ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
        'en',
        'ko',
        [],
        [],
        null,
        $configs,
        consensusTranslatorConfig('judge'),
    );
}

test('single translator returns its result without calling judge', function () {
    fakeConsensusAgents([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '안녕히 가세요']),
    ], []);

    $result = makeConsensusTranslator([consensusTranslatorConfig('translator-a')])->translate();

    expect($result)->toHaveCount(2)
        ->and($result[0]->translated)->toBe('안녕하세요')
        ->and($result[1]->translated)->toBe('안녕히 가세요');
});

test('judge chooses a different candidate for one key', function () {
    fakeConsensusAgents([
        aiTextResponse(
            '<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item><item><key>test.farewell</key><trx><![CDATA[잘 가]]></trx></item></translations>',
            new Usage(10, 20),
        ),
        aiTextResponse(
            '<translations><item><key>test.greeting</key><trx><![CDATA[안녕]]></trx></item><item><key>test.farewell</key><trx><![CDATA[안녕히 가세요]]></trx></item></translations>',
            new Usage(11, 21),
        ),
    ], [
        aiStructuredResponse(['greeting' => 'A', 'farewell' => 'B'], new Usage(5, 7)),
    ]);

    $usage = [];
    $result = consensusTranslate(
        makeConsensusTranslator([
            consensusTranslatorConfig('translator-a'),
            consensusTranslatorConfig('translator-b'),
        ]),
        null,
        function (array $currentUsage) use (&$usage): void {
            if ($currentUsage['final'] ?? false) {
                $usage = $currentUsage;
            }
        },
    );

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요'])
        ->and($usage['input_tokens'])->toBe(26)
        ->and($usage['output_tokens'])->toBe(48);
});

test('invalid judge label falls back to first candidate', function () {
    fakeConsensusAgents([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '잘 가']),
        consensusTextResponse(['greeting' => '안녕', 'farewell' => '안녕히 가세요']),
    ], [aiStructuredResponse(['greeting' => 'invalid', 'farewell' => 'B'])]);

    $result = makeConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ])->translate();

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요']);
});

test('failed translator is skipped and surviving candidate is returned', function () {
    Log::spy();
    fakeConsensusAgents([
        aiTextResponse('not xml'),
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '안녕히 가세요']),
    ], []);

    $result = makeConsensusTranslator([
        consensusTranslatorConfig('translator-a'),
        consensusTranslatorConfig('translator-b'),
    ])->translate();

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '안녕히 가세요']);
    Log::shouldHaveReceived('warning')->with('Translator translator-a produced no result; continuing with remaining candidates');
});

test('judge failure falls back to first translator for every key', function () {
    Log::spy();
    fakeConsensusAgents([
        consensusTextResponse(['greeting' => '안녕하세요', 'farewell' => '잘 가']),
        consensusTextResponse(['greeting' => '안녕', 'farewell' => '안녕히 가세요']),
    ], []);

    $progress = [];
    $result = consensusTranslate(
        makeConsensusTranslator([
            consensusTranslatorConfig('translator-a'),
            consensusTranslatorConfig('translator-b'),
        ]),
        function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );

    expect(collect($result)->pluck('translated')->all())
        ->toBe(['안녕하세요', '잘 가'])
        ->and($progress)->toContain('Judge failed; using first translator results for all keys.');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Judge failed; using first translator results for all keys.');
});
