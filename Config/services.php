<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();
    // Keep services private by default (no ->public())

    // Autoload everything under the bundle (except Mautic's default excludes)
    $services->load('MauticPlugin\\LeuchtfeuerTranslationsBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // --- Integration registration (legacy plugins list/config) ---
    // Private class service + public alias for the legacy ID.
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class)
        ->tag('mautic.integration', ['integration' => 'LeuchtfeuerTranslations']);

    $services->alias(
        'mautic.integration.leuchtfeuertranslations',
        MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class
    )->public();

    // Rely on autowiring for constructor deps (IntegrationHelper, LoggerInterface, etc.)
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService::class);
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlTranslateService::class);
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlCompileService::class);
};
