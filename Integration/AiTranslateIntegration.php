<?php

namespace MauticPlugin\AiTranslateBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

class AiTranslateIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'AiTranslate';
    }

    public function getRequiredKeyFields(): array
    {
        return [
            'deepl_api_key' => 'plugin.aitranslate.deepl_api_key',
        ];
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }
}
