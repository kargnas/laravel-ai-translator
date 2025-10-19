# AI Agent Instructions for Laravel AI Translator

> 📦 **Project Type**: Laravel Composer Package
> 🔧 **Framework**: Laravel 8.0+ / PHP 8.2+
> 🌐 **Primary Language**: English (project language), Korean (developer preference)

## 🚀 Quick Start & Local Development

### Prerequisites
| Requirement | Minimum Version | Notes |
|------------|----------------|-------|
| **PHP** | 8.2 | Use PHP 8.2+ features (readonly properties, enums, etc.) |
| **Composer** | 2.0+ | Required for dependency management |
| **Laravel** | 8.0+ | Package compatible with Laravel 8-11 |

### Initial Setup
```bash
# 1. Install dependencies
composer install

# 2. Run tests to verify setup
./vendor/bin/pest

# 3. Run static analysis
./vendor/bin/phpstan analyse
```

### Development Workflow

#### 🧪 Testing Commands
| Command | Purpose | When to Use |
|---------|---------|-------------|
| `./vendor/bin/pest` | Run all tests | Before commits, after changes |
| `./vendor/bin/pest --filter=TestName` | Run specific test | Debugging specific functionality |
| `./vendor/bin/pest --coverage` | Coverage report | Before PR submission |
| `./vendor/bin/phpstan analyse` | Static analysis | Before commits (Level 5) |

#### 🎨 Code Quality Commands
| Command | Purpose | Auto-fix? |
|---------|---------|-----------|
| `./vendor/bin/pint` | Format code (Laravel Pint) | ✅ Yes |
| `./vendor/bin/pint --test` | Check formatting only | ❌ No |
| `./vendor/bin/phpstan analyse` | Static analysis | ❌ No |

#### 🔧 Testing in Host Laravel Project
The package includes `laravel-ai-translator-test/` for integration testing:

```bash
# Setup test environment and run commands
./scripts/test-setup.sh && cd ./laravel-ai-translator-test

# Test translation commands
php artisan ai-translator:translate              # Translate PHP files
php artisan ai-translator:translate-parallel     # Parallel translation
php artisan ai-translator:translate-json         # Translate JSON files
php artisan ai-translator:test                   # Test with sample strings

# Return to package root
cd modules/libraries/laravel-ai-translator
```

## 📐 Code Style Guidelines

### 🔤 Naming Conventions
```php
// Classes: PascalCase
class TranslateStrings {}

// Methods/Functions: camelCase
public function getTranslation() {}

// Variables: snake_case (Laravel convention)
$source_locale = 'en';

// Constants: UPPER_SNAKE_CASE
const DEFAULT_LOCALE = 'en';

// Enums: PascalCase (PHP 8.1+)
enum TranslationStatus { case PENDING; }
```

### ⚠️ Mandatory Practices

**NEVER DO:**
- ❌ Use `sprintf()` for string interpolation
- ❌ Edit `composer.json` directly for package updates
- ❌ Skip type hints on public methods
- ❌ Use loose comparison (`==`) where strict (`===`) is appropriate

**ALWAYS DO:**
- ✅ Use `"{$variable}"` syntax for string interpolation
- ✅ Use `composer require/update` for package management
- ✅ Add PHP type declarations and return types
- ✅ Create custom exceptions in `src/Exceptions/` for error handling
- ✅ Use PHPDoc blocks for public methods
- ✅ Follow PSR-12 coding standard
- ✅ One class per file, filename matches class name
- ✅ Group imports: PHP core → Laravel → third-party → project (alphabetized)

### 📝 Code Comments
```php
/**
 * PHPDoc for public methods with params and returns
 *
 * @param string $locale Target locale code
 * @return array Translated strings
 */
public function translate(string $locale): array {}

// Inline comments only for complex logic
// Not for obvious operations
```

## 🏗️ Architecture Overview

### Package Type & Purpose
**Laravel AI Translator** is a Composer package that automates translation of Laravel language files using multiple AI providers (OpenAI GPT, Anthropic Claude, Google Gemini, via Prism PHP).

### 🎯 Core Architecture: Plugin-Based Translation Pipeline

#### 1. **Translation Pipeline** (`src/Core/TranslationPipeline.php`)
**Central execution engine** managing the complete translation workflow:

```
┌─────────────────────────────────────────────────────────┐
│  TranslationRequest → TranslationPipeline → Generator   │
│                                                         │
│  Stages:                                                │
│  1. Pre-process     → Clean/prepare input              │
│  2. Diff Detection  → Track changes from previous       │
│  3. Preparation     → Context building                  │
│  4. Chunking        → Split for API efficiency         │
│  5. Translation     → AI provider execution             │
│  6. Consensus       → Multi-provider agreement          │
│  7. Validation      → Verify translation accuracy       │
│  8. Post-process    → Format output                     │
│  9. Output          → Stream results                    │
└─────────────────────────────────────────────────────────┘
```

**Key Features:**
- 🔌 Plugin lifecycle management (Middleware, Provider, Observer)
- 🔄 Streaming via PHP Generators for memory efficiency
- 🎭 Event emission (`translation.started`, `stage.*.completed`)
- 🧩 Service registry for plugin-provided capabilities

#### 2. **TranslationBuilder** (`src/TranslationBuilder.php`)
**Fluent API** for constructing translation requests:

```php
// Example: Fluent translation configuration
$result = TranslationBuilder::make()
    ->from('en')->to('ko')
    ->withStyle('formal')
    ->withProviders(['claude-sonnet-4', 'gpt-4o'])
    ->withGlossary(['API' => 'API'])
    ->trackChanges()
    ->translate($texts);
```

**Builder Methods:**
| Method | Purpose | Plugin Loaded |
|--------|---------|---------------|
| `from()` / `to()` | Set source/target locales | - |
| `withStyle()` | Apply translation style | `StylePlugin` |
| `withProviders()` | Configure AI providers | `MultiProviderPlugin` |
| `withGlossary()` | Set terminology rules | `GlossaryPlugin` |
| `trackChanges()` | Enable diff tracking | `DiffTrackingPlugin` |
| `withValidation()` | Add validation checks | `ValidationPlugin` |
| `secure()` | Enable PII masking | `PIIMaskingPlugin` |
| `withPlugin()` | Add custom plugin instance | Custom |

#### 3. **Plugin System** (`src/Core/PluginManager.php`, `src/Contracts/`, `src/Plugins/`)

**Three Plugin Types:**

##### A. **Provider Plugins** (`src/Plugins/Provider/`)
Supply services at specific pipeline stages:
- `StylePlugin`: Apply language-specific tone/style rules
- `GlossaryPlugin`: Enforce terminology consistency

##### B. **Middleware Plugins** (`src/Plugins/Middleware/`)
Transform data through the pipeline:
- `TokenChunkingPlugin`: Split texts for API limits
- `ValidationPlugin`: Verify translation accuracy
- `DiffTrackingPlugin`: Track changes from previous translations
- `PIIMaskingPlugin`: Protect sensitive data
- `MultiProviderPlugin`: Consensus from multiple AI providers

##### C. **Observer Plugins** (`src/Plugins/Observer/`)
React to events without modifying data:
- `StreamingOutputPlugin`: Real-time console output
- `AnnotationContextPlugin`: Add translation context

**Plugin Registration Flow:**
```
ServiceProvider → PluginManager → TranslationPipeline
       ↓                ↓                  ↓
Default Plugins  Custom Plugins     Boot Lifecycle
```

### 📦 Key Components

#### Console Commands (`src/Console/`)
| Command | Purpose | File Type |
|---------|---------|-----------|
| `ai-translator:translate` | Translate PHP files | PHP arrays |
| `ai-translator:translate-parallel` | Parallel multi-locale | PHP arrays |
| `ai-translator:translate-json` | Translate JSON files | JSON |
| `ai-translator:translate-file` | Single file translation | Both |
| `ai-translator:test` | Test with samples | - |
| `ai-translator:find-unused` | Find unused keys | - |
| `ai-translator:clean` | Remove translations | - |
| `CrowdIn/` | CrowdIn integration | - |

#### Transformers (`src/Transformers/`)
- `PHPLangTransformer`: Handle PHP array language files
- `JSONLangTransformer`: Handle JSON language files
- Interface: `TransformerInterface`

#### Language Support (`src/Support/Language/`)
- `Language.php`: Language detection and metadata
- `LanguageConfig.php`: Language-specific configurations
- `LanguageRules.php`: Translation rules per language
- `PluralRules.php`: Pluralization handling

#### AI Integration (`src/Providers/AI/`)
**Uses Prism PHP** (`prism-php/prism`) for unified AI provider interface:
- OpenAI (GPT-4, GPT-4o, GPT-4o-mini)
- Anthropic (Claude Sonnet 4, Claude 3.7 Sonnet, Claude 3 Haiku)
- Google (Gemini 2.5 Pro, Gemini 2.5 Flash)

**Prompt Management** (`resources/prompts/`):
- `system-prompt.txt`: System instructions for AI
- `user-prompt.txt`: User message template

