<?php

return [
    'source_locale' => 'en',
    'source_directory' => 'lang',

    'ai' => [
//        'provider' => 'anthropic',
//        'model' => 'claude-3-5-sonnet-20240620', // Best result. Recommend for production.
//        'api_key' => env('ANTHROPIC_API_KEY'),
//        'retries' => 3,
//        'provider' => 'openai',
//        'model' => 'gpt-4o', // Balanced. Normal price, normal accuracy. Recommend for production.
//        'api_key' => env('OPENAI_API_KEY'),
//        'retries' => 3,
//        'provider' => 'anthropic',
//        'model' => 'claude-3-haiku-20240307', // Recommend to use for testing purpose. It's better than gpt-3.5
//        'api_key' => env('ANTHROPIC_API_KEY'),
//        'retries' => 5,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini', // Recommend to use for testing purpose. It sometimes doesn't translate.
        'api_key' => env('OPENAI_API_KEY'),
        'retries' => 5,
    ],

    // Example: 'en_us' (all capital, underscore)
    // You can add custom locale names here.
    'locale_names' => [
        'en_reddit' => 'English (Reddit)',
    ],

    'additional_rules' => [
        // You can add custom rules for languages here.
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
