<?php

namespace MauticPlugin\AiTranslateBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class AiTranslateIntegration extends AbstractIntegration
{
    public const NAME = 'AiTranslate'; // machine name

    public function getName(): string
    {
        return self::NAME;
    }

    public function getRequiredKeyFields(): array
    {
        return [
            'deepl_api_key' => 'plugin.aitranslate.deepl_api_key',
        ];
    }



    public function getDisplayName(): string
    {
        return 'AI Translate';
    }

    public function getIcon(): string
    {
        return 'plugins/AiTranslateBundle/Assets/img/icon.png';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        // Let it be enabled/disabled immediately
        return true;
    }
}
