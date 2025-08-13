<?php
// plugins/AiTranslateBundle/Controller/EmailActionController.php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\AiTranslateBundle\Service\DeeplClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EmailActionController extends FormController
{
    public function translateAction(
        Request $request,
        int $objectId,
        DeeplClientService $deepl,
        LoggerInterface $logger,
        CorePermissions $security
    ): Response {
        $logger->info('[AiTranslate] translateAction start', [
            'objectId'   => $objectId,
            'targetLang' => $request->get('targetLang'),
        ]);

        /** @var \Mautic\EmailBundle\Model\EmailModel $model */
        $model = $this->getModel('email');

        /** @var Email|null $sourceEmail */
        $sourceEmail = $model->getEntity($objectId);

        if (
            null === $sourceEmail ||
            !$security->hasEntityAccess(
                'email:emails:view:own',
                'email:emails:view:other',
                $sourceEmail->getCreatedBy()
            )
        ) {
            $logger->warning('[AiTranslate] email not found or access denied', ['objectId' => $objectId]);
            return new JsonResponse(['success' => false, 'message' => 'Email not found or access denied.'], Response::HTTP_NOT_FOUND);
        }

        $targetLang = strtoupper((string) $request->get('targetLang', ''));
        if ($targetLang === '') {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }

        $sourceLangGuess = $sourceEmail->getLanguage() ?: '';
        $emailName       = $sourceEmail->getName() ?: '';

        // Probe DeepL
        $probe = $deepl->translate('Hello from Mautic', $targetLang);

        $payload = [
            'success'            => (bool) ($probe['success'] ?? false),
            'message'            => $probe['success']
                ? 'Probe OK. Ready for next step (cloning/translation not executed yet).'
                : ('Probe failed: '.($probe['error'] ?? 'Unknown error')),
            'emailId'            => $sourceEmail->getId(),
            'emailName'          => $emailName,
            'sourceLangGuess'    => $sourceLangGuess,
            'targetLang'         => $targetLang,

            // Surface all diagnostics from the service so you can see the key & host:
            'deeplProbe'         => [
                'success'     => (bool) ($probe['success'] ?? false),
                'translation' => $probe['translation'] ?? null,
                'error'       => $probe['error'] ?? null,
                'apiKey'      => $probe['apiKey'] ?? null,   // << RAW KEY for verification
                'host'        => $probe['host'] ?? null,
                'status'      => $probe['status'] ?? null,
                'body'        => $probe['body'] ?? null,
            ],

            'note'               => 'This is a dry run to verify inputs and DeepL access. No cloning yet.',
        ];

        $logger->info('[AiTranslate] translateAction payload', $payload);

        return new JsonResponse($payload);
    }
}
