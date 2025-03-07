# CLAUDE.md - Laravel AI Translator Guidelines

## Build Commands
- **Install dependencies**: `composer install`
- **Publish config**: `cd /Volumes/Data/projects/test.af && php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider" && cd modules/libraries/laravel-ai-translator`
- **Run translator**: `cd /Volumes/Data/projects/test.af && php artisan ai-translator:translate && cd modules/libraries/laravel-ai-translator`
- **Test translate**: `cd /Volumes/Data/projects/test.af && php artisan ai-translator:test && cd modules/libraries/laravel-ai-translator`
- **Single test**: `./vendor/bin/phpunit --filter=TestName`

## Lint/Format Commands
- **PHP lint**: `./vendor/bin/pint`
- **PHP CS Fixer**: `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php`

## Code Style Guidelines
- **PHP**: Follow PSR-12, minimum PHP 8.0
- **Imports**: Group and alphabetize imports
- **Naming**: PascalCase for classes, snake_case for methods and variables
- **Types**: Use PHP type hints where possible
- **Error handling**: Create custom exceptions, use try/catch blocks
- **String interpolation**: Use "{$variable}" not sprintf()
- **Files**: One class per file
- **NO-sprintf**: Never use sprintf. Use "{$variable}" for string interpolation.

## Project Structure
- Laravel package for AI-powered translations
- AI providers in `src/AI/Clients`
- Translation utilities in `src/Transformers` 
- Commands in `src/Console`