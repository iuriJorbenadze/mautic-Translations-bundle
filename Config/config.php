<?php


return [
    'name'        => 'Translations by Leuchtfeuer',
    'description' => 'AI-based translation of Mautic content e.g. emails',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'version'     => '0.1.0',

    'services' => [
        'integrations' => [
            'mautic.integration.leuchtfeuertranslations' => [
                'class'     => MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
    ],

    'routes' => [
        'main' => [
            // Clone & translate action
            'plugin_ai_translate_action_translate' => [
                'path'       => '/plugin/ai-translate/email/{objectId}/translate',
                'controller' => 'MauticPlugin\LeuchtfeuerTranslationsBundle\Controller\EmailActionController::translateAction',
            ],

            // Secure (non-public) test API endpoint
            'plugin_ai_translate_test_api' => [
                'path'       => '/plugin/ai-translate/test-api',
                'controller' => 'MauticPlugin\LeuchtfeuerTranslationsBundle\Controller\ApiTestController::testApiAction',
            ],
        ],
        // NOTE: removed from 'public' group to avoid unauthenticated/CSRF-less access
    ],
];
