# Laravel AI Translator

This package will translate your English strings into multiple languages using AI in a single command!

I was struggling with translating my strings recently for my personal projects. I can use AI, but it is annoying and not convenient. So I just made this package to make it automation flow. When you add a new string in the default language (en), just run our translate command. It will translate into all languages.

Also, the detailed consideration is that this package will translate your strings more smartly. This will respect your variables, the tense of the expressions, and the length of the words.

## Prerequisites

- PHP 7.4 or higher
- Laravel 8.0 or higher

## Installation

You can install the package via composer:

```bash
composer require kargnas/laravel-ai-translator
```

## Usage

To translate your language files, run the following command:

```bash
php artisan ai-translator:translate
```

This command will:
1. Recognize all language folders in your `lang` directory
2. Use AI to translate the contents of the string files in the source language, English. (You can change the source language in the config file)

### Example

Given an English language file:

```php
<?php

return [
    'notifications' => [
        'new_feature_search_sentence' => 'New feature: Now you can type sentences not only words. Even in your languages. The AI will translate them to Chinese.',
        'refresh_after_1_min' => 'Refresh after 1 minutes. New content will be available! (The previous model: :model, Updated: :updated_at)',
    ]
];
```

The package will generate translations like these:

Korean (ko-kr):
```php
<?php
return array (
  'notifications.new_feature_search_sentence' => '새로운 기능: 이제 단어뿐만 아니라 문장도 입력할 수 있어요. 심지어 여러분의 언어로도 가능해요. AI가 알아서 중국어로 번역해 줄 거예요.',
  'notifications.refresh_after_1_min' => '1분 후에 새로고침하세요. 새로운 내용이 준비될 거예요! (이전 모델: :model, 업데이트: :updated_at)',
);
```

Chinese (zh-cn):
```php
<?php
return array (
  'notifications.new_feature_search_sentence' => '新功能：现在你不仅可以输入单词，还可以输入句子。甚至可以用你的语言。AI会把它们翻译成中文。',
  'notifications.refresh_after_1_min' => '1分钟后刷新。新内容即将到来！（之前的模型：:model，更新时间：:updated_at）',
);
```

Thai (th-th):
```php
<?php
return array (
  'notifications.new_feature_search_sentence' => 'ฟีเจอร์ใหม่: ตอนนี้คุณพิมพ์ประโยคได้แล้ว ไม่ใช่แค่คำเดียว แม้แต่ภาษาของคุณเอง AI จะแปลเป็นภาษาจีนให้',
  'notifications.refresh_after_1_min' => 'รีเฟรชหลังจาก 1 นาที จะมีเนื้อหาใหม่ให้ดู! (โมเดลก่อนหน้า: :model, อัปเดตเมื่อ: :updated_at)',
);
```

## Configuration

If you want to customize the source language or other settings, you can publish the configuration file:

```bash
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\LaravelAiTranslatorServiceProvider"
```

This will create a `config/ai-translator.php` file where you can modify the settings.

## Supported File Types

Currently, this package only supports PHP language files used by Laravel. JSON language files are not supported at this time.

## AI Service

Currently, this package uses Claude from Anthropic for translations. Support for GPT-3.5, GPT-4, and GPT-4 Turbo is planned for future releases.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
