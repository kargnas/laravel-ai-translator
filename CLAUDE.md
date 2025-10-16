# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

### Package Development
- **Install dependencies**: `composer install`
- **Run tests**: `./vendor/bin/pest`
- **Run specific test**: `./vendor/bin/pest --filter=TestName`
- **Coverage report**: `./vendor/bin/pest --coverage`

### Testing in Host Project
- **Publish config**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider" && cd modules/libraries/laravel-ai-translator`
- **Run translator**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate && cd modules/libraries/laravel-ai-translator`
- **Run parallel translator**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-parallel && cd modules/libraries/laravel-ai-translator`
- **Test translate**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:test && cd modules/libraries/laravel-ai-translator`
- **Translate JSON files**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-json && cd modules/libraries/laravel-ai-translator`
- **Translate strings**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-strings && cd modules/libraries/laravel-ai-translator`
- **Translate single file**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-file lang/en/test.php && cd modules/libraries/laravel-ai-translator`

## Lint/Format Commands
- **PHP lint (Laravel Pint)**: `./vendor/bin/pint`
- **PHP CS Fixer**: `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php`
- **Check lint without fixing**: `./vendor/bin/pint --test`

## Code Style Guidelines

### PHP Standards
- **Version**: Minimum PHP 8.0, use PHP 8.1+ features where available
- **Standards**: Follow PSR-12 coding standard
- **Testing**: Use Pest for tests, follow existing test patterns

### Naming Conventions
- **Classes**: PascalCase (e.g., `TranslateStrings`)
- **Methods/Functions**: camelCase (e.g., `getTranslation`)
- **Variables**: snake_case (e.g., `$source_locale`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `DEFAULT_LOCALE`)

### Code Practices
- **Type hints**: Always use PHP type declarations and return types
- **String interpolation**: Use "{$variable}" syntax, NEVER use sprintf()
- **Error handling**: Create custom exceptions in `src/Exceptions`, use try/catch blocks
- **File structure**: One class per file, match filename to class name
- **Imports**: Group by type (PHP core, Laravel, third-party, project), alphabetize within groups
- **Comments**: Use PHPDoc for public methods, inline comments sparingly for complex logic

## Architecture Overview

### Package Type
Laravel package for AI-powered translations supporting multiple AI providers via Prism (Anthropic Claude, OpenAI, Google Gemini, OpenRouter, and more).

### Key Components

1. **AI Layer** (`src/AI/`)
   - `AIProvider.php`: Factory for creating AI clients
   - Prism-driven provider integration replaces custom HTTP clients
   - `TranslationContextProvider.php`: Manages translation context and prompts
   - System and user prompts in `prompt-system.txt` and `prompt-user.txt`

2. **Console Commands** (`src/Console/`)
   - `TranslateStrings.php`: Translate PHP language files
   - `TranslateStringsParallel.php`: Parallel translation for multiple locales
   - `TranslateJson.php`: Translate JSON language files
   - `TranslateFileCommand.php`: Translate single file
   - `TestTranslateCommand.php`: Test translations with sample strings
   - `CrowdIn/`: Integration with CrowdIn translation platform

3. **Transformers** (`src/Transformers/`)
   - `PHPLangTransformer.php`: Handles PHP array language files
   - `JSONLangTransformer.php`: Handles JSON language files

4. **Language Support** (`src/Language/`)
   - `Language.php`: Language detection and metadata
   - `LanguageConfig.php`: Language-specific configurations
   - `LanguageRules.php`: Translation rules per language
   - `PluralRules.php`: Pluralization handling

5. **Parsing** (`src/AI/Parsers/`)
   - `XMLParser.php`: Parses AI responses in XML format
   - `AIResponseParser.php`: Validates and processes AI translations

### Translation Flow
1. Command reads source language files
2. Transformer converts to translatable format
3. AIProvider chunks strings for efficient API usage
4. AI translates with context from TranslationContextProvider
5. Parser validates and extracts translations
6. Transformer writes back to target language files

### Key Features
- Chunking for cost-effective API calls
- Validation to ensure translation accuracy
- Support for variables, pluralization, and HTML
- Custom language styles (e.g., regional dialects)
- Token usage tracking and reporting

### Version Notes
- When tagging versions, use `commit version 1.7.13` instead of `v1.7.13`