<?php

namespace Kargnas\LaravelAiTranslator;


use Kargnas\LaravelAiTranslator\Console\TestTranslateCommand;
use Kargnas\LaravelAiTranslator\Console\TranslateCrowdin;
use Kargnas\LaravelAiTranslator\Console\TranslateStrings;
use Kargnas\LaravelAiTranslator\Console\TranslateStringsParallel;
use Kargnas\LaravelAiTranslator\Console\TranslateCrowdinParallel;
use Kargnas\LaravelAiTranslator\Console\TranslateFileCommand;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/ai-translator.php' => config_path('ai-translator.php'),
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai-translator.php',
            'ai-translator',
        );

        $this->commands([
            TranslateStrings::class,
            TranslateStringsParallel::class,
            TranslateCrowdinParallel::class,
            TranslateCrowdin::class,
            TestTranslateCommand::class,
            TranslateFileCommand::class,
        ]);
    }
}
