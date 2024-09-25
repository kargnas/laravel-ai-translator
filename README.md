# kargnas/laravel-ai-translator

AI-powered translation tool for Laravel language files

## 💡 New Feature: Custom Language Styles

We've expanded our capabilities with support for custom language styles, allowing for unique and creative translations. [Learn more about Custom Language Styles](#custom-language-styles)

## Overview

Laravel AI Translator is a powerful tool designed to streamline the localization process in Laravel projects. It automates the tedious task of translating strings across multiple languages, leveraging advanced AI models to provide high-quality, context-aware translations.

Key benefits:

- **Time-saving**: Translate all your language files with one simple command
- **AI-powered**: Utilizes state-of-the-art language models (GPT-4o, GPT-4, GPT-3.5, Claude) for superior translation quality
- **Smart context understanding**: Accurately captures nuances, technical terms, and Laravel-specific expressions
- **Seamless integration**: Works within your existing Laravel project structure, preserving complex language file structures
- **Flexible translation format**: Choose between flat arrays with dot notation keys or multi-dimensional arrays based on your preference

Whether you're working on a personal project or a large-scale application, Laravel AI Translator simplifies the internationalization process, allowing you to focus on building great features instead of wrestling with translations.

## Key Features

- Automatically detects all language folders in your `lang` directory
- Translates PHP language files from a source language (default: English) to all other languages
- Supports multiple AI providers for intelligent, context-aware translations
- Preserves variables, HTML tags, pluralization codes, and nested structures
- Maintains consistent tone and style across translations
- Supports custom translation rules for enhanced quality and project-specific requirements
- **Configurable translation format**: Store translations as flat arrays using dot notation keys or as multi-dimensional arrays
- Efficiently processes large language files, saving time and effort
- Respects Laravel's localization system, ensuring compatibility with your existing setup
- Chunking functionality for cost-effective translations: Processes multiple strings in a single AI request, significantly reducing API costs and improving efficiency
- String validation to ensure translation accuracy: Automatically checks and validates AI translations to catch and correct any errors or mistranslations

Also, this tool is designed to translate your language files intelligently:

- **Contextual Understanding**: Analyzes keys to determine if they represent buttons, descriptions, or other UI elements.
- **Linguistic Precision**: Preserves word forms, tenses, and punctuation in translations.
- **Variable Handling**: Respects and maintains your language file variables during translation.
- **Smart Length Adaptation**: Adjusts translation length to fit UI constraints where possible.
- **Tone Consistency**: Maintains a consistent tone across translations, customizable via configuration.

Do you want to know how this works? See the prompt in `src/AI`.

## Custom Language Styles

In addition to standard language translations, this package now supports custom language styles, allowing for unique and creative localizations.

### Built-in Styles

The package includes several built-in language styles:

- `ko_kp`: North Korean style Korean
- Various regional dialects and language variants

These are automatically available and don't require additional configuration.

### Custom Style Example: Reddit English

As a demonstration of custom styling capabilities, we've implemented a "Reddit style" English:

This style mimics the casual, often humorous language found on Reddit, featuring:

- Liberal use of sarcasm
- Internet slang and meme references
- Playful skepticism

Example configuration:

```php
'locale_names' => [
    'en_reddit' => 'English (Reddit)',
],
'additional_rules' => [
    'en_reddit' => [
        "- Incorporate sarcasm and exaggeration",
        "- Use popular internet slang and meme references",
        "- Add humorous calls for sources on obvious statements",
    ],
],
