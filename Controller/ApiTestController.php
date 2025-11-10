<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Controller;

use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiTestController extends AbstractController
{
    /**
     * POST /s/plugin/ai-translate/test-api (registered via config.php under "main").
     */
    public function testApiAction(DeeplClientService $deepl): JsonResponse
    {
        /** @var array{success:bool, translation?:string, error?:string, host:string|null, status:int|null} $result */
        $result = $deepl->translate('Hello', 'DE');

        $isSuccess = isset($result['success']) && true === $result['success'];

        if (true === $isSuccess) {
            $translation = isset($result['translation']) ? (string) $result['translation'] : '';
            $message     = sprintf('Success! "Hello" → "%s"', $translation);
        } else {
            $error   = isset($result['error']) ? (string) $result['error'] : 'Unknown error';
            $message = sprintf('Error: %s', $error);
        }

        return new JsonResponse(['message' => $message]);
    }
}
