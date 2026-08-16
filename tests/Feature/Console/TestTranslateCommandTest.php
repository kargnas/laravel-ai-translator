<?php

use Illuminate\Contracts\Console\Kernel;

test('registers the test translation command', function () {
    expect(app(Kernel::class)->all())
        ->toHaveKey('ai-translator:test');
});
