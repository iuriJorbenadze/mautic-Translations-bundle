<?php

return [
    'name'        => 'Translations by Leuchtfeuer',
    'description' => 'AI-based translation of Mautic content e.g. emails',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'version'     => '0.1.0',

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

    'assets' => [
        'js' => [
            'plugins/LeuchtfeuerTranslationsBundle/Assets/js/ai-translate.js',
            'plugins/LeuchtfeuerTranslationsBundle/Assets/js/config.js',
        ],
    ],

];
