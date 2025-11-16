<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration;

class FeatureGateService
{
    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    public function isEnabled(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject(LeuchtfeuerTranslationsIntegration::NAME);

        // If the integration itself is missing, it's disabled; otherwise defer to its settings.
        if (false === $integration) {
            return false;
        }

        return $integration->getIntegrationSettings()->isPublished();
    }
}
