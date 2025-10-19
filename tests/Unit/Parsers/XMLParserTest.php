<?php

use Kargnas\LaravelAiTranslator\Support\Parsers\XMLParser;

test('can parse translation items', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>greeting</key>
            <trx><![CDATA[Hello]]></trx>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result)->toHaveKey('key');
    expect($result)->toHaveKey('trx');
    expect($result['key'][0]['content'])->toBe('greeting');
    expect($result['trx'][0]['content'])->toBe('Hello');
});

test('can parse multiple translation items', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>greeting</key>
            <trx><![CDATA[Hello]]></trx>
        </item>
        <item>
            <key>farewell</key>
            <trx><![CDATA[Goodbye]]></trx>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result['key'])->toHaveCount(2);
    expect($result['trx'])->toHaveCount(2);
    expect($result['key'][0]['content'])->toBe('greeting');
    expect($result['trx'][0]['content'])->toBe('Hello');
    expect($result['key'][1]['content'])->toBe('farewell');
    expect($result['trx'][1]['content'])->toBe('Goodbye');
});

test('can handle special characters in CDATA', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>message</key>
            <trx><![CDATA[Hello & Goodbye]]></trx>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result['key'][0]['content'])->toBe('message');
    expect($result['trx'][0]['content'])->toBe('Hello & Goodbye');
});

test('can handle empty translation items', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>empty</key>
            <trx><![CDATA[]]></trx>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result['key'][0]['content'])->toBe('empty');
    expect($result['trx'][0]['content'])->toBe('');
});

test('can handle line breaks in CDATA', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>multiline</key>
            <trx><![CDATA[First line
Second line
Third line with special chars: & < > "]]></trx>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result['key'][0]['content'])->toBe('multiline');
    expect($result['trx'][0]['content'])->toBe("First line\nSecond line\nThird line with special chars: & < > \"");
});

test('can handle comments in XML', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><translations>
        <item>
            <key>greeting</key>
            <trx><![CDATA[Hello]]></trx>
            <comment><![CDATA[This is a greeting message]]></comment>
        </item>
    </translations>';

    $parser = new XMLParser;
    $parser->parse($xml);
    $result = $parser->getParsedData();

    expect($result['key'][0]['content'])->toBe('greeting');
    expect($result['trx'][0]['content'])->toBe('Hello');
    expect($result)->toHaveKey('comment');
    expect($result['comment'][0]['content'])->toBe('This is a greeting message');
});
