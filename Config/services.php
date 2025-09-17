<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();
    // NOTE: do NOT call ->public() here – keep services private by default

    // Autoload everything under the bundle (except Mautic's default excludes)
    $services->load('MauticPlugin\\LeuchtfeuerTranslationsBundle\\', '../')
        ->exclude('../{' . implode(',', MauticCoreExtension::DEFAULT_EXCLUDES) . '}');

    // --- Integration registration (legacy plugins list/config) ---
    // Private class service + public alias for the legacy ID.
    $services->set(MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class)
        ->tag('mautic.integration', ['integration' => 'LeuchtfeuerTranslations']);

    $services->alias(
        'mautic.integration.leuchtfeuertranslations',
        MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class
    )->public();

    // DeepL client with IntegrationHelper + logger (kept private)
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
