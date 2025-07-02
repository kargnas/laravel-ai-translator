<?php

namespace Kargnas\LaravelAiTranslator\Tests\Unit\Transformers;

use Illuminate\Support\Facades\Config;
use Kargnas\LaravelAiTranslator\Tests\TestCase;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;

class PHPLangTransformerTest extends TestCase
{
    private string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilePath = sys_get_temp_dir().'/test_lang.php';
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
        $transformer = new PHPLangTransformer($this->testFilePath);
        $this->assertEmpty($transformer->flatten());
    }

    public function test_it_handles_empty_file_without_return_statement(): void
    {
        // Create an empty PHP file without return statement
        file_put_contents($this->testFilePath, '<?php');
        
        $transformer = new PHPLangTransformer($this->testFilePath);
        $this->assertEmpty($transformer->flatten());
    }

    public function test_it_handles_file_with_code_but_no_return(): void
    {
        // Create a PHP file with code but no return statement
        file_put_contents($this->testFilePath, '<?php $var = "test";');
        
        $transformer = new PHPLangTransformer($this->testFilePath);
        $this->assertEmpty($transformer->flatten());
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

        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);
        $flattened = $transformer->flatten();

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
        Config::set('ai-translator.dot_notation', true);

        $content = ['message' => 'Hello'];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);
        $transformer->updateString('message', '안녕하세요');

        $updatedContent = require $this->testFilePath;
        $this->assertEquals(['message' => '안녕하세요'], $updatedContent);
    }

    public function test_it_updates_nested_string_with_dot_notation(): void
    {
        Config::set('ai-translator.dot_notation', true);

        $content = [
            'navigation' => [
                'menu' => [
                    'home' => 'Home',
                    'about' => 'About Us',
                ],
            ],
            'footer' => [
                'copyright' => 'All rights reserved',
            ],
        ];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);

        // Update existing nested key
        $transformer->updateString('navigation.menu.home', '홈');

        // Create new nested key
        $transformer->updateString('navigation.menu.contact', '연락처');

        // Update another existing nested key in different branch
        $transformer->updateString('footer.copyright', '모든 권리 보유');

        $updatedContent = require $this->testFilePath;
        $expected = [
            'navigation.menu.home' => '홈',
            'navigation.menu.about' => 'About Us',
            'footer.copyright' => '모든 권리 보유',
            'navigation.menu.contact' => '연락처',
        ];
        $this->assertEquals($expected, $updatedContent);
    }

    public function test_it_updates_string_with_array_notation(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = [
            'title' => [
                'blog' => 'My Blog',
            ],
        ];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);
        $transformer->updateString('title.blog', '내 블로그');

        $updatedContent = require $this->testFilePath;
        $this->assertEquals(['title' => ['blog' => '내 블로그']], $updatedContent);
    }

    public function test_it_creates_nested_structure_if_not_exists(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = ['existing' => 'value'];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);
        $transformer->updateString('new.nested.key', 'New Value');

        $updatedContent = require $this->testFilePath;
        $expected = [
            'existing' => 'value',
            'new' => [
                'nested' => [
                    'key' => 'New Value',
                ],
            ],
        ];
        $this->assertEquals($expected, $updatedContent);
    }

    public function test_it_handles_special_characters(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $content = ['message' => "It's a test"];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);
        $transformer->updateString('message', "It's a 'quoted' string");

        $updatedContent = require $this->testFilePath;
        $this->assertEquals(['message' => "It's a 'quoted' string"], $updatedContent);
    }

    public function test_it_checks_if_key_is_translated(): void
    {
        $content = [
            'title' => [
                'blog' => 'My Blog',
            ],
            'empty' => [],
        ];
        file_put_contents($this->testFilePath, '<?php return '.var_export($content, true).';');

        $transformer = new PHPLangTransformer($this->testFilePath);

        $this->assertTrue($transformer->isTranslated('title.blog'));
        $this->assertFalse($transformer->isTranslated('title.unknown'));
    }

    public function test_it_maintains_file_header_comments(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $transformer = new PHPLangTransformer($this->testFilePath, 'en');
        $transformer->updateString('test', 'Value');

        $fileContent = file_get_contents($this->testFilePath);

        $this->assertStringContainsString('WARNING: This is an auto-generated file', $fileContent);
        $this->assertStringContainsString('Do not modify this file manually', $fileContent);
        $this->assertStringContainsString('automatically translated from en', $fileContent);
    }

    public function test_it_properly_formats_nested_arrays(): void
    {
        Config::set('ai-translator.dot_notation', false);

        $transformer = new PHPLangTransformer($this->testFilePath);
        $transformer->updateString('level1.level2.level3', 'Deep Value');

        $fileContent = file_get_contents($this->testFilePath);

        // Check proper indentation
        $this->assertStringContainsString("'level1' => [", $fileContent);
        $this->assertStringContainsString("        'level2' => [", $fileContent);
        $this->assertStringContainsString("            'level3' => 'Deep Value'", $fileContent);
    }
}
