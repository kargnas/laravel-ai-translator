# Laravel AI Translator

Automatically translate your Laravel language files using AI. 

I was struggling with translating my strings recently for my personal projects. I can use AI, but it is annoying and not convenient. So I just made this package to make it automation flow. When you add a new string in the default language (en), just run our translate command. It will translate into all languages.

Also, the detailed consideration is that this package will translate your strings more smartly. This will respect your variables, the tense of the expressions, and the length of the words.

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
2. Use AI to translate the contents of these files
3. Use English (`en`) as the source language for translations

## Configuration

If you want to customize the source language or other settings, you can publish the configuration file:

```bash
php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\LaravelAiTranslatorServiceProvider"
```

This will create a `config/ai-translator.php` file where you can modify the settings.

## Supported File Types

Currently, this package only supports PHP language files used by Laravel. JSON language files are not supported at this time.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
