<?php

namespace Kargnas\LaravelAiTranslator\Console\CrowdIn\Services;

use CrowdinApiClient\Crowdin;
use Illuminate\Console\Command;

class CrowdinApiService
{
    protected Crowdin $crowdin;

    protected Command $command;

    protected string $token;

    protected ?string $organization;

    public function __construct(Command $command, string $token, ?string $organization = null)
    {
        $this->command = $command;
        $this->token = $token;
        $this->organization = $organization;
    }

    /**
     * Initialize Crowdin client
     *
     * @throws \RuntimeException When client initialization fails
     */
    public function initialize(): void
    {
        try {
            $config = ['access_token' => $this->token];

            if (! empty($this->organization)) {
                $config['organization'] = $this->organization;
            }

            $this->crowdin = new Crowdin($config);

            // Verify connection by making a test API call
            $this->crowdin->project->list(['limit' => 1]);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to initialize Crowdin client: '.$e->getMessage();
            if (str_contains(strtolower($e->getMessage()), 'unauthorized')) {
                $errorMsg .= "\nPlease check your API token and organization settings.";
            }
            throw new \RuntimeException($errorMsg, 0, $e);
        }
    }

    /**
     * Get Crowdin client instance
     */
    public function getClient(): Crowdin
    {
        return $this->crowdin;
    }

    /**
     * Get organization name
     */
    public function getOrganization(): ?string
    {
        return $this->organization;
    }

    /**
     * Get API token
     */
    public function getToken(): string
    {
        return $this->token;
    }
}
