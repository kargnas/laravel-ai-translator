<?php

namespace Kargnas\LaravelAiTranslator\Tests\Unit\AI;

use Kargnas\LaravelAiTranslator\AI\TranslationContextProvider;
use Kargnas\LaravelAiTranslator\Transformers\JSONLangTransformer;
use Kargnas\LaravelAiTranslator\Transformers\PHPLangTransformer;
use Kargnas\LaravelAiTranslator\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class TranslationContextProviderTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ai-translator-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir("{$this->tempDir}/en");
        mkdir("{$this->tempDir}/ko");
        mkdir("{$this->tempDir}/ja");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function removeDirectory($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object)) {
                    $this->removeDirectory($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        rmdir($dir);
    }

    public function test_loads_context_from_php_files(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create test PHP files
        file_put_contents("{$this->tempDir}/en/common.php", "<?php\nreturn ['hello' => 'Hello', 'world' => 'World'];");
        file_put_contents("{$this->tempDir}/ko/common.php", "<?php\nreturn ['hello' => '안녕하세요', 'world' => '세계'];");

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/common.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 100);

        $this->assertEquals('en', $context['source_locale']);
        $this->assertEquals('ja', $context['target_locale']);
        $this->assertCount(1, $context['references']);
        $this->assertEquals('ko', $context['references'][0]['locale']);
        
        // Check that we have context items
        $this->assertNotEmpty($context['items']);
        $this->assertGreaterThan(0, $context['item_count']);
        
        // Check content (the key may vary based on implementation)
        $firstItem = reset($context['items']);
        $this->assertEquals('Hello', $firstItem['source']);
        $this->assertEquals('안녕하세요', $firstItem['target']);
    }

    public function test_loads_context_from_json_files(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create test JSON files
        file_put_contents("{$this->tempDir}/en.json", json_encode(['hello' => 'Hello', 'world' => 'World']));
        file_put_contents("{$this->tempDir}/ko.json", json_encode(['hello' => '안녕하세요', 'world' => '세계']));

        $transformer = new JSONLangTransformer("{$this->tempDir}/en.json");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 100);

        $this->assertEquals('en', $context['source_locale']);
        $this->assertEquals('ja', $context['target_locale']);
        $this->assertCount(1, $context['references']);
        
        // Check that we have context items
        $this->assertNotEmpty($context['items']);
        $this->assertGreaterThan(0, $context['item_count']);
        
        // Check content
        $firstItem = reset($context['items']);
        $this->assertEquals('Hello', $firstItem['source']);
        $this->assertEquals('안녕하세요', $firstItem['target']);
    }

    public function test_respects_max_context_items_limit(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create test files with many items
        $manyItems = [];
        for ($i = 1; $i <= 100; $i++) {
            $manyItems["item{$i}"] = "Item {$i}";
        }
        
        file_put_contents("{$this->tempDir}/en/large.php", "<?php\nreturn " . var_export($manyItems, true) . ";");
        file_put_contents("{$this->tempDir}/ko/large.php", "<?php\nreturn " . var_export($manyItems, true) . ";");

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/large.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 10);

        $this->assertLessThanOrEqual(10, $context['item_count']);
        $this->assertCount($context['item_count'], $context['items']);
    }

    public function test_handles_missing_target_files_gracefully(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create only source file
        file_put_contents("{$this->tempDir}/en/missing.php", "<?php\nreturn ['hello' => 'Hello'];");
        // No corresponding ko/missing.php file

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/missing.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 100);

        $this->assertEmpty($context['items']);
        $this->assertEquals(0, $context['item_count']);
        $this->assertEquals(0, $context['file_count']);
    }

    public function test_prioritizes_shorter_strings(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create test files with strings of different lengths
        file_put_contents("{$this->tempDir}/en/priority.php", "<?php\nreturn [
            'short' => 'OK',
            'medium' => 'This is a medium length string',
            'long' => 'This is a very long string that contains a lot of text and should be deprioritized in favor of shorter strings',
        ];");
        file_put_contents("{$this->tempDir}/ko/priority.php", "<?php\nreturn [
            'short' => '확인',
            'medium' => '이것은 중간 길이의 문자열입니다',
            'long' => '이것은 매우 긴 문자열로 많은 텍스트를 포함하고 있으며 짧은 문자열을 우선시하기 위해 우선순위가 낮아야 합니다',
        ];");

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/priority.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 2);

        // Check that only 2 items are returned and they are the shortest
        $this->assertEquals(2, $context['item_count']);
        $this->assertCount(2, $context['items']);
        
        // Check that short strings are prioritized
        $values = array_column($context['items'], 'source');
        $this->assertContains('OK', $values);
        $this->assertContains('This is a medium length string', $values);
    }

    public function test_handles_multiple_reference_locales(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create test files for multiple reference locales
        file_put_contents("{$this->tempDir}/en/multi.php", "<?php\nreturn ['hello' => 'Hello'];");
        file_put_contents("{$this->tempDir}/ko/multi.php", "<?php\nreturn ['hello' => '안녕하세요'];");
        file_put_contents("{$this->tempDir}/ja/multi.php", "<?php\nreturn ['hello' => 'こんにちは'];");

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/multi.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        $context = $provider->getGlobalTranslationContext('fr', ['ko', 'ja'], 100);

        $this->assertCount(2, $context['references']);
        $this->assertEquals('ko', $context['references'][0]['locale']);
        $this->assertEquals('ja', $context['references'][1]['locale']);
        $this->assertEquals(2, $context['file_count']);
    }

    public function test_handles_invalid_files_gracefully(): void
    {
        // Set config for test
        config(['ai-translator.source_locale' => 'en']);
        
        // Create an invalid PHP file
        file_put_contents("{$this->tempDir}/en/invalid.php", "<?php\n// Invalid PHP file\nreturn 'not an array';");
        file_put_contents("{$this->tempDir}/ko/invalid.php", "<?php\nreturn ['valid' => 'data'];");

        $transformer = new PHPLangTransformer("{$this->tempDir}/en/invalid.php");
        $provider = new TranslationContextProvider($this->tempDir, $transformer);

        // Should not throw exception
        $context = $provider->getGlobalTranslationContext('ja', ['ko'], 100);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('items', $context);
    }
}