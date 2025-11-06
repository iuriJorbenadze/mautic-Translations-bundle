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
        if (!$integration) {
            return false;
        }

        $settings = $integration->getIntegrationSettings();
        if (!$settings) {
            return false;
        }

        return method_exists($settings, 'isPublished') ? (bool) $settings->isPublished() : false;
    }
}
