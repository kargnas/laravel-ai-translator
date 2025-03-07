<?php

// 더 간단하게 여러 항목 테스트

// 번역할 항목들
$items = [
    'first' => 'This is the first item with <b>tags</b>.',
    'second' => 'This is the second item with "quotes".',
    'third' => 'This is the third item with special chars & lines.\nSecond line here.'
];

// 개별적으로 각 항목 번역 테스트
foreach ($items as $key => $text) {
    echo "\n### Translating item: $key ###\n";
    $command = "php artisan ai-translator:test-translate --text=\"$text\"";
    system($command);
    echo "\n";
}

echo "\nAll tests completed.\n";
