<?php

return [
    // Language file directory. 'lang' for Laravel.
    'source_directory' => 'lang',

    // Source language for translations. Default is 'en' for English.
    'source_locale' => 'en',

    // Disk for source checksum state.
    'state' => [
        'disk' => 'local',
    ],

    'ai' => [
        // Supported providers: OpenRouter (default), OpenAI, Anthropic, and Gemini.
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-opus-5',
        'api_key' => env('OPENROUTER_API_KEY'),

        // Additional options
        // 'retries' => 5,
        // 'max_tokens' => 128000,
        // 'reasoning' => ['effort' => 'high'],
        // 'use_extended_thinking' => false, // Claude 3.7 with the Anthropic direct provider only.
        // 'disable_stream' => true, // Disable streaming mode for better error messages

        // 'prompt_custom_system_file_path' => null, // Full path to your own custom prompt-system.txt - i.e. resource_path('prompt-system.txt')
        // 'prompt_custom_user_file_path' => null, // Full path to your own custom prompt-user.txt - i.e. resource_path('prompt-user.txt')
    ],

    // Consensus mode: when 2+ translators are configured, each translates the chunk
    // and the judge picks the best candidate per key. Leave 'translators' empty to
    // use the single 'ai' config above.
    'consensus' => [
        'translators' => [
            // ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'api_key' => env('ANTHROPIC_API_KEY'), 'temperature' => 0.3, 'use_extended_thinking' => false],
            // ['provider' => 'openai', 'model' => 'gpt-5', 'api_key' => env('OPENAI_API_KEY')],
        ],
        'judge' => [
            // 'provider' => 'openai', 'model' => 'gpt-5', 'api_key' => env('OPENAI_API_KEY'),
        ],
    ],

    // 'disable_plural' => true,
    // 'skip_locales' => [],
    // 'skip_files' => [],

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
            '- Tell the AI to use heavy sarcasm and exaggeration, often employing phrases like "Obviously," "Clearly," or "Wow, who would have thought?" to emphasize the obviousness of a point in a mocking way.',
            "- Instruct the AI to liberally use internet slang, memes, and pop culture references, particularly those popular on Reddit, such as \"Nice try, FBI,\" \"This guy reddits,\" or \"I also choose this guy's dead wife.\"",
            '- Direct the AI to be skeptical of everything, encouraging it to question sources, point out logical fallacies, and respond with "Source?" even for trivial claims.',
            '- Ask the AI to incorporate self-deprecating humor and cynicism, often making jokes about depression, social anxiety, or being forever alone, which are common themes in Reddit humor.',
            "- Instruct the AI to use puns, wordplay, and intentionally bad jokes, followed by expressions like \"\/s\" to denote sarcasm, or \"I'll see myself out\" after particularly groan-worthy puns, mimicking common Reddit comment patterns.",
        ],
    ],
];
