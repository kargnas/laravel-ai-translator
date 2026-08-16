<?php

namespace Kargnas\LaravelAiTranslator\Console\Concerns;

use Kargnas\LaravelAiTranslator\Contracts\Translator;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Translation\TranslatorFactory;

trait WiresTranslatorOutput
{
    protected function wireTranslatorOutput(Translator $translator, int $totalCount): Translator
    {
        $translator->setOnThinking(function ($thinking) {
            echo $this->colors['gray'].$thinking.$this->colors['reset'];
        });

        $translator->setOnThinkingStart(function () {
            $this->line($this->colors['gray'].'    '.'🧠 AI Thinking...'.$this->colors['reset']);
        });

        $translator->setOnThinkingEnd(function () {
            $this->line($this->colors['gray'].'    '.'Thinking completed.'.$this->colors['reset']);
        });

        // Set callback for displaying translation progress
        $translator->setOnTranslated(function ($item, $status, $translatedItems) use ($totalCount) {
            if ($status === TranslationStatus::COMPLETED) {
                $completedCount = count($translatedItems);

                $this->line($this->colors['cyan'].'  ⟳ '.
                    $this->colors['reset'].$item->key.
                    $this->colors['gray'].' → '.
                    $this->colors['reset'].$item->translated.
                    $this->colors['gray']." ({$completedCount}/{$totalCount})".
                    $this->colors['reset']);
            }
        });

        // Set token usage callback
        $translator->setOnTokenUsage(function ($usage) {
            $isFinal = $usage['final'] ?? false;
            $inputTokens = $usage['input_tokens'] ?? 0;
            $outputTokens = $usage['output_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? 0;

            // Display real-time token usage
            $this->line($this->colors['gray'].'    Tokens: '.
                'Input='.$this->colors['green'].$inputTokens.$this->colors['gray'].', '.
                'Output='.$this->colors['green'].$outputTokens.$this->colors['gray'].', '.
                'Total='.$this->colors['purple'].$totalTokens.$this->colors['gray'].
                $this->colors['reset']);
        });

        // Set prompt logging callback
        if ($this->option('show-prompt')) {
            $translator->setOnPromptGenerated(function ($prompt, PromptType $type) {
                $typeText = match ($type) {
                    PromptType::SYSTEM => '🤖 System Prompt',
                    PromptType::USER => '👤 User Prompt',
                };

                echo "\n    {$typeText}:\n";
                echo $this->colors['gray'].'    '.str_replace("\n", $this->colors['reset']."\n    ".$this->colors['gray'], $prompt).$this->colors['reset']."\n";
            });
        }

        return $translator;
    }

    protected function announceConsensusMode(): void
    {
        if (! TranslatorFactory::usesConsensus()) {
            return;
        }

        $translators = config('ai-translator.consensus.translators', []);
        $judgeModel = config('ai-translator.consensus.judge.model', 'unknown');
        $this->line('Consensus mode: '.count($translators)." translators + judge {$judgeModel}");
    }
}
