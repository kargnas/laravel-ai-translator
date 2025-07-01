<?php

namespace Kargnas\LaravelAiTranslator\Tests\Unit\Transformers;

use Illuminate\Support\Facades\Config;
use Kargnas\LaravelAiTranslator\Tests\TestCase;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;

class JSONLangTransformerTest extends TestCase
{
    private string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = sys_get_temp_dir().'/test_lang.json';
        Config::set('ai-translator', [
            'dot_notation' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }
        parent::tearDown();
    }

    public function test_it_loads_empty_array_when_file_does_not_exist(): void
    {
        $transformer = new JSONLangTransformer($this->testFilePath);
        $this->assertEmpty($transformer->flatten());
    }

    public function test_it_flattens_nested_array(): void
    {
        $content = [
            'title' => [
                'blog' => 'My Blog',
                'about' => [
                    'company' => 'About Company',
                ],
            ],
            'message' => 'Hello World',
        ];
        file_put_contents($this->testFilePath, json_encode($content));

        $transformer = new JSONLangTransformer($this->testFilePath);
        $flattened = $transformer->flatten();

        $expected = [
            'title.blog' => 'My Blog',
            'title.about.company' => 'About Company',
            'message' => 'Hello World',
        ];

        $this->assertEquals($expected, $flattened);
    }

    public function test_it_updates_string_with_array_notation(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = ['message' => 'Hello'];
        file_put_contents($this->testFilePath, json_encode($content));

        $transformer = new JSONLangTransformer($this->testFilePath);
        $transformer->updateString('message', '안녕하세요');

        $updatedContent = json_decode(file_get_contents($this->testFilePath), true);

        // Check that comment contains expected warning and date
        $this->assertArrayHasKey('_comment', $updatedContent);
        $this->assertStringContainsString('WARNING: This is an auto-generated file', $updatedContent['_comment']);
        $this->assertStringContainsString('automatically translated from en on', $updatedContent['_comment']);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $updatedContent['_comment']);

        // Check translated content
        $this->assertEquals('안녕하세요', $updatedContent['message']);
    }

    public function test_it_excludes_comment_from_flattening(): void
    {
        $content = [
            '_comment' => 'This is a comment',
            'message' => 'Hello',
            'nested' => [
                'key' => 'value',
            ],
        ];
        file_put_contents($this->testFilePath, json_encode($content));

        $transformer = new JSONLangTransformer($this->testFilePath);
        $flattened = $transformer->flatten();

        $expected = [
            'message' => 'Hello',
            'nested.key' => 'value',
        ];

        $this->assertEquals($expected, $flattened);
        $this->assertArrayNotHasKey('_comment', $flattened);
    }

    public function test_comment_is_always_considered_translated(): void
    {
        $transformer = new JSONLangTransformer($this->testFilePath);

        $this->assertTrue($transformer->isTranslated('_comment'));
    }

    public function test_it_updates_nested_string_with_dot_notation(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = [
            'user' => [
                'profile' => [
                    'name' => 'Name',
                    'email' => 'Email'
                ]
            ]
        ];
        file_put_contents($this->testFilePath, json_encode($content));

        $transformer = new JSONLangTransformer($this->testFilePath);
        $transformer->updateString('user.profile.name', 'Nome');

        $updatedContent = json_decode(file_get_contents($this->testFilePath), true);
        
        // Should maintain nested structure
        $this->assertEquals('Nome', $updatedContent['user']['profile']['name']);
        $this->assertEquals('Email', $updatedContent['user']['profile']['email']);
    }

    public function test_it_creates_nested_structure_for_new_keys(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = [];
        file_put_contents($this->testFilePath, json_encode($content));

        $transformer = new JSONLangTransformer($this->testFilePath);
        $transformer->updateString('new.nested.key', 'New Value');

        $updatedContent = json_decode(file_get_contents($this->testFilePath), true);
        
        // Should create nested structure
        $this->assertArrayHasKey('new', $updatedContent);
        $this->assertArrayHasKey('nested', $updatedContent['new']);
        $this->assertEquals('New Value', $updatedContent['new']['nested']['key']);
    }
}
