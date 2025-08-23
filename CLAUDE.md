# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Configuration

### AI Provider Settings
For testing, use mock provider by default:
```php
// config/ai-translator.php
'ai' => [
    'provider' => 'mock',
    'model' => 'mock', 
    'api_key' => 'test',
],
```

For production, configure real providers:
```php
// Anthropic Claude
'ai' => [
    'provider' => 'anthropic',
    'model' => 'claude-3-5-sonnet-latest',
    'api_key' => 'your-anthropic-api-key',
],

// OpenAI GPT
'ai' => [
    'provider' => 'openai', 
    'model' => 'gpt-4o',
    'api_key' => 'your-openai-api-key',
],

// Google Gemini
'ai' => [
    'provider' => 'gemini',
    'model' => 'gemini-2.5-pro',
    'api_key' => 'your-gemini-api-key',
],
```

## Build & Development Commands

### Package Development
- **Install dependencies**: `composer install`
- **Run tests**: `./vendor/bin/pest`
- **Run specific test**: `./vendor/bin/pest --filter=TestName`
- **Coverage report**: `./vendor/bin/pest --coverage`
- **Static analysis**: `phpstan` (uses phpstan.neon configuration)

### Testing in Host Project
- **Publish config**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider" && cd modules/libraries/laravel-ai-translator`
- **Run translator**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate && cd modules/libraries/laravel-ai-translator`
- **Run parallel translator**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-parallel && cd modules/libraries/laravel-ai-translator`
- **Test translate**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:test && cd modules/libraries/laravel-ai-translator`
- **Translate JSON files**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-json && cd modules/libraries/laravel-ai-translator`
- **Translate strings**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-strings && cd modules/libraries/laravel-ai-translator`
- **Translate single file**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:translate-file lang/en/test.php && cd modules/libraries/laravel-ai-translator`
- **Find unused translations**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:find-unused && cd modules/libraries/laravel-ai-translator`
- **Clean translations**: `./scripts/test-setup.sh && cd ./laravel-ai-translator-test && php artisan ai-translator:clean && cd modules/libraries/laravel-ai-translator`

## Lint/Format Commands
- **PHP lint (Laravel Pint)**: `./vendor/bin/pint`
- **PHP CS Fixer**: `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php`
- **Check lint without fixing**: `./vendor/bin/pint --test`

## Code Style Guidelines

### PHP Standards
- **Version**: Minimum PHP 8.1
- **Standards**: Follow PSR-12 coding standard
- **Testing**: Use Pest for tests, follow existing test patterns

### Naming Conventions
- **Classes**: PascalCase (e.g., `TranslationPipeline`)
- **Methods/Functions**: camelCase (e.g., `getTranslation`)
- **Variables**: snake_case (e.g., `$source_locale`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `DEFAULT_LOCALE`)

### Code Practices
- **Type hints**: Always use PHP type declarations and return types
- **String interpolation**: Use "{$variable}" syntax, NEVER use sprintf()
- **Error handling**: Create custom exceptions in `src/Exceptions`, use try/catch blocks
- **File structure**: One class per file, match filename to class name
- **Imports**: Group by type (PHP core, Laravel, third-party, project), alphabetize within groups
- **Comments**: Use PHPDoc for public methods, inline comments sparingly for complex logic, ALWAYS in English
- **Using Facade**: Always use Laravel Facade with import when it's available. (e.g. Log, HTTP, Cache, ...)

### GIT
- Always write git commit message in English
- Always run `phpstan` before commit

## Plugin-Based Pipeline Architecture

### Architecture Pattern
The package now implements a **plugin-based pipeline architecture** that provides modular, extensible translation processing. This architecture follows Laravel's design patterns and enables easy customization without modifying core code.

### Core Architecture Components

#### 1. **Pipeline System** (`src/Core/`)
- **TranslationPipeline**: Orchestrates the entire translation workflow through defined stages
- **TranslationContext**: Central state container that maintains all translation data
- **PluginManager**: Manages plugin lifecycle, dependencies, and multi-tenant configurations
- **PipelineStages**: Defines 3 essential constants (TRANSLATION, VALIDATION, OUTPUT) with flexible string-based stages

#### 2. **Plugin Types** (Laravel-inspired patterns)

**Middleware Plugins** (`src/Plugins/Abstract/AbstractMiddlewarePlugin.php`)
- Transform data as it flows through the pipeline
- Examples: TokenChunkingPlugin, ValidationPlugin, PIIMaskingPlugin
- Similar to Laravel's HTTP middleware pattern

**Provider Plugins** (`src/Plugins/Abstract/AbstractProviderPlugin.php`)
- Supply core services and functionality  
- Examples: MultiProviderPlugin, StylePlugin, GlossaryPlugin
- Similar to Laravel's Service Providers

**Observer Plugins** (`src/Plugins/Abstract/AbstractObserverPlugin.php`)
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
    ->secure()  // Uses PIIMaskingPlugin
    ->translate($texts);
```

### Pipeline Stages
- **Essential Constants** (type-safe):
  - `PipelineStages::TRANSLATION`: Core translation execution
  - `PipelineStages::VALIDATION`: Translation quality validation
  - `PipelineStages::OUTPUT`: Final output handling
- **Flexible String Stages**: Plugins can define custom stages as strings (e.g., 'pre_process', 'chunking', 'consensus')

### Plugin Registration

