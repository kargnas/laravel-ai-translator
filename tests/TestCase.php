<?php

namespace Kargnas\LaravelAiTranslator\Tests;

use Kargnas\LaravelAiTranslator\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        if (file_exists(__DIR__ . '/../.env.testing')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.testing');
            $dotenv->load();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {

    }
}
