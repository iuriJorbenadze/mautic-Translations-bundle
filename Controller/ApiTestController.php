<?php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use MauticPlugin\AiTranslateBundle\Service\DeeplClientService;

class ApiTestController extends AbstractController
{
    /**
     * @Route("/s/plugin/ai-translate/test-api", name="ai_translate_test_api", methods={"POST"})
     */
    public function testApiAction(DeeplClientService $deepl)
    {
        $result = $deepl->translate('Hello', 'DE');
        if ($result['success']) {
            $message = sprintf('Success! "Hello" → "%s"', $result['translation']);
        } else {
            $message = sprintf('Error: %s', $result['error']);
        }
        return new JsonResponse(['message' => $message]);
    }
}
