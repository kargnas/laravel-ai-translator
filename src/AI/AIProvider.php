<?php

namespace Kargnas\LaravelAiTranslator\AI;


use AdrienBrault\Instructrice\InstructriceFactory;
use AdrienBrault\Instructrice\LLM\Provider\Anthropic;
use AdrienBrault\Instructrice\LLM\Provider\OpenAi;
use Kargnas\LaravelAiTranslator\Exceptions\VerifyFailedException;
use Kargnas\LaravelAiTranslator\Models\LocalizedString;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;

class AIProvider
{
    protected string $configProvider;
    protected string $configModel;
    protected int $configRetries = 3;

    public function __construct(
        public string $filename,
        public array  $strings,
        public string $sourceLanguage,
        public string $targetLanguage,
        public array  $additionalRules = [],
    ) {
        $this->configProvider = config('ai-translator.ai.provider');
        $this->configModel = config('ai-translator.ai.model');
        $this->configRetries = config('ai-translator.ai.retries');
    }

    protected function verify(array $list): void {
        $sourceKeys = collect($this->strings)->keys()->unique()->sort()->values()->toArray();
        $resultKeys = collect($list)->pluck('key')->unique()->sort()->values()->toArray();

        if ($sourceKeys !== $resultKeys) {
            throw new VerifyFailedException("Failed to translate the string. The keys are not matched. (Source: " . implode(', ', $sourceKeys) . ") (Result: " . implode(', ', $resultKeys) . ")");
        }

        foreach ($list as $item) {
            /** @var LocalizedString $item */
            if (empty($item->key)) {
                throw new VerifyFailedException("Failed to translate the string. The key is empty.");
            }
            if (!isset($item->translated)) {
                throw new VerifyFailedException("Failed to translate the string. The translation is not set for key: {$item->key}.");
            }
        }
    }

    protected function getSystemPrompt($replaces = []) {
        $systemPrompt = file_get_contents(__DIR__ . '/prompt-system.txt');

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'additionalRules' => sizeof($this->additionalRules) > 0 ? "\nSpecial rules for {$this->targetLanguage}:\n" . implode("\n", $this->additionalRules) : '',
        ]);

        foreach ($replaces as $key => $value) {
            $systemPrompt = str_replace("{{$key}}", $value, $systemPrompt);
        }

        return $systemPrompt;
    }

    protected function getUserPrompt($replaces = []) {
        $userPrompt = file_get_contents(__DIR__ . '/prompt-user.txt');

        $replaces = array_merge($replaces, [
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'filename' => $this->filename,
            'parentKey' => basename($this->filename, '.php'),
            'strings' => collect($this->strings)->map(fn($string, $key) => "  - `{$key}`: \"\"\"{$string}\"\"\"")->implode("\n"),
        ]);

        foreach ($replaces as $key => $value) {
            $userPrompt = str_replace("{{$key}}", $value, $userPrompt);
        }

        return $userPrompt;
    }

    /**
     * @return LocalizedString[]
     * @throws \Exception
     */
    public function getTranslatedObjects(): array {
        $model = match ($this->configProvider) {
            'anthropic' => match ($this->configModel) {
                'claude-3-haiku-20240307' => Anthropic::CLAUDE3_HAIKU,
                'claude-3-sonnet-20240229' => Anthropic::CLAUDE3_SONNET,
                'claude-3-opus-20240229' => Anthropic::CLAUDE3_OPUS,
                'claude-3-5-sonnet-20240620' => Anthropic::CLAUDE35_SONNET,
            },
            'openai' => match ($this->configModel) {
                'gpt-4o' => OpenAi::GPT_4O,
                'gpt-4o-mini' => OpenAi::GPT_4O_MINI,
                'gpt-4-turbo' => OpenAi::GPT_4T,
                'gpt-3.5-turbo' => OpenAi::GPT_35T,
            },
            default => null,
        };

        if (!$model) throw new \Exception("Provider {$this->configProvider} with model {$this->configModel} is not supported.");

        // Start
        $instructrice = InstructriceFactory::create(
            defaultLlm: $model,
            apiKeys: [ // Unless you inject keys here, api keys will be fetched from environment variables
                OpenAi::class => config('ai-translator.ai.api_key'),
                Anthropic::class => config('ai-translator.ai.api_key'),
            ],
        );

        $result = $instructrice->list(
            type: LocalizedString::class,
            context: $this->getUserPrompt(),
            prompt: $this->getSystemPrompt(),
        );

        // Fix Parent key issue
        $parentKey = basename($this->filename, '.php');
        foreach($result as $item) {
            if (str_starts_with($item->key, $parentKey)) {
                $item->key = str_replace($parentKey . '.', '', $item->key);
            }
        }

        return $result;
    }

    /**
     * @return LocalizedString[]
     * @throws VerifyFailedException
     */
    public function translate(): array {
        $tried = 1;
        do {
            try {
                \Log::debug("Translating " . sizeof($this->strings) . " strings to {$this->targetLanguage}, using {$this->configProvider} with {$this->configModel} model. Tried: {$tried}/{$this->configRetries}");

                $items = $this->getTranslatedObjects();
                $this->verify($items);
                return $items;
            } catch (NotNormalizableValueException $e) {
                \Log::error($e->getMessage());
            } catch (VerifyFailedException $e) {
                \Log::error($e->getMessage());
            }
        } while (++$tried <= $this->configRetries);

        throw new VerifyFailedException('Failed to translate the string after ' . ($tried + 1) . ' retries. If you run the command again, it will try to translate the strings starting from the failed one.');
    }
}
