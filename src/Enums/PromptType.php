<?php

namespace Kargnas\LaravelAiTranslator\Enums;

enum PromptType: string
{
    case SYSTEM = 'system';
    case USER = 'user';
}