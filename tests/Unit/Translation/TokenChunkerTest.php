<?php

use Kargnas\LaravelAiTranslator\Translation\TokenChunker;

test('estimates latin text with structural overhead', function () {
    $chunker = new TokenChunker;

    expect($chunker->estimateTokens(str_repeat('a', 100)))->toBe(45);
});

test('estimates Korean text with cjk multiplier', function () {
    $chunker = new TokenChunker;

    expect($chunker->estimateTokens(str_repeat('가', 100)))->toBe(170);
});

test('splits chunks at the token budget with safety buffer', function () {
    $chunker = new TokenChunker;
    $strings = [
        'first' => str_repeat('a', 50),
        'second' => str_repeat('b', 50),
        'third' => str_repeat('c', 100),
    ];

    expect($chunker->chunk($strings, 100))->toBe([
        [
            'first' => str_repeat('a', 50),
            'second' => str_repeat('b', 50),
        ],
        [
            'third' => str_repeat('c', 100),
        ],
    ]);
});

test('keeps a single oversized item whole', function () {
    $chunker = new TokenChunker;
    $strings = ['large' => str_repeat('a', 400)];

    expect($chunker->chunk($strings, 100))->toBe([$strings]);
});

test('preserves order and keys across chunks', function () {
    $chunker = new TokenChunker;
    $strings = [
        'first' => ['text' => str_repeat('a', 50)],
        'second' => str_repeat('b', 100),
        'third' => str_repeat('c', 100),
    ];

    $chunks = $chunker->chunk($strings, 100);

    expect(array_keys($chunks[0]))->toBe(['first', 'second']);
    expect(array_keys($chunks[1]))->toBe(['third']);
    expect($chunks[0]['first'])->toBe($strings['first']);
});