#### Simple Registration Methods
1. **Plugin Instance**: `withPlugin(new MyPlugin())`
2. **Plugin Class**: `withPluginClass(MyPlugin::class, $config)`
3. **Inline Closure**: `withClosure('name', $callback)`

#### Auto-Registration
- Default plugins are registered automatically via ServiceProvider
- Custom plugins from `app/Plugins/Translation/` are discovered and loaded
- No configuration file changes needed for basic plugin usage

### Available Core Plugins

1. **StylePlugin**: Custom translation styles and tones
2. **GlossaryPlugin**: Consistent term translation
3. **DiffTrackingPlugin**: Skip unchanged content (60-80% cost reduction)
4. **TokenChunkingPlugin**: Optimal API chunking
5. **ValidationPlugin**: Quality assurance checks
6. **PIIMaskingPlugin**: PII protection (emails, phones, SSN, cards, IPs)
7. **StreamingOutputPlugin**: Real-time progress updates
8. **MultiProviderPlugin**: Consensus-based translation
9. **AnnotationContextPlugin**: Context from code comments

### Creating Custom Plugins

```php
class MyCustomPlugin extends AbstractMiddlewarePlugin {
    protected string $name = 'my_custom_plugin';
    
    protected function getStage(): string {
        return 'preparation'; // Or use custom string stage
    }
    
    public function handle(TranslationContext $context, Closure $next): mixed {
        // Your logic here
        return $next($context);
    }
}

// Usage
TranslationBuilder::make()
    ->withPlugin(new MyCustomPlugin())
    ->translate($texts);
```

### Multi-Tenant Support
Plugins can be configured per tenant for SaaS applications:
```php
$pluginManager->enableForTenant('tenant-123', StylePlugin::class, [
    'default_style' => 'casual'
]);
```

### Storage Adapters
The architecture supports multiple storage backends:
- **FileStorage**: Local filesystem storage
- **DatabaseStorage**: Laravel database storage  
- **RedisStorage**: Redis-based storage for high performance

## Architecture Overview

### Package Type
Laravel package for AI-powered translations supporting multiple AI providers (OpenAI, Anthropic Claude, Google Gemini).

### Key Components

1. **AI Layer** (`src/AI/`)
   - `Clients/`: Provider-specific implementations (OpenAI, Anthropic, Gemini)
   - `TranslationContextProvider.php`: Manages translation context and prompts
   - System and user prompts in `prompt-system.txt` and `prompt-user.txt`

2. **Console Commands** (`src/Console/`)
   - `TranslateStrings.php`: Translate PHP language files
   - `TranslateStringsParallel.php`: Parallel translation for multiple locales
   - `TranslateJson.php`: Translate JSON language files
   - `TranslateFileCommand.php`: Translate single file
   - `TestTranslateCommand.php`: Test translations with sample strings
   - `FindUnusedTranslations.php`: Find and remove unused translation keys
   - `CleanCommand.php`: Remove translations for re-generation
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
2. TranslationBuilder creates pipeline with configured plugins
3. Transformer converts to translatable format
4. Plugins process through 9 pipeline stages (pre_process, diff_detection, preparation, chunking, translation, consensus, validation, post_process, output)
5. MultiProviderPlugin executes AI translation with context
6. Parser validates and extracts translations
7. Plugins apply post-processing transformations
8. Transformer writes back to target language files

### Key Features
- Plugin-based architecture for extensibility
- Chunking for cost-effective API calls (60-80% cost reduction with DiffTrackingPlugin)
- Validation to ensure translation accuracy
- Support for variables, pluralization, and HTML
- Custom language styles (e.g., regional dialects)
- Token usage tracking and reporting
- PII protection with PIIMaskingPlugin
- Multi-tenant support for SaaS applications

### Plugin Usage Examples

```php
// E-commerce with PII protection
TranslationBuilder::make()
    ->from('en')->to(['ko', 'ja'])
    ->trackChanges()  // Skip unchanged products
    ->withTokenChunking(3000)  // Optimal chunk size
    ->withStyle('marketing', 'Use persuasive language')
    ->withGlossary(['Free Shipping' => ['ko' => '무료 배송']])
    ->secure()  // Mask customer data
    ->translate($texts);

// Multi-tenant configuration
TranslationBuilder::make()
    ->forTenant($tenantId)
    ->withStyle($tenant->style)
    ->withGlossary($tenant->glossary)
    ->secure()  // If tenant requires PII protection
    ->translate($texts);

// API documentation with code preservation
TranslationBuilder::make()
    ->withPlugin(new CodePreservationPlugin())
    ->withStyle('technical')
    ->withValidation(['variables'])
    ->translate($texts);
```

### Version Notes
- When tagging versions, use `commit version 1.7.13` instead of `v1.7.13`

## important-instruction-reminders
Do what has been asked; nothing more, nothing less.
NEVER create files unless they're absolutely necessary for achieving your goal.
ALWAYS prefer editing an existing file to creating a new one.
ALWAYS keep updating existing documentation files (*.md), CLAUDE and README files. Do that even you never mention it.
NEVER proactively create documentation files (*.md) or README files. Only create documentation files if explicitly requested by the User.
NEVER say 'all features are working correctly!' unless you successfully ran `phpstan`. Always run `phpstan` before saying that. (`./vendor/bin/phpstan --memory-limit=1G`)
NEVER ignore phpstan errors. You need to fix all the phpstan errors.