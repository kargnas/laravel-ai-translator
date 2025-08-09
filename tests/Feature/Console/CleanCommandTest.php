<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Kargnas\LaravelAiTranslator\Console\CleanCommand;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Set up test language file directory
    $this->testLangPath = __DIR__.'/../../Fixtures/lang-clean';
    
    // Configure AI translator settings
    Config::set('ai-translator.source_directory', $this->testLangPath);
    Config::set('ai-translator.source_locale', 'en');
    
    // Clean up any existing test directories
    if (File::exists($this->testLangPath)) {
        File::deleteDirectory($this->testLangPath);
    }
    
    // Create test directories and files
    setupTestFiles();
});

afterEach(function () {
    // Clean up test directories after each test
    if (File::exists($this->testLangPath)) {
        File::deleteDirectory($this->testLangPath);
    }
});

function setupTestFiles(): void
{
    $testLangPath = test()->testLangPath;
    
    // Create source (English) files
    File::makeDirectory("{$testLangPath}/en", 0755, true);
    
    // Create test.php
    File::put("{$testLangPath}/en/test.php", '<?php
return [
    "welcome" => "Welcome to our application",
    "hello" => "Hello :name",
    "products" => "You have :count product|You have :count products",
];');

    // Create nested.php with nested structure
    File::put("{$testLangPath}/en/nested.php", '<?php
return [
    "top" => "Top level",
    "section" => [
        "title" => "Section Title",
        "content" => "Section Content",
        "subsection" => [
            "item1" => "Item 1",
            "item2" => "Item 2",
            "deep" => [
                "level1" => "Level 1",
                "level2" => "Level 2",
            ],
        ],
    ],
    "footer" => "Footer text",
];');

    // Create subdirectory structure
    File::makeDirectory("{$testLangPath}/en/admin", 0755, true);
    File::put("{$testLangPath}/en/admin/dashboard.php", '<?php
return [
    "title" => "Admin Dashboard",
    "users" => "Users",
    "settings" => "Settings",
];');

    // Create target locale files (Korean)
    File::makeDirectory("{$testLangPath}/ko", 0755, true);
    
    File::put("{$testLangPath}/ko/test.php", '<?php
return [
    "welcome" => "애플리케이션에 오신 것을 환영합니다",
    "hello" => "안녕하세요 :name",
    "products" => "제품이 :count개 있습니다",
];');

    File::put("{$testLangPath}/ko/nested.php", '<?php
return [
    "top" => "최상위 레벨",
    "section" => [
        "title" => "섹션 제목",
        "content" => "섹션 내용",
        "subsection" => [
            "item1" => "항목 1",
            "item2" => "항목 2",
            "deep" => [
                "level1" => "레벨 1",
                "level2" => "레벨 2",
            ],
        ],
    ],
    "footer" => "푸터 텍스트",
];');

    File::makeDirectory("{$testLangPath}/ko/admin", 0755, true);
    File::put("{$testLangPath}/ko/admin/dashboard.php", '<?php
return [
    "title" => "관리자 대시보드",
    "users" => "사용자",
    "settings" => "설정",
];');

    // Create target locale files (Japanese)
    File::makeDirectory("{$testLangPath}/ja", 0755, true);
    
    File::put("{$testLangPath}/ja/test.php", '<?php
return [
    "welcome" => "アプリケーションへようこそ",
    "hello" => "こんにちは :name",
    "products" => ":count個の製品があります",
];');

    File::put("{$testLangPath}/ja/nested.php", '<?php
return [
    "top" => "トップレベル",
    "section" => [
        "title" => "セクションタイトル",
        "content" => "セクション内容",
        "subsection" => [
            "item1" => "アイテム1",
            "item2" => "アイテム2",
            "deep" => [
                "level1" => "レベル1",
                "level2" => "レベル2",
            ],
        ],
    ],
    "footer" => "フッターテキスト",
];');

    // Create JSON files
    File::put("{$testLangPath}/ko.json", json_encode([
        "Login" => "로그인",
        "Register" => "회원가입",
        "Forgot Password?" => "비밀번호를 잊으셨나요?",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    File::put("{$testLangPath}/ja.json", json_encode([
        "Login" => "ログイン",
        "Register" => "登録",
        "Forgot Password?" => "パスワードを忘れましたか？",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

test('command exists', function () {
    $this->assertTrue(class_exists(CleanCommand::class));
});

test('cleans all files when no pattern provided', function () {
    artisan('ai-translator:clean', [
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    // Check Korean files are empty
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->toBeEmpty();
    
    $koNested = include "{$this->testLangPath}/ko/nested.php";
    expect($koNested)->toBeEmpty();
    
    // Check Japanese files are empty
    $jaTest = include "{$this->testLangPath}/ja/test.php";
    expect($jaTest)->toBeEmpty();
    
    // Check JSON files are empty
    $koJson = json_decode(File::get("{$this->testLangPath}/ko.json"), true);
    expect($koJson)->toBeEmpty();
    
    $jaJson = json_decode(File::get("{$this->testLangPath}/ja.json"), true);
    expect($jaJson)->toBeEmpty();
    
    // Source files should remain unchanged
    $enTest = include "{$this->testLangPath}/en/test.php";
    expect($enTest)->not->toBeEmpty();
    expect($enTest)->toHaveKey('welcome');
});

test('cleans specific file pattern', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    // test.php should be empty
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->toBeEmpty();
    
    // nested.php should remain unchanged
    $koNested = include "{$this->testLangPath}/ko/nested.php";
    expect($koNested)->not->toBeEmpty();
    expect($koNested)->toHaveKey('top');
    
    // JSON files should remain unchanged
    $koJson = json_decode(File::get("{$this->testLangPath}/ko.json"), true);
    expect($koJson)->not->toBeEmpty();
});

test('cleans specific key pattern', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test.welcome',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koTest = include "{$this->testLangPath}/ko/test.php";
    
    // 'welcome' key should be removed
    expect($koTest)->not->toHaveKey('welcome');
    
    // Other keys should remain
    expect($koTest)->toHaveKey('hello');
    expect($koTest)->toHaveKey('products');
});

test('cleans nested key pattern', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'nested.section.subsection',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koNested = include "{$this->testLangPath}/ko/nested.php";
    
    // Check structure
    expect($koNested)->toHaveKey('top');
    expect($koNested)->toHaveKey('section');
    expect($koNested['section'])->toHaveKey('title');
    expect($koNested['section'])->toHaveKey('content');
    
    // 'subsection' should be completely removed
    expect($koNested['section'])->not->toHaveKey('subsection');
    
    // Footer should remain
    expect($koNested)->toHaveKey('footer');
});

test('removes empty arrays after cleaning', function () {
    // Create a file with deeply nested structure
    File::put("{$this->testLangPath}/ko/deeply.php", '<?php
return [
    "keep" => "Keep this",
    "remove" => [
        "all" => [
            "of" => [
                "this" => "Remove this",
            ],
        ],
    ],
];');

    artisan('ai-translator:clean', [
        'pattern' => 'deeply.remove',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koDeeply = include "{$this->testLangPath}/ko/deeply.php";
    
    // 'keep' should remain
    expect($koDeeply)->toHaveKey('keep');
    expect($koDeeply['keep'])->toBe('Keep this');
    
    // 'remove' and all its nested empty arrays should be gone
    expect($koDeeply)->not->toHaveKey('remove');
});

test('cleans subdirectory pattern', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'admin/dashboard',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    // admin/dashboard.php should be empty
    $koDashboard = include "{$this->testLangPath}/ko/admin/dashboard.php";
    expect($koDashboard)->toBeEmpty();
    
    // Other files should remain unchanged
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->not->toBeEmpty();
});

test('cleans subdirectory with key pattern', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'admin/dashboard.users',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koDashboard = include "{$this->testLangPath}/ko/admin/dashboard.php";
    
    // 'users' key should be removed
    expect($koDashboard)->not->toHaveKey('users');
    
    // Other keys should remain
    expect($koDashboard)->toHaveKey('title');
    expect($koDashboard)->toHaveKey('settings');
});

test('creates backup when not disabled', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test',
        '--force' => true,
    ])->assertSuccessful();

    // Backup directory should exist
    expect(File::exists("{$this->testLangPath}/backup"))->toBeTrue();
    
    // Backup files should exist
    expect(File::exists("{$this->testLangPath}/backup/ko/test.php"))->toBeTrue();
    expect(File::exists("{$this->testLangPath}/backup/ja/test.php"))->toBeTrue();
    
    // Backup info file should exist
    expect(File::exists("{$this->testLangPath}/backup/backup_info.txt"))->toBeTrue();
    
    // Backup content should match original
    $backupContent = include "{$this->testLangPath}/backup/ko/test.php";
    expect($backupContent)->toHaveKey('welcome');
    expect($backupContent['welcome'])->toBe('애플리케이션에 오신 것을 환영합니다');
});

test('respects dry run option', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test',
        '--dry-run' => true,
    ])->assertSuccessful();

    // Files should remain unchanged
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->not->toBeEmpty();
    expect($koTest)->toHaveKey('welcome');
    
    // No backup should be created in dry-run mode
    expect(File::exists("{$this->testLangPath}/backup"))->toBeFalse();
});

test('excludes source locale', function () {
    artisan('ai-translator:clean', [
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    // Source files should remain unchanged
    $enTest = include "{$this->testLangPath}/en/test.php";
    expect($enTest)->not->toBeEmpty();
    expect($enTest)->toHaveKey('welcome');
    
    // Target files should be empty
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->toBeEmpty();
});

test('handles json file key removal', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'ko.Login',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koJson = json_decode(File::get("{$this->testLangPath}/ko.json"), true);
    
    // 'Login' key should be removed
    expect($koJson)->not->toHaveKey('Login');
    
    // Other keys should remain
    expect($koJson)->toHaveKey('Register');
    expect($koJson)->toHaveKey('Forgot Password?');
});

test('cleans multiple locales simultaneously', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test.hello',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    // Check Korean
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->not->toHaveKey('hello');
    expect($koTest)->toHaveKey('welcome');
    
    // Check Japanese
    $jaTest = include "{$this->testLangPath}/ja/test.php";
    expect($jaTest)->not->toHaveKey('hello');
    expect($jaTest)->toHaveKey('welcome');
});

test('handles deep nested removal correctly', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'nested.section.subsection.deep',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koNested = include "{$this->testLangPath}/ko/nested.php";
    
    // Check that only 'deep' is removed, not entire 'subsection'
    expect($koNested)->toHaveKey('section');
    expect($koNested['section'])->toHaveKey('subsection');
    expect($koNested['section']['subsection'])->toHaveKey('item1');
    expect($koNested['section']['subsection'])->toHaveKey('item2');
    expect($koNested['section']['subsection'])->not->toHaveKey('deep');
});

test('fails when backup directory exists', function () {
    // Create existing backup directory
    File::makeDirectory("{$this->testLangPath}/backup", 0755, true);
    File::put("{$this->testLangPath}/backup/test.txt", 'existing backup');
    
    artisan('ai-translator:clean', [
        '--force' => true,
    ])
    ->expectsOutput("Backup directory already exists at: {$this->testLangPath}/backup")
    ->assertFailed();
    
    // Files should remain unchanged
    $koTest = include "{$this->testLangPath}/ko/test.php";
    expect($koTest)->not->toBeEmpty();
});

test('shows correct stats in dry run', function () {
    artisan('ai-translator:clean', [
        'pattern' => 'test.welcome',
        '--dry-run' => true,
    ])
    ->expectsOutputToContain('DRY RUN MODE')
    ->expectsOutputToContain('test.welcome')
    ->expectsOutputToContain('Total locales to clean:')
    ->assertSuccessful();
});

test('handles pattern with multiple dots correctly', function () {
    // Create a file with multiple nested levels
    File::put("{$this->testLangPath}/ko/multi.php", '<?php
return [
    "level1" => [
        "level2" => [
            "level3" => [
                "level4" => [
                    "deep" => "Very deep value",
                    "another" => "Another deep value",
                ],
                "keep" => "Keep this",
            ],
        ],
    ],
];');

    artisan('ai-translator:clean', [
        'pattern' => 'multi.level1.level2.level3.level4',
        '--force' => true,
        '--no-backup' => true,
    ])->assertSuccessful();

    $koMulti = include "{$this->testLangPath}/ko/multi.php";
    
    // level4 should be removed
    expect($koMulti['level1']['level2']['level3'])->not->toHaveKey('level4');
    
    // 'keep' should remain
    expect($koMulti['level1']['level2']['level3'])->toHaveKey('keep');
});