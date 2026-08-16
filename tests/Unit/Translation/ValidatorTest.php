<?php

use Kargnas\LaravelAiTranslator\Translation\Validator;

test('accepts translation when html tags are preserved', function () {
    expect((new Validator)->validate('Hello <strong>world</strong>', 'Bonjour <strong>monde</strong>'))->toBe([]);
});

test('reports dropped html tags', function () {
    $issues = (new Validator)->validate('Hello <strong>world</strong>', 'Bonjour monde');

    expect($issues)->toContain('html: expected strong');
});

test('reports missing Laravel variables', function () {
    $issues = (new Validator)->validate('Hello :name', 'Bonjour');

    expect($issues)->toContain('laravel_variables: missing :name');
});

test('accepts reordered Laravel variables', function () {
    expect((new Validator)->validate(':first :last', ':last :first'))->toBe([]);
});

test('reports missing mustache tokens', function () {
    $issues = (new Validator)->validate('Hello {{name}}', 'Bonjour');

    expect($issues)->toContain('mustache: missing {{name}}');
});

test('requires matching printf placeholders', function () {
    expect((new Validator)->validate('Progress: %s (%d)', 'Progrès: %s (%d)'))->toBe([]);

    $issues = (new Validator)->validate('Progress: %s', 'Progrès: %s %s');

    expect($issues)->toContain('printf: extra %s');
});

test('does not flag short originals for length', function () {
    expect((new Validator)->validate('Short', 'x'))->toBe([]);
});

test('reports pathological translation shrink', function () {
    $issues = (new Validator)->validate('This is a sufficiently long source string', 'x');

    expect($issues)->toContain('length: translated length 1 is below 20% of original length 41');
});

test('returns no issues for clean translation', function () {
    expect((new Validator)->validate(
        'Welcome, :name! You have {{count}} messages (%d).',
        '환영합니다, :name님! {{count}}개의 메시지가 있습니다 (%d).'
    ))->toBe([]);
});
