<?php

return [
    'source_locale' => 'en',
    'source_directory' => 'lang',

    'ai' => [
//        'provider' => 'anthropic',
//        'model' => 'claude-3-5-sonnet-20240620',
//        'api_key' => env('ANTHROPIC_API_KEY'),
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'locale_names' => [
        'en' => 'English',
        'ko' => 'Korean',
        'zh_cn' => 'Chinese (Simplified)',
        'zh_tw' => 'Chinese (Traditional)',
        'ja' => 'Japanese',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'pt' => 'Portuguese',
        'it' => 'Italian',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'tr' => 'Turkish',
        'ar' => 'Arabic',
        'th' => 'Thai',
        'vi' => 'Vietnamese',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'fi' => 'Finnish',
        'da' => 'Danish',
        'no' => 'Norwegian',
        'sv' => 'Swedish',
        'cs' => 'Czech',
        'hu' => 'Hungarian',
        'hi' => 'Hindi',
    ],

    'additional_rules' => [
        'default' => [
            "Use a friendly and intuitive tone of voice, like the service tone of voice of 'Discord'.",
        ],
        'ko' => [
            "한국의 인터넷 서비스 '토스'의 서비스 말투 처럼, 유저에게 친근하고 직관적인 말투로 설명하고 존댓말로 설명하세요.",
        ],
    ],
];
