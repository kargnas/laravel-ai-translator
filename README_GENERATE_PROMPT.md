# Generate Prompt Command

This command analyzes your application's frontend and language files to generate optimized translation prompts and build a glossary using Gemini 2.5 Pro.

## Usage

```bash
php artisan ai-translator:generate-prompt
```

## Options

- `--source=en` - Source locale to analyze (default: en)
- `--frontend-path=resources/js` - Path to frontend files (default: resources/js)
- `--lang-path=lang` - Path to language files (default: lang)
- `--output-path=storage/ai-translator` - Path to save generated prompts and glossary (default: storage/ai-translator)
- `--force` - Overwrite existing files

## Features

### 1. Frontend Analysis
- Scans Vue, React, and other JavaScript/TypeScript files
- Extracts component names and features
- Identifies translation keys used in the frontend
- Builds context about the application's UI structure

### 2. Language File Analysis
- Scans existing PHP and JSON language files
- Analyzes translation structure and categories
- Collects sample strings for context
- Counts total translation keys

### 3. Glossary Generation
- Analyzes existing translations across all locales
- Identifies frequently used terms
- Uses Gemini to generate glossary with:
  - Term definitions
  - Context and usage notes
  - Preferred translations
  - Disambiguation for ambiguous terms (e.g., "knocked" → "knocked down" vs "knocked out")

### 4. Prompt Generation
- **Global Prompt**: General translation guidelines based on application context
- **Language-Specific Prompts**: Custom rules for each target language based on existing translation patterns

## Output Files

The command generates the following files in the output directory:

1. `prompt-global.txt` - Global translation prompt
2. `prompt-{locale}.txt` - Language-specific prompts for each locale
3. `glossary.json` - Structured glossary in JSON format
4. `glossary.md` - Human-readable glossary in Markdown format

## Configuration

Make sure to set your Gemini API key in your `.env` file:

```
GEMINI_API_KEY=your-api-key-here
```

## Example Output

### Global Prompt
```
This is an e-commerce application focused on user engagement and sales.
Maintain a friendly, professional tone throughout. Key features include
product catalog, shopping cart, and user authentication...
```

### Language-Specific Prompt (Korean)
```
Use formal Korean (존댓말) for all customer-facing text. Apply natural
Korean sentence structure rather than direct translation. Maintain
consistency with Korean e-commerce conventions...
```

### Glossary Entry
```json
{
  "checkout": {
    "definition": "The process of completing a purchase",
    "context": "Used in shopping cart and payment flows",
    "preferred_translation": "결제하기",
    "notes": "Avoid using '체크아웃' which is too literal"
  }
}
```

## Integration with Translation Commands

The generated prompts and glossary can be used to improve translation quality:

1. Copy the generated prompts to your custom prompt files
2. Reference the glossary when reviewing translations
3. Use the language-specific rules to maintain consistency

## Requirements

- Laravel 9.0 or higher
- PHP 8.0 or higher
- Gemini API key
- Existing language files to analyze (for best results)