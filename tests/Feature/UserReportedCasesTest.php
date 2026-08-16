<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Translation\Validator;

// Issue #53: OpenAI gpt-5 rejects any temperature other than 1 with HTTP 400.
test('gpt-5 models are pinned to temperature 1.0', function (string $model) {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', $model);
    config()->set('ai-translator.ai.api_key', 'test-key');
    config()->set('ai-translator.ai.disable_stream', true);
    config()->set('ai-translator.ai.temperature', 0.2);

    fakeAiProvider([aiTextResponse(
        '<translations><item><key>test.greeting</key><trx><![CDATA[안녕하세요]]></trx></item></translations>'
    )]);

    $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');
    $provider->translate();

    $method = new ReflectionMethod($provider, 'makeAgent');
    $method->setAccessible(true);
    expect($method->invoke($provider, 'system')->temperature())->toBe(1.0);
})->with(['gpt-5', 'gpt-5-mini', 'gpt-5.6-luna']);

// Issue #19: sentence keys ending with '.' must not explode into a nested ['' => ...] level.
// dot_notation=false is the code default for users whose published config predates the key.
test('PHP transformer preserves sentence keys with a trailing dot', function () {
    config()->set('ai-translator.dot_notation', false);
    $file = sys_get_temp_dir().'/ai-translator-test-'.uniqid().'.php';
    $key = 'A new verification link has been sent to your email address.';

    try {
        $transformer = new PHPLangTransformer($file);
        $transformer->updateString($key, '새 인증 링크를 이메일로 보냈어요.');

        $written = include $file;
        // array_key_exists: Pest's toHaveKey traverses dots as nesting and would false-pass.
        expect(array_key_exists($key, $written))->toBeTrue()
            ->and($written[$key])->toBe('새 인증 링크를 이메일로 보냈어요.');
    } finally {
        @unlink($file);
    }
});

test('JSON transformer preserves sentence keys with inner dots', function () {
    config()->set('ai-translator.dot_notation', false);
    $file = sys_get_temp_dir().'/ai-translator-test-'.uniqid().'.json';
    $key = 'Mr. Smith went to Washington. Then he came back.';

    try {
        $transformer = new JSONLangTransformer($file);
        $transformer->updateString($key, '스미스 씨는 워싱턴에 갔다가 돌아왔어요.');

        $written = json_decode(file_get_contents($file), true);
        // array_key_exists: Pest's toHaveKey traverses dots as nesting and would false-pass.
        expect(array_key_exists($key, $written))->toBeTrue()
            ->and($written[$key])->toBe('스미스 씨는 워싱턴에 갔다가 돌아왔어요.');
    } finally {
        @unlink($file);
    }
});

// Issue #20: markup-polluted model output must not be written into translations verbatim.
test('validator flags translations polluted with response XML markup', function () {
    $validator = new Validator;

    $issues = $validator->validate(
        'Address suffix',
        "label</key>\n<trx><![CDATA[Address suffix]]></trx>"
    );

    expect($issues)->not->toBeEmpty();
});
