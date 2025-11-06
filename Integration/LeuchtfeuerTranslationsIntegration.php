<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class LeuchtfeuerTranslationsIntegration extends AbstractIntegration
{
    public const NAME = 'LeuchtfeuerTranslations'; // machine name

    public function getName(): string
    {
        return self::NAME;
    }

    public function getRequiredKeyFields(): array
    {
        return [
            'deepl_api_key' => 'plugin.leuchtfeuertranslations.deepl_api_key',
        ];
    }

    public function getDisplayName(): string
    {
        return 'Translations by Leuchtfeuer';
    }

    public function getIcon(): string
    {
        return 'plugins/LeuchtfeuerTranslationsBundle/Assets/img/icon.png';
    }

    public function getAuthenticationType(): string
    {
        return 'keys';
    }

    public function isConfigured(): bool
    {
        // Let it be enabled/disabled immediately
        return true;
    }
}
