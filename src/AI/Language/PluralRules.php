<?php

namespace Kargnas\LaravelAiTranslator\AI\Language;

use Kargnas\LaravelAiTranslator\Utility;

class PluralRules
{
    public static function getAdditionalRulesPlural(string $locale): array
    {
        $locale = strtolower(str_replace('-', '_', $locale));
        $pluralForms = Utility::getPluralForms($locale);

        if ($pluralForms === 1) {
            return [
                "- This language has only ONE form. Do not use plural forms.",
                "- Example: {1} 1 item|[2,*] :count items → {1} 1 item",
            ];
        }

        if ($pluralForms === 2) {
            return [
                "- This language has TWO forms: singular and plural.",
                "- Example: {1} 1 item|[2,*] :count items",
            ];
        }

        if ($pluralForms === 3) {
            return [
                "- This language has THREE forms: singular, dual, and plural.",
                "- Example: {1} 1 item|{2} 2 items|[3,*] :count items",
            ];
        }

        if ($pluralForms === 4) {
            return [
                "- This language has FOUR forms: singular, dual, trial, and plural.",
                "- Example: {1} 1 item|{2} 2 items|{3} 3 items|[4,*] :count items",
            ];
        }

        if ($pluralForms === 5) {
            return [
                "- This language has FIVE forms: singular, dual, trial, paucal, and plural.",
                "- Example: {1} 1 item|{2} 2 items|{3} 3 items|[4,10] :count items (paucal)|[11,*] :count items (plural)",
            ];
        }

        if ($pluralForms === 6) {
            return [
                "- This language has SIX forms: zero, singular, dual, trial, paucal, and plural.",
                "- Example: {0} no items|{1} 1 item|{2} 2 items|{3} 3 items|[4,10] :count items (paucal)|[11,*] :count items (plural)",
            ];
        }

        return [];
    }
}