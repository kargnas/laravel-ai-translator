<?php

return [
    // Language file directory. 'lang' for Laravel.
    'source_directory' => 'lang',

    'ai' => [
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest', // Best result. Recommend for production.
        'api_key' => env('ANTHROPIC_API_KEY'),

        // claude-3-haiku
        // 'provider' => 'anthropic',
        // 'model' => 'claude-3-haiku-20240307', // Recommend to use for testing purpose. It's better than gpt-3.5
        // 'api_key' => env('ANTHROPIC_API_KEY'),

        // gpt-4o
        // 'provider' => 'openai',
        // 'model' => 'gpt-4o', // Balanced. Normal price, normal accuracy. Recommend for production.
        // 'api_key' => env('OPENAI_API_KEY'),

        // gpt-4o-mini
        // 'provider' => 'openai',
        // 'model' => 'gpt-4o-mini', // Recommend to use for testing purpose. It sometimes doesn't translate.
        // 'api_key' => env('OPENAI_API_KEY'),

        // 추가 옵션 기능 설정
        // 'retries' => 5,
        // 'max_tokens' => 4096,
        // 'use_extended_thinking' => false, // Extended Thinking 기능 사용 여부 (claude-3-7-sonnet-latest 모델만 지원)
        // 'disable_stream' => true, // Disable streaming mode for better error messages

        'prompt_custom_system_file_path' => null, // Full path to your own custom prompt-system.txt - i.e. resource_path('prompt-system.txt')
        'prompt_custom_user_file_path' => null, // Full path to your own custom prompt-user.txt - i.e. resource_path('prompt-user.txt')
    ],

    // 'disable_plural' => true,
    // 'skip_locales' => [],

    // If set to true, translations will be saved as flat arrays using dot notation keys. If set to false, translations will be saved as multi-dimensional arrays.
    'dot_notation' => true,

    // You can add additional custom locale names here.
    // Example: 'en_us', 'en-us', 'en_US', 'en-US'
    'locale_names' => [
        'en_reddit' => 'English (Reddit)',
    ],

    // Fallback
    //   - 'default' is fallback rules for all languages which don't have specific rules. If you added custom rules for a language, 'default' will not be used.
    // Combining the language and regional rules:
    //   - In the situation that you defined rules for 'en' and 'en_us'
    //   - If you translate into 'en_us', both 'en' and 'en_us' will be applied.
    //   - If you translate into 'en', only 'en' will be applied.
    //   - If you translate into 'en_uk', only 'en' will be applied.
    'additional_rules' => [
        'default' => [
            "- Use a friendly and intuitive tone of voice, like the service tone of voice of 'Discord'.",
        ],
        'ko' => [
            "- 한국의 인터넷 서비스 '토스'의 서비스 말투 처럼, 유저에게 친근하고 직관적인 말투로 설명하고 존댓말로 설명하세요.",
        ],
        'en_reddit' => [
            "- Use a sarcastic and informal tone of voice, like the users in 'Reddit'.",
            "- Tell the AI to use heavy sarcasm and exaggeration, often employing phrases like \"Obviously,\" \"Clearly,\" or \"Wow, who would have thought?\" to emphasize the obviousness of a point in a mocking way.",
            "- Instruct the AI to liberally use internet slang, memes, and pop culture references, particularly those popular on Reddit, such as \"Nice try, FBI,\" \"This guy reddits,\" or \"I also choose this guy's dead wife.\"",
            "- Direct the AI to be skeptical of everything, encouraging it to question sources, point out logical fallacies, and respond with \"Source?\" even for trivial claims.",
            "- Ask the AI to incorporate self-deprecating humor and cynicism, often making jokes about depression, social anxiety, or being forever alone, which are common themes in Reddit humor.",
            "- Instruct the AI to use puns, wordplay, and intentionally bad jokes, followed by expressions like \"\/s\" to denote sarcasm, or \"I'll see myself out\" after particularly groan-worthy puns, mimicking common Reddit comment patterns.",
        ],
    ],
];
