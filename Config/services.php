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
    $services->load('MauticPlugin\\AiTranslateBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    // --- Integration (so it shows on /s/plugins) ---
    // Make sure Integration\AiTranslateIntegration::getName() returns 'AiTranslate'
    $services->set(MauticPlugin\AiTranslateBundle\Integration\AiTranslateIntegration::class)
        ->parent('mautic.integration.abstract')
        ->tag('mautic.integration', ['integration' => 'AiTranslate'])
        ->tag('mautic.config_integration');

    // DeepL client with IntegrationHelper + logger
    $services->set(MauticPlugin\AiTranslateBundle\Service\DeeplClientService::class)
        ->arg('$integrationHelper', service('mautic.helper.integration'))
        ->arg('$logger', service('monolog.logger.mautic'));

    // MJML translation orchestrator (uses DeeplClientService + logger)
    $services->set(MauticPlugin\AiTranslateBundle\Service\MjmlTranslateService::class)
        ->arg('$deepl', service(MauticPlugin\AiTranslateBundle\Service\DeeplClientService::class))
        ->arg('$logger', service('monolog.logger.mautic'));

    // MJML compiler
    $services->set(MauticPlugin\AiTranslateBundle\Service\MjmlCompileService::class)
        ->arg('$logger', service('monolog.logger.mautic'));
};
