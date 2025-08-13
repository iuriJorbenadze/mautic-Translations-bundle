<?php
declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()->autowire()->autoconfigure()->public();

    $services->load('MauticPlugin\\AiTranslateBundle\\', '../')
        ->exclude('../{'.implode(',', MauticCoreExtension::DEFAULT_EXCLUDES).'}');

    $services->set(MauticPlugin\AiTranslateBundle\Service\DeeplClientService::class)
        ->arg('$integrationHelper', service('mautic.helper.integration'))
        ->arg('$logger', service('monolog.logger.mautic')); // << inject logger
};
