<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Autoload everything under the bundle (except Mautic's default excludes)
    $services->load('MauticPlugin\\LeuchtfeuerTranslationsBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // --- Integration (so it shows on /s/plugins) ---
    // Make sure Integration\LeuchtfeuerTranslationsIntegration::getName() returns 'LeuchtfeuerTranslations'
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class)
        ->parent('mautic.integration.abstract')
        ->tag('mautic.integration', ['integration' => 'LeuchtfeuerTranslations'])
        ->tag('mautic.config_integration');

    // DeepL client with IntegrationHelper + logger
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService::class)
        ->arg('$integrationHelper', service('mautic.helper.integration'))
        ->arg('$logger', service('monolog.logger.mautic'));

    // MJML translation orchestrator (uses DeeplClientService + logger)
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlTranslateService::class)
        ->arg('$deepl', service(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService::class))
        ->arg('$logger', service('monolog.logger.mautic'));

    // MJML compiler
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlCompileService::class)
        ->arg('$logger', service('monolog.logger.mautic'));
};
