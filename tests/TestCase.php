<?php

namespace Kargnas\LaravelAiTranslator\Tests {

    use Dotenv\Dotenv;
    use Kargnas\LaravelAiTranslator\ServiceProvider;
    use Laravel\Ai\AiServiceProvider;
    use Orchestra\Testbench\TestCase as Orchestra;

    class TestCase extends Orchestra
    {
        protected function setUp(): void
        {
            parent::setUp();

            if (file_exists(__DIR__.'/../.env.testing')) {
                $dotenv = Dotenv::createImmutable(__DIR__.'/..', '.env.testing');
                $dotenv->load();
            }
        }

        protected function getPackageProviders($app): array
        {
            return [
                AiServiceProvider::class,
                ServiceProvider::class,
            ];
        }

        protected function getEnvironmentSetUp($app): void {}
    }

}

namespace {

    use Kargnas\LaravelAiTranslator\AI\AIProvider;
    use Kargnas\LaravelAiTranslator\Translation\ConsensusTranslator;
    use Laravel\Ai\Ai;
    use Laravel\Ai\Gateway\FakeTextGateway;
    use Laravel\Ai\Responses\Data\Meta;
    use Laravel\Ai\Responses\Data\Usage;
    use Laravel\Ai\Responses\StructuredTextResponse;
    use Laravel\Ai\Responses\TextResponse;

    function aiTextResponse(string $text, ?Usage $usage = null): TextResponse
    {
        return new TextResponse($text, $usage ?? new Usage, new Meta('fake', 'fake'));
    }

    function aiStructuredResponse(array $structured, ?Usage $usage = null): StructuredTextResponse
    {
        return new StructuredTextResponse($structured, json_encode($structured), $usage ?? new Usage, new Meta('fake', 'fake'));
    }

    function aiProviderAgentClass(): string
    {
        $provider = new AIProvider('test.php', ['greeting' => 'Hello'], 'en', 'ko');
        $method = new ReflectionMethod($provider, 'makeAgent');
        $method->setAccessible(true);

        return $method->invoke($provider, '')::class;
    }

    /** @param  array<string, array<int, string>>  $labelsByKey */
    function consensusJudgeAgentClass(array $labelsByKey = ['test.greeting' => ['A', 'B']]): string
    {
        $translator = new ConsensusTranslator(
            'test.php',
            ['greeting' => 'Hello'],
            'en',
            'ko',
            [],
            [],
            null,
            [],
            [],
        );
        $method = new ReflectionMethod($translator, 'makeJudgeAgent');
        $method->setAccessible(true);

        return $method->invoke($translator, $labelsByKey, 0.3, null)::class;
    }

    /** @param  array<int, TextResponse|StructuredTextResponse|string|array>  $responses */
    function fakeAiProvider(array $responses): FakeTextGateway
    {
        return Ai::fakeAgent(aiProviderAgentClass(), $responses);
    }

    /** @param  array<int, TextResponse|StructuredTextResponse|string|array>  $translatorResponses */
    /** @param  array<int, TextResponse|StructuredTextResponse|string|array>  $judgeResponses */
    function fakeConsensusAgents(array $translatorResponses, array $judgeResponses): FakeTextGateway
    {
        $gateway = Ai::fakeAgent(aiProviderAgentClass(), $translatorResponses);
        Ai::fakeAgent(consensusJudgeAgentClass(), $judgeResponses)->preventStrayPrompts();

        return $gateway;
    }

}
