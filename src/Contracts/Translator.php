<?php

namespace Kargnas\LaravelAiTranslator\Contracts;

use Kargnas\LaravelAiTranslator\Models\LocalizedString;

interface Translator
{
    /** @return array<int, LocalizedString> */
    public function translate(): array;

    public function setOnTranslated(?callable $callback): self;

    public function setOnThinking(?callable $callback): self;

    public function setOnProgress(?callable $callback): self;

    public function setOnThinkingStart(?callable $callback): self;

    public function setOnThinkingEnd(?callable $callback): self;

    public function setOnTokenUsage(?callable $callback): self;

    public function setOnPromptGenerated(?callable $callback): self;

    /** @return array<string, int> */
    public function getTokenUsage(): array;

    public function getModel(): string;
}
