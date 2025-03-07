<?php

// 현재 디렉토리를 설정
chdir(__DIR__);

// Artisan 명령어로 여러 항목을 번역하는 테스트
// 여러 번역할 항목들
$items = [
    'item1' => 'Hello, world\! This is the first item.',
    'item2' => 'This is the second item with <b>HTML</b> tags.',
    'item3' => 'Third item with "quotes" and special characters.',
    'item4' => 'Fourth item with multiple lines.
Second line.
Third line.',
    'item5' => 'Fifth item with emoji 🚀 and Japanese 日本語.'
];

// temp.php 파일에 저장
file_put_contents('temp.php', '<?php return ' . var_export($items, true) . ';');

// 번역 명령 실행 (디버그 모드 활성화 및 AI 응답 표시)
$command = 'cd ../../../ && php artisan ai-translator:translate-file modules/libraries/laravel-ai-translator/temp.php ko en --debug --show-ai-response -vvv && cd modules/libraries/laravel-ai-translator';
echo "Executing: $command\n";
system($command);

// 결과 확인
echo "\nTranslation results:\n";
$results = include('temp-ko.php');
foreach ($results as $key => $text) {
    echo "[$key] => $text\n";
}

// 임시 파일 삭제
if (file_exists('temp.php')) {
    unlink('temp.php');
}
if (file_exists('temp-ko.php')) {
    unlink('temp-ko.php');
}

echo "\nTest completed.\n";
