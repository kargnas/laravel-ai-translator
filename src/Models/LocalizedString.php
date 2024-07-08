<?php

namespace Kargnas\LaravelAiTranslator\Models;


use AdrienBrault\Instructrice\Attribute\Prompt;

class LocalizedString
{
    #[Prompt('The key of the string to be translated. Should be kept as it.')]
    public string $key = '';

    #[Prompt('Translated text into the target language from the source language.')]
    public string $translated;
}