#### Parsing & Validation (`src/Support/Parsers/`)
- `XMLParser.php`: Parse AI XML responses
- Validates variables, pluralization, HTML preservation

### 🔄 Complete Translation Flow

```
1. Command Execution
   ├─ Read source language files
   └─ Create TranslationRequest
        ↓
2. TranslationBuilder Configuration
   ├─ Set locales, styles, providers
   └─ Load plugins via PluginManager
        ↓
3. TranslationPipeline Processing
   ├─ Pre-process (clean input)
   ├─ Diff Detection (track changes)
   ├─ Preparation (build context)
   ├─ Chunking (split for API)
   ├─ Translation (AI provider via Prism)
   ├─ Consensus (multi-provider)
   ├─ Validation (verify accuracy)
   └─ Post-process (format output)
        ↓
4. Output & Storage
   ├─ Stream results via Generator
   └─ Transformer writes to files
```

### 🎨 Key Features
- ⚡ **Chunking**: Cost-effective API calls via `TokenChunkingPlugin`
- ✅ **Validation**: Automatic accuracy verification via `ValidationPlugin`
- 🔄 **Streaming**: Memory-efficient via PHP Generators
- 🌍 **Multi-provider**: Consensus from multiple AI models
- 🎭 **Custom Styles**: Regional dialects, tones (Reddit, North Korean, etc.)
- 📊 **Token Tracking**: Cost monitoring and reporting
- 🧩 **Extensible**: Custom plugins via plugin system

### 📋 Version Management
When tagging releases:
```bash
# ✅ Correct
git tag 1.7.21
git push origin 1.7.21

# ❌ Incorrect
git tag v1.7.21  # Don't use 'v' prefix
```

## 🛠️ Development Best Practices

### Dependencies Management
**Package Updates:**
```bash
# ✅ Use Composer commands
composer require new-package
composer update package-name

# ❌ Never edit composer.json directly
# Edit config/ai-translator.php for package settings
```

### Testing Strategy
```bash
# Before committing
./vendor/bin/pint              # Format code
./vendor/bin/phpstan analyse   # Static analysis
./vendor/bin/pest              # Run tests

# Integration testing
./scripts/test-setup.sh && cd laravel-ai-translator-test
php artisan ai-translator:test
```

### PHPStan Configuration
- **Level**: 5 (see `phpstan.neon`)
- **Ignored**: Laravel facades, test properties, reflection methods
- Focus: Type safety, null safety, undefined variables

## 🌍 Localization Notes

### Project Languages
- **Code & Comments**: English (mandatory per commit `2ff6f77`)
- **Console Output**: Dynamic based on Laravel locale
- **Documentation**: English (README.md)

### UI/UX Writing Style
The package uses configurable tone of voice per locale:
- **Korean (`ko`)**: Toss-style friendly formal (친근한 존댓말)
- **English (`default`)**: Discord-style friendly
- **Custom**: Define in `config/ai-translator.php` → `additional_rules`

## 📚 Additional Resources

### Important Directories
```
├── src/                      # Source code
│   ├── Core/                # Pipeline, PluginManager
│   ├── Console/             # Artisan commands
│   ├── Contracts/           # Plugin interfaces
│   ├── Plugins/             # Built-in plugins
│   ├── Providers/           # AI providers
│   ├── Support/             # Language, Parsers, Prompts
│   └── Transformers/        # File format handlers
├── tests/                    # Pest tests
│   ├── Unit/                # Unit tests
│   └── Feature/             # Integration tests
├── config/                   # Configuration
├── resources/prompts/        # AI prompts
└── laravel-ai-translator-test/ # Integration test Laravel app
```

### Recent Architectural Changes
Based on recent commits (`ce2e56d`, `e7081cd`, `10834e3`):
- ✅ Migrated from legacy `AIProvider` to plugin-based architecture
- ✅ Separated concerns: TranslationBuilder → TranslationPipeline → Plugins
- ✅ Added reference language support for better translation quality
- ✅ Improved visual logging with color-coded output
- ✅ Enhanced token usage tracking

### Environment Variables
```env
# Required (choose one provider)
ANTHROPIC_API_KEY=sk-ant-...  # Recommended
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...

# Optional (set in config/ai-translator.php)
# - ai.provider: 'anthropic' | 'openai' | 'gemini'
# - ai.model: See README.md for available models
# - ai.max_tokens: 64000 (default for Claude Extended Thinking)
# - ai.use_extended_thinking: true (Claude 3.7+ only)
```