<?php
// plugins/AiTranslateBundle/Config/config.php

return [
    'name'        => 'AI Translate',
    'description' => 'Plugin for AI-powered email translation.',
    'author'      => 'Your Name',
    'version'     => '0.1.0',

    'services' => [
        'integrations' => [
            'mautic.integration.aitranslate' => [
                'class' => \MauticPlugin\AiTranslateBundle\Integration\AiTranslateIntegration::class,
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
            // This route will handle the main translation action
            'plugin_ai_translate_action_translate' => [
                'path'       => '/plugin/ai-translate/email/{objectId}/translate',
                'controller' => 'MauticPlugin\AiTranslateBundle\Controller\EmailActionController::translateAction',
            ],
        ],
        'public' => [
            'plugin_ai_translate_test_api' => [
                'path'       => '/plugin/ai-translate/test-api',
                'controller' => 'MauticPlugin\AiTranslateBundle\Controller\ApiTestController::testApiAction',
            ],
        ]
    ],
];
