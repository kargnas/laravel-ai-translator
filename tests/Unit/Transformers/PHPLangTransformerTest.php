<?php

namespace Kargnas\LaravelAiTranslator\Tests\Unit\Transformers;

use Illuminate\Support\Facades\Config;
use Kargnas\LaravelAiTranslator\Tests\TestCase;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class PHPLangTransformerTest extends TestCase
{
    private string $testFilePath;
    private PHPLangTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = sys_get_temp_dir().'/test_lang.php';
        $this->transformer = new PHPLangTransformer();
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
        $content = $this->transformer->parse($this->testFilePath);
        $this->assertEmpty($content);
    }

    public function test_it_flattens_nested_array(): void
    {
        $content = [
            'title' => [
                'blog' => 'My Blog',
                'about' => [
                    'company' => 'About Company',
                    'team' => 'About Team',
                ],
            ],
            'message' => 'Hello World',
        ];

        $flattened = $this->transformer->flatten($content);

        $expected = [
            'title.blog' => 'My Blog',
            'title.about.company' => 'About Company',
            'title.about.team' => 'About Team',
            'message' => 'Hello World',
        ];

        $this->assertEquals($expected, $flattened);
    }

    public function test_it_updates_string_with_dot_notation(): void
    {
        $content = [
            'title' => [
                'blog' => 'My Blog',
            ],
        ];

        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $data = $this->transformer->parse($this->testFilePath);
        $updated = $this->transformer->unflatten($data, 'title.blog', 'Updated Blog');
        $this->transformer->save($this->testFilePath, $updated);

        $result = $this->transformer->parse($this->testFilePath);
        $this->assertEquals('Updated Blog', $result['title']['blog']);
    }

    public function test_it_updates_nested_string_with_dot_notation(): void
    {
        $content = [
            'title' => [
                'about' => [
                    'company' => 'About Company',
                ],
            ],
        ];

        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $data = $this->transformer->parse($this->testFilePath);
        $updated = $this->transformer->unflatten($data, 'title.about.company', 'Updated Company');
        $this->transformer->save($this->testFilePath, $updated);

        $result = $this->transformer->parse($this->testFilePath);
        $this->assertEquals('Updated Company', $result['title']['about']['company']);
    }

    public function test_it_updates_string_with_array_notation(): void
    {
        Config::set('ai-translator.dot_notation', true);
        $transformer = new PHPLangTransformer();

        $content = [
            'title.blog' => 'My Blog',
        ];

        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $data = $transformer->parse($this->testFilePath);
        $updated = $transformer->unflatten($data, 'title.blog', 'Updated Blog');
        $transformer->save($this->testFilePath, $updated);

        $result = $transformer->parse($this->testFilePath);
        $this->assertEquals('Updated Blog', $result['title.blog']);
    }

    public function test_it_creates_nested_structure_if_not_exists(): void
    {
        $data = [];
        $updated = $this->transformer->unflatten($data, 'new.nested.key', 'New Value');
        
        $this->assertEquals('New Value', $updated['new']['nested']['key']);
    }

    public function test_it_handles_special_characters(): void
    {
        $content = [
            'message' => "It's a \"test\" message",
        ];

        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $data = $this->transformer->parse($this->testFilePath);
        $updated = $this->transformer->unflatten($data, 'message', "Updated \"test\" message");
        $this->transformer->save($this->testFilePath, $updated);

        $result = $this->transformer->parse($this->testFilePath);
        $this->assertEquals("Updated \"test\" message", $result['message']);
    }

    public function test_it_checks_if_key_is_translated(): void
    {
        $content = [
            'translated' => 'Some value',
            'empty' => '',
            'nested' => [
                'value' => 'Nested value',
            ],
        ];

        $this->assertTrue($this->transformer->isTranslated($content, 'translated'));
        $this->assertFalse($this->transformer->isTranslated($content, 'empty'));
        $this->assertTrue($this->transformer->isTranslated($content, 'nested.value'));
        $this->assertFalse($this->transformer->isTranslated($content, 'non.existent'));
    }

    public function test_it_maintains_file_header_comments(): void
    {
        $data = ['test' => 'value'];
        $this->transformer->save($this->testFilePath, $data);

        $content = file_get_contents($this->testFilePath);
        $this->assertStringContainsString('WARNING: This is an auto-generated file', $content);
        $this->assertStringContainsString('Do not modify this file manually', $content);
    }

    public function test_it_properly_formats_nested_arrays(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => 'Deep value',
                ],
                'sibling' => 'Sibling value',
            ],
            'root' => 'Root value',
        ];

        $this->transformer->save($this->testFilePath, $data);
        $result = $this->transformer->parse($this->testFilePath);

        $this->assertEquals($data, $result);
    }
}