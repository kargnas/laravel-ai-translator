<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Language\LanguageConfig;
use Kargnas\LaravelAiTranslator\AI\Printer\TokenUsagePrinter;
use Kargnas\LaravelAiTranslator\Enums\PromptType;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;

/**
 * Translate a JSON language file with interactive options similar to TranslateStrings.
 */
class TranslateJsonFileCommand extends Command
{
    protected $signature = 'ai-translator:translate-json
                            {file : Path to the JSON file to translate}
                            {--s|source= : Source language to translate from}
                            {--l|locale=* : Target locales to translate. If not provided, will ask interactively}
                            {--r|reference= : Reference locales for translation guidance}
                            {--show-prompt : Show the whole AI prompts during translation}
                            {--non-interactive : Run in non-interactive mode, using default or provided values}
                            {--debug : Enable debug mode}';

    protected $description = 'Translate a JSON language file using AI';

    /** @var int */
    private $thinkingBlockCount = 0;

    /** @var array<string,string> */
    protected $colors = [
        'gray' => "\033[38;5;245m",
        'blue' => "\033[38;5;33m",
        'green' => "\033[38;5;40m",
        'yellow' => "\033[38;5;220m",
        'purple' => "\033[38;5;141m",
        'red' => "\033[38;5;196m",
        'reset' => "\033[0m",
        'blue_bg' => "\033[48;5;24m",
        'white' => "\033[38;5;255m",
        'bold' => "\033[1m",
        'yellow_bg' => "\033[48;5;220m",
        'black' => "\033[38;5;16m",
        'line_clear' => "\033[2K\r",
    ];

    /** @var array<string,int> */
    protected $tokenUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
    ];

    protected array $referenceLocales = [];
    protected string $sourceLocale;

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $nonInteractive = $this->option('non-interactive');
        $debug = $this->option('debug');

        if ($debug) {
            config(['app.debug' => true]);
            config(['ai-translator.debug' => true]);
        }

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $content = file_get_contents($filePath);
        $strings = json_decode($content, true);
        if (!is_array($strings)) {
            $this->error('File must be a valid JSON object with key-value pairs');
            return 1;
        }

        $this->displayHeader();

        // Determine source locale
        $defaultSource = pathinfo($filePath, PATHINFO_FILENAME);
        if ($nonInteractive || $this->option('source')) {
            $this->sourceLocale = $this->option('source') ?? $defaultSource;
            $this->info($this->colors['green'] . "✓ Selected source locale: " .
                $this->colors['reset'] . $this->colors['bold'] . $this->sourceLocale . $this->colors['reset']);
        } else {
            $this->sourceLocale = $this->choiceLanguages(
                $this->colors['yellow'] . 'Choose a source locale' . $this->colors['reset'],
                false,
                $defaultSource
            );
        }

        // Determine target locales
        if ($nonInteractive || $this->option('locale')) {
            $locales = (array) ($this->option('locale') ?: []);
            if (!empty($locales)) {
                $this->info($this->colors['green'] . '✓ Selected target locales: ' .
                    $this->colors['reset'] . $this->colors['bold'] . implode(', ', $locales) . $this->colors['reset']);
            }
        } else {
            $locales = (array) $this->choiceLanguages(
                $this->colors['yellow'] . 'Choose target locales' . $this->colors['reset'],
                true
            );
        }

        if (empty($locales)) {
            $this->error('No target locales specified.');
            return 1;
        }

        // Determine reference locales
        if ($nonInteractive) {
            $this->referenceLocales = $this->option('reference')
                ? explode(',', (string) $this->option('reference'))
                : [];
            if (!empty($this->referenceLocales)) {
                $this->info($this->colors['green'] . '✓ Selected reference locales: ' .
                    $this->colors['reset'] . $this->colors['bold'] . implode(', ', $this->referenceLocales) . $this->colors['reset']);
            }
        } elseif ($this->option('reference')) {
            $this->referenceLocales = explode(',', (string) $this->option('reference'));
            $this->info($this->colors['green'] . '✓ Selected reference locales: ' .
                $this->colors['reset'] . $this->colors['bold'] . implode(', ', $this->referenceLocales) . $this->colors['reset']);
        } elseif ($this->ask($this->colors['yellow'] . 'Do you want to add reference locales? (y/n)' . $this->colors['reset'], 'n') === 'y') {
            $this->referenceLocales = (array) $this->choiceLanguages(
                $this->colors['yellow'] . 'Choose reference locales' . $this->colors['reset'],
                true
            );
        }

        foreach ($locales as $targetLocale) {
            if ($targetLocale === $this->sourceLocale) {
                $this->warn('Skipping locale ' . $targetLocale . '.');
                continue;
            }

            $targetLanguageName = LanguageConfig::getLanguageName($targetLocale) ?? $targetLocale;
            $this->line(str_repeat('─', 80));
            $this->line($this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] .
                " Starting {$targetLanguageName} ({$targetLocale}) " . $this->colors['reset']);

            $provider = $this->setupProvider($filePath, $strings, $targetLocale);

            try {
                $translatedItems = $provider->translate();
            } catch (\Exception $e) {
                $this->error('Translation failed: ' . $e->getMessage());
                continue;
            }

            $results = [];
            foreach ($translatedItems as $item) {
                $results[$item->key] = $item->translated;
            }

            $basename = basename($filePath, '.json');
            $outputFileName = $basename === $this->sourceLocale
                ? $targetLocale
                : "{$basename}-{$targetLocale}";
            $outputFilePath = dirname($filePath) . "/{$outputFileName}.json";

            file_put_contents($outputFilePath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->info($this->colors['green'] . '✓ Translation completed. Output written to: ' .
                $this->colors['reset'] . $outputFilePath);
        }

        // Display total token usage
        $printer = new TokenUsagePrinter(config('ai-translator.ai.model'));
        $printer->printTokenUsageSummary($this, $this->tokenUsage);
        $printer->printCostEstimation($this, $this->tokenUsage);

        return 0;
    }

    /**
     * Create and configure AIProvider.
     *
     * @param string $filePath
     * @param array $strings
     * @param string $targetLocale
     */
    protected function setupProvider(string $filePath, array $strings, string $targetLocale): AIProvider
    {
        // Load reference strings
        $references = [];
        foreach ($this->referenceLocales as $refLocale) {
            $refFile = dirname($filePath) . "/{$refLocale}.json";
            if (file_exists($refFile)) {
                $content = json_decode(file_get_contents($refFile), true);
                if (is_array($content)) {
                    $references[$refLocale] = $content;
                }
            }
        }

        $provider = new AIProvider(
            basename($filePath),
            $strings,
            $this->sourceLocale,
            $targetLocale,
            $references,
            [],
            []
        );

        $provider
            ->setOnTranslated(function (LocalizedString $item, string $status, array $translated) {
                if ($status === TranslationStatus::COMPLETED) {
                    $this->line($this->colors['cyan'] . '  ⟳ ' .
                        $this->colors['reset'] . $item->key .
                        $this->colors['gray'] . ' → ' .
                        $this->colors['reset'] . $item->translated .
                        $this->colors['reset']);
                }
            })
            ->setOnThinking(function ($delta) {
                echo $this->colors['gray'] . $delta . $this->colors['reset'];
            })
            ->setOnThinkingStart(function () {
                $this->thinkingBlockCount++;
                $this->line('');
                $this->line($this->colors['purple'] . '🧠 AI Thinking Block #' . $this->thinkingBlockCount . ' Started...' . $this->colors['reset']);
            })
            ->setOnThinkingEnd(function ($content = null) {
                $this->line('');
                $this->line($this->colors['purple'] . '✓ Thinking completed (' . strlen((string) $content) . ' chars)' . $this->colors['reset']);
                $this->line('');
            })
            ->setOnTokenUsage(function (array $usage) {
                $this->tokenUsage['input_tokens'] += $usage['input_tokens'] ?? 0;
                $this->tokenUsage['output_tokens'] += $usage['output_tokens'] ?? 0;
                $this->tokenUsage['total_tokens'] =
                    $this->tokenUsage['input_tokens'] + $this->tokenUsage['output_tokens'];
            });

        if ($this->option('show-prompt')) {
            $provider->setOnPromptGenerated(function ($prompt, PromptType $type) {
                $typeText = match ($type) {
                    PromptType::SYSTEM => '🤖 System Prompt',
                    PromptType::USER => '👤 User Prompt',
                };

                print("\n    {$typeText}:\n");
                print($this->colors['gray'] . '    ' . str_replace("\n", $this->colors['reset'] . "\n    " . $this->colors['gray'], $prompt) . $this->colors['reset'] . "\n");
            });
        }

        return $provider;
    }

    /**
     * Display fancy header.
     */
    protected function displayHeader(): void
    {
        $this->line("\n" . $this->colors['blue_bg'] . $this->colors['white'] . $this->colors['bold'] . ' Laravel AI Translator ' . $this->colors['reset']);
        $this->line($this->colors['gray'] . 'Translating JSON language file using AI technology' . $this->colors['reset']);
        $this->line(str_repeat('─', 80) . "\n");
    }

    /**
     * List available locales based on existing JSON files.
     */
    protected function getExistingLocales(): array
    {
        $root = dirname($this->argument('file')); // assume json files live here
        $files = glob("{$root}/*.json");
        return collect($files)
            ->map(fn($f) => basename($f, '.json'))
            ->values()
            ->toArray();
    }

    /**
     * Helper to choose locales interactively.
     */
    protected function choiceLanguages(string $question, bool $multiple, ?string $default = null): array|string
    {
        $locales = $this->getExistingLocales();
        return $this->choice($question, $locales, $default, 3, $multiple);
    }
}
