<?php

use Laravel\Ai\Responses\Data\Usage;

use function Pest\Laravel\artisan;

test('uses the configured OpenRouter model when translating one file', function () {
    $sourceFile = tempnam(sys_get_temp_dir(), 'laravel-ai-translator-');
    if ($sourceFile === false) {
        throw new RuntimeException('Unable to create temporary translation file.');
    }

    $outputFile = pathinfo($sourceFile, PATHINFO_DIRNAME).'/'
        .pathinfo($sourceFile, PATHINFO_FILENAME).'-ko.php';
    file_put_contents($sourceFile, "<?php return ['greeting' => 'Hello'];");

    $key = pathinfo($sourceFile, PATHINFO_FILENAME).'.greeting';
    fakeAiProvider([
        aiTextResponse(
            "<translations><item><key>{$key}</key><trx><![CDATA[안녕하세요]]></trx></item></translations>",
            new Usage(10, 5),
        ),
    ]);
    config()->set('ai-translator.ai.api_key', 'test-openrouter-key');

    try {
        artisan('ai-translator:translate-file', [
            'file' => $sourceFile,
            '--target-language' => 'ko',
        ])->assertSuccessful();

        expect(include $outputFile)->toMatchArray(['greeting' => '안녕하세요']);
    } finally {
        foreach ([$sourceFile, $outputFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
