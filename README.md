# Laravel AI Translator

Automatically translate your Laravel language files into multiple languages from English using AI with a single command!

## Overview

Laravel AI Translator is a powerful tool designed to streamline the localization process in Laravel projects. Born out of the frustration of manual translation work, this package automates the tedious task of translating strings across multiple languages.

Key benefits:
- Time-saving: Translate all your language files with one simple command
- AI-powered: Utilizes advanced AI (Claude or GPT) to provide high-quality translations
- Smart context understanding: Respects variables, tenses, and linguistic nuances
- Seamless integration: Works within your existing Laravel project structure

## Key Features

- Automatically detects all language folders in your `lang` directory (It's okay even if the directory is empty)
- Translates PHP language files from a source language (default: English) to all other languages
- Supports both Anthropic's Claude AI and OpenAI's GPT for intelligent translations
- Respects variables, tenses, and word lengths in translations
- Supports multiple locales out of the box

## Prerequisites

- PHP 8.0 or higher

## Installation

1. Install the package via composer:

```bash
composer require kargnas/laravel-ai-translator
```

2. Add the API key to your `.env` file:

For Anthropic Claude:
```
ANTHROPIC_API_KEY=your-api-key-here
```

For OpenAI GPT:
```
OPENAI_API_KEY=your-api-key-here
```

You can obtain API keys from the respective provider's website or dashboard.

## Configuration

If you want to customize the settings, you can publish the configuration file:

```bash
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\LaravelAiTranslatorServiceProvider"
```

This will create a `config/ai-translator.php` file where you can modify the following settings:

```php
<?php

return [
    'source_locale' => 'en',
    'source_directory' => 'lang',

    'ai' => [
        'provider' => 'openai', // or 'anthropic'
        'model' => 'gpt-4o', // or 'claude-3-5-sonnet-20240620' for Anthropic
        'api_key' => env('OPENAI_API_KEY'), // or env('ANTHROPIC_API_KEY') for Anthropic
    ],

    // ... other settings ...
];
```

## AI Service

This package supports two AI providers for translations:
1. Claude from Anthropic
2. GPT models from OpenAI (including GPT-3.5, GPT-4, GPT-4o, etc.)

You can switch between these providers by modifying the `ai.provider` setting in your configuration file.

## TODO List

We're constantly working to improve Laravel AI Translator. Here are some features and improvements we're planning:

- [ ] Expand support for other LLMs (such as Gemini)
- [ ] Implement strict validation for translations
- [ ] Write test code to ensure reliability and catch potential issues
- [ ] Implement functionality to maintain the array structure of strings during translation

If you'd like to contribute to any of these tasks, please feel free to submit a pull request!
