<?php

namespace Kargnas\LaravelAiTranslator\Plugins;

use Kargnas\LaravelAiTranslator\Contracts\ProviderPlugin;
use Kargnas\LaravelAiTranslator\Core\TranslationContext;
use Kargnas\LaravelAiTranslator\Core\TranslationPipeline;
use Kargnas\LaravelAiTranslator\Core\PipelineStages;

abstract class AbstractProviderPlugin extends AbstractTranslationPlugin implements ProviderPlugin
{
    /**
     * {@inheritDoc}
     */
    abstract public function provides(): array;

    /**
     * {@inheritDoc}
     */
    public function when(): array
    {
        return [PipelineStages::TRANSLATION, PipelineStages::CONSENSUS]; // Default stages
    }

    /**
     * {@inheritDoc}
     */
    abstract public function execute(TranslationContext $context): mixed;

    /**
     * {@inheritDoc}
     */
    public function boot(TranslationPipeline $pipeline): void
    {
        // Register each service this provider offers
        foreach ($this->provides() as $service) {
            $pipeline->registerService($service, [$this, 'execute']);
        }

        // Register for specific stages
        foreach ($this->when() as $stage) {
            $pipeline->registerStage($stage, function (TranslationContext $context) {
                if ($this->shouldProvide($context)) {
                    return $this->execute($context);
                }
                return null;
            }, $this->getPriority());
        }
    }

    /**
     * Check if this provider should provide services for the context.
     */
    protected function shouldProvide(TranslationContext $context): bool
    {
        // Check if any of the services this provider offers are requested
        $requestedServices = $context->request->getOption('services', []);
        $providedServices = $this->provides();

        if (!empty($requestedServices)) {
            return !empty(array_intersect($requestedServices, $providedServices));
        }

        // Check if provider is enabled for the current stage
        return in_array($context->currentStage, $this->when(), true);
    }

    /**
     * Helper method to check if a service is requested.
     */
    protected function isServiceRequested(TranslationContext $context, string $service): bool
    {
        $requestedServices = $context->request->getOption('services', []);
        return in_array($service, $requestedServices, true);
    }

    /**
     * Helper method to get service configuration.
     */
    protected function getServiceConfig(TranslationContext $context, string $service): array
    {
        $serviceConfigs = $context->request->getOption('service_configs', []);
        return $serviceConfigs[$service] ?? [];
    }
}