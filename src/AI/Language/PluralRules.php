<?php

namespace Kargnas\LaravelAiTranslator\AI\Language;

class PluralRules
{
    public static function getAdditionalRulesPlural(Language $language): array
    {
        $rules = [];

        // Skip plural rules if language doesn't have plural forms
        if (! $language->hasPlural()) {
            return $rules;
        }

        // General plural rules based on plural forms
        $rules[] = '## Plural Rules';
        $rules = array_merge($rules, match ($language->pluralForms) {
            1 => [
                '- This language has only ONE form. Do not use plural forms.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                '- Example: {1} 1 item|[2,*] :count items → {1} 1 item',
            ],
            2 => [
                '- This language has TWO forms: singular and plural.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                '- For plurals, always use the format: {1} singular|[2,*] plural.',
                '- Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.',
                '- Example: {1} 1 item|[2,*] :count items',
            ],
            3 => [
                '- This language has THREE forms: singular, few, and many.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "- Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                '- Always use: singular|few|many',
                '- Apply this to ALL nouns, regular or irregular',
                '- Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.',
                '- Example: {1} 1 item|{2} 2 items|[3,*] :count items',
            ],
            4 => [
                '- This language has FOUR forms: singular, dual, few, and many.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "- Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                '- Always use: singular|dual|few|many',
                '- Apply this to ALL nouns, regardless of their original plural formation',
                '- Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.',
                '- Example: {1} 1 item|{2} 2 items|{3} 3 items|[4,*] :count items',
            ],
            5 => [
                '- This language has FIVE forms: singular, dual, trial, paucal, and plural.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                '- Always expand all plural forms into multiple forms, regardless of the source format or word type.',
                '- Always use: singular|dual|trial|paucal|plural',
                '- Apply this to ALL nouns, regardless of their original plural formation',
                '- Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.',
                '- Example: {1} 1 item|{2} 2 items|{3} 3 items|[4,10] :count items (paucal)|[11,*] :count items (plural)',
            ],
            6 => [
                '- This language has SIX forms: zero, one, two, few, many, and other.',
                "- Never follow these plural rules if the original does not have multiple forms without '|'. (e.g. `:count items` -> `:count items`)",
                "- Always expand all plural forms into multiple forms, regardless of the source format or word type. Don't specify a range.",
                '- Always use: zero|one|two|few|many|other',
                '- Apply this to ALL nouns, regardless of their original plural formation',
                '- Research and apply the correct plural forms for each specific noun in target language and preserve case of letters for each.',
                '- Example: {0} no items|{1} 1 item|{2} 2 items|{3} 3 items|[4,10] :count items (paucal)|[11,*] :count items (plural)',
            ],
            default => []
        });

        // Language specific rules after plural rules
        if ($language->is('zh')) {
            $rules[] = '## Chinese Specific Rules';
            $rules[] = "- CRITICAL: For ALL Chinese translations, ALWAYS use exactly THREE parts if there is '|': 一 + measure word + noun|两 + measure word + noun|:count + measure word + noun. This is MANDATORY, even if the original only has two parts. NO SPACES in Chinese text except right after numbers in curly braces and square brackets.";
            $rules[] = '- Example structure (DO NOT COPY WORDS, only structure): {1} 一X词Y|{2} 两X词Y|[3,*] :countX词Y. Replace X with correct measure word, Y with noun. Ensure NO SPACE between :count and the measure word. If any incorrect spaces are found, remove them and flag for review.';
        }

        if ($language->is('ko')) {
            $rules[] = '## Korean Specific Rules';
            $rules[] = "- Don't add a space between the number and the measure word with variables. Example: {1} 한 개|{2} 두 개|[3,*] :count개";
        }

        return $rules;
    }
}
