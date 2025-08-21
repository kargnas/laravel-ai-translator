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
- **Using Facade**: Always use Laravel Facade with import when it's available. (e.g. Log, HTTP, Cache, ...)

## Plugin-Based Pipeline Architecture

### Architecture Pattern
The package now implements a **plugin-based pipeline architecture** that provides modular, extensible translation processing. This architecture follows Laravel's design patterns and enables easy customization without modifying core code.

### Core Architecture Components

#### 1. **Pipeline System** (`src/Core/`)
- **TranslationPipeline**: Orchestrates the entire translation workflow through 9 defined stages
- **TranslationContext**: Central state container that maintains all translation data
- **PluginManager**: Manages plugin lifecycle, dependencies, and multi-tenant configurations
- **PluginRegistry**: Tracks plugin metadata and dependency graphs

#### 2. **Plugin Types** (Laravel-inspired patterns)

**Middleware Plugins** (`src/Plugins/Abstract/MiddlewarePlugin.php`)
- Transform data as it flows through the pipeline
- Examples: TokenChunkingPlugin, ValidationPlugin, PIIMaskingPlugin
- Similar to Laravel's HTTP middleware pattern

**Provider Plugins** (`src/Plugins/Abstract/ProviderPlugin.php`)
- Supply core services and functionality
- Examples: MultiProviderPlugin, StylePlugin, GlossaryPlugin
- Similar to Laravel's Service Providers

**Observer Plugins** (`src/Plugins/Abstract/ObserverPlugin.php`)
- React to events without modifying data flow
- Examples: DiffTrackingPlugin, StreamingOutputPlugin, AnnotationContextPlugin
- Similar to Laravel's Event Listeners

#### 3. **User API** (`src/TranslationBuilder.php`)
Fluent, chainable interface for building translation requests:
```php
$result = TranslationBuilder::make()
    ->from('en')->to(['ko', 'ja'])
    ->withStyle('formal')
    ->withProviders(['claude', 'gpt-4'])
    ->trackChanges()
    ->translate($texts);
```

### Pipeline Stages
1. **pre_process**: Initial text preparation and style configuration
2. **diff_detection**: Identify changed content to avoid retranslation
3. **preparation**: Apply glossaries and extract context
4. **chunking**: Split texts into optimal token sizes
5. **translation**: Execute AI translation
6. **consensus**: Select best translation from multiple providers
7. **validation**: Verify translation quality and accuracy
8. **post_process**: Final transformations and cleanup
9. **output**: Stream results to client

### Plugin Development Guide

#### Creating a Custom Plugin
1. Choose the appropriate base class:
   - Extend `AbstractMiddlewarePlugin` for data transformation
   - Extend `AbstractProviderPlugin` for service provision
   - Extend `AbstractObserverPlugin` for event monitoring

2. Implement required methods:
```php
class MyCustomPlugin extends AbstractMiddlewarePlugin {
    protected string $name = 'my_custom_plugin';
    
    protected function getStage(): string {
        return 'preparation'; // Choose appropriate stage
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed {
        // Your logic here
        return $next($context);
    }
}
```

3. Register the plugin:
```php
TranslationBuilder::make()
    ->withPlugin(new MyCustomPlugin())
    ->translate($texts);
```

### Multi-Tenant Support
Plugins can be configured per tenant for SaaS applications:
```php
$pluginManager->enableForTenant('tenant-123', 'style', [
    'default_style' => 'casual'
]);
```

### Storage Adapters
The architecture supports multiple storage backends for state persistence:
- **FileStorage**: Local filesystem storage
- **DatabaseStorage**: Laravel database storage
- **RedisStorage**: Redis-based storage for high performance

## Architecture Overview

### Package Type
Laravel package for AI-powered translations supporting multiple AI providers (OpenAI, Anthropic Claude, Google Gemini).

### Key Components

1. **AI Layer** (`src/AI/`)
   - `AIProvider.php`: Factory for creating AI clients
   - `Clients/`: Provider-specific implementations (OpenAI, Anthropic, Gemini)
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