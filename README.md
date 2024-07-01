# Laravel AI Translator

Automatically translate your Laravel language files into multiple languages using AI with a single command!

## Overview

Laravel AI Translator is a powerful tool designed to streamline the localization process in Laravel projects. Born out of the frustration of manual translation work, this package automates the tedious task of translating strings across multiple languages.

Key benefits:
- Time-saving: Translate all your language files with one simple command
- AI-powered: Utilizes advanced AI to provide high-quality translations
- Smart context understanding: Respects variables, tenses, and linguistic nuances
- Seamless integration: Works within your existing Laravel project structure

Whether you're working on a personal project or a large-scale application, Laravel AI Translator simplifies the internationalization process, allowing you to focus on building great features instead of wrestling with translations.

## Key Features

This tool is designed to translate your language files intelligently:

- Contextual Understanding: Analyzes keys to determine if they represent buttons, descriptions, or other UI elements.
- Linguistic Precision: Preserves word forms, tenses, and punctuation in translations.
- Variable Handling: Respects and maintains your language file variables during translation.
- Smart Length Adaptation: Adjusts translation length to fit UI constraints where possible.
- Tone Consistency: Maintains a consistent tone across translations, customizable via configuration.

Do you want to know how this works? See the prompt in `src/AI`.

## Prerequisites

- PHP 8.0 or higher

## Installation

1. Install the package via composer:

```bash
composer require kargnas/laravel-ai-translator
```

2. Add the Anthropic API key to your `.env` file:

```
ANTHROPIC_API_KEY=your-api-key-here
```

You can obtain an API key from the [Anthropic website](https://www.anthropic.com) or your Anthropic account dashboard.

3. (Optional) Publish the configuration file:

```bash
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\LaravelAiTranslatorServiceProvider"
```

This step is optional but recommended if you want to customize the package's behavior. It will create a `config/ai-translator.php` file where you can modify various settings.

4. You're now ready to use the Laravel AI Translator!

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
  'notifications.new_feature_search_sentence' => '새로운 기능: 이제 단어뿐만 아니라 문장도 입력할 수 있어요. 심지어 여러분의 언어로도 가능해요.',
  'notifications.refresh_after_1_min' => '1분 후에 새로고침하세요. 새로운 내용이 준비될 거예요! (이전 모델: :model, 업데이트: :updated_at)',
);
```

Chinese (zh-cn):
```php
<?php
return array (
  'notifications.new_feature_search_sentence' => '新功能：现在你不仅可以输入单词，还可以输入句子。甚至可以用你的语言。',
  'notifications.refresh_after_1_min' => '1分钟后刷新。新内容即将到来！（之前的模型：:model，更新时间：:updated_at）',
);
```

Thai (th-th):
```php
<?php
return array (
  'notifications.new_feature_search_sentence' => 'ฟีเจอร์ใหม่: ตอนนี้คุณพิมพ์ประโยคได้แล้ว ไม่ใช่แค่คำเดียว แม้แต่ภาษาของคุณเอง',
  'notifications.refresh_after_1_min' => 'รีเฟรชหลังจาก 1 นาที จะมีเนื้อหาใหม่ให้ดู! (โมเดลก่อนหน้า: :model, อัปเดตเมื่อ: :updated_at)',
);
```

## Configuration

If you want to customize the settings, you can publish the configuration file:

```bash
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\LaravelAiTranslatorServiceProvider"
```

This will create a `config/ai-translator.php` file where you can modify the following settings:

1. `source_locale`: Change this to your default language in the Laravel project. The package will translate from this language.

2. `source_directory`: If you use a different directory for language files instead of the default `lang` directory, you can specify it here.

3. `ai`: Currently only supports Anthropic's Claude. You can configure the API key and model version here.

4. `locale_names`: This mapping of locale codes to language names enhances translation quality by providing context to the AI.

5. `additional_rules`: Add custom rules to the translation prompt. This is useful for customizing the style of the messages.

Example configuration:

```php
<?php

return [
    'source_locale' => 'en',
    'source_directory' => 'lang',

    'ai' => [
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-20240620',
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'locale_names' => [
        'en' => 'English',
        'ko' => 'Korean',
        'zh_cn' => 'Chinese (Simplified)',
        // ... other locales
    ],

    'additional_rules' => [
        'default' => [
            "Use a friendly and intuitive tone of voice, like the service tone of voice of 'Discord'.",
        ],
        'ko' => [
            "한국의 인터넷 서비스 '토스'의 서비스 말투 처럼, 유저에게 친근하고 직관적인 말투로 설명하고 존댓말로 설명하세요.",
        ],
    ],
];
```

Make sure to set your Anthropic API key in your `.env` file:

```
ANTHROPIC_API_KEY=your-api-key-here
```

## Supported File Types

Currently, this package only supports PHP language files used by Laravel. JSON language files are not supported at this time.

## AI Service

Currently, this package uses Claude from Anthropic for translations. Support for GPT-3.5, GPT-4, and GPT-4o is planned for future releases.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
