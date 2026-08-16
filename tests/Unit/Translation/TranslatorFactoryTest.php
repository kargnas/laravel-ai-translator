<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Contracts\Translator;
use Kargnas\LaravelAiTranslator\Translation\ConsensusTranslator;
use Kargnas\LaravelAiTranslator\Translation\TranslatorFactory;

function makeFactoryTranslator(): Translator
{
    return TranslatorFactory::make('test.php', ['greeting' => 'Hello'], 'en', 'ko');
}

test('default config creates an AI provider translator', function () {
    config()->set('ai-translator.consensus.translators', []);

    expect(makeFactoryTranslator())->toBeInstanceOf(AIProvider::class)
        ->toBeInstanceOf(Translator::class)
        ->and(TranslatorFactory::usesConsensus())->toBeFalse();
});

test('two configured translators create a consensus translator', function () {
    config()->set('ai-translator.consensus.translators', [
        ['provider' => 'openai', 'model' => 'translator-a'],
        ['provider' => 'openai', 'model' => 'translator-b'],
    ]);

    expect(makeFactoryTranslator())->toBeInstanceOf(ConsensusTranslator::class)
        ->toBeInstanceOf(Translator::class)
        ->and(TranslatorFactory::usesConsensus())->toBeTrue();
});

test('consensus requires at least two configured translators', function (array $configs, bool $expected) {
    config()->set('ai-translator.consensus.translators', $configs);

    expect(TranslatorFactory::usesConsensus())->toBe($expected);
})->with([
    [[], false],
    [[['provider' => 'openai', 'model' => 'translator-a']], false],
    [[['provider' => 'openai', 'model' => 'translator-a'], ['provider' => 'openai', 'model' => 'translator-b']], true],
]);
