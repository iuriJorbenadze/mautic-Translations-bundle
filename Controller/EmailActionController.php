<?php
// plugins/AiTranslateBundle/Controller/EmailActionController.php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\AiTranslateBundle\Service\DeeplClientService;
use MauticPlugin\AiTranslateBundle\Service\MjmlTranslateService;
use MauticPlugin\AiTranslateBundle\Service\MjmlCompileService;
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
        MjmlTranslateService $mjmlService,
        MjmlCompileService $mjmlCompiler,
        LoggerInterface $logger,
        CorePermissions $security,
        Connection $conn
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

        // DeepL wants UPPER; Mautic Email.language wants lower
        $targetLangApi = strtoupper((string) $request->get('targetLang', ''));
        if ($targetLangApi === '') {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }
        $targetLangIso = strtolower($targetLangApi);

        $sourceLangGuess = strtolower($sourceEmail->getLanguage() ?: '');
        $emailName       = $sourceEmail->getName() ?: '';
        $isCodeMode      = $sourceEmail->getTemplate() === 'mautic_code_mode';

        // 1) Quick probe
        $probe = $deepl->translate('Hello from Mautic', $targetLangApi);
        if (!($probe['success'] ?? false)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Probe failed: '.($probe['error'] ?? 'Unknown error'),
                'deeplProbe' => $probe,
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2) Read MJML from builder table
        $mjml = '';
        try {
            $row  = $conn->fetchAssociative('SELECT custom_mjml FROM bundle_grapesjsbuilder WHERE email_id = :id LIMIT 1', ['id' => $sourceEmail->getId()]);
            $mjml = isset($row['custom_mjml']) ? (string) $row['custom_mjml'] : '';
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Failed to fetch MJML from bundle_grapesjsbuilder', [
                'emailId' => $sourceEmail->getId(),
                'ex'      => $e->getMessage(),
            ]);
        }

        // 3) Clone the entity (pattern similar to AB test) + fix language casing and safe HTML
        try {
            $emailType = $sourceEmail->getEmailType();

            /** @var Email $clone */
            $clone = clone $sourceEmail;

            // Restore fields / set our adjustments
            $clone->setIsPublished(false);
            $clone->setEmailType($emailType);
            $clone->setVariantParent(null);

            // Name + target language suffix
            $clone->setName(rtrim(($emailName ?: 'Email').' ['.$targetLangApi.']'));

            // IMPORTANT: Mautic expects lowercase language code
            $clone->setLanguage($targetLangIso ?: $sourceLangGuess);

            // Ensure HTML is not null (prevents PlainTextHelper error on /view)
            $sourceHtml = $sourceEmail->getCustomHtml();
            if ($sourceHtml === null) {
                $sourceHtml = '<!doctype html><html><body></body></html>';
            }
            $clone->setCustomHtml($sourceHtml);

            // Persist clone to get its ID
            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Clone (entity __clone) failed', ['ex' => $e->getMessage()]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to clone email: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $cloneId = (int) $clone->getId();

        // 4) If we had MJML, write it to the clone first…
        $wroteMjml = false;
        if ($mjml !== '') {
            try {
                $affected = $conn->update('bundle_grapesjsbuilder', ['custom_mjml' => $mjml], ['email_id' => $cloneId]);
                if ($affected === 0) {
                    $conn->insert('bundle_grapesjsbuilder', ['email_id' => $cloneId, 'custom_mjml' => $mjml]);
                }
                $wroteMjml = true;
            } catch (\Throwable $e) {
                $logger->error('[AiTranslate] Failed initial MJML write for clone', ['cloneId' => $cloneId, 'ex' => $e->getMessage()]);
            }
        }

        // 5) Translate subject + MJML (if present) and set custom_html to compiled HTML
        $translatedSubject = null;
        $translatedMjml    = null;
        $samples           = [];

        try {
            // Subject
            $origSubject = (string) $clone->getSubject();
            if ($origSubject !== '') {
                $translatedSubject = $mjmlService->translateRichText($origSubject, $targetLangApi, $samples);
                if ($translatedSubject !== $origSubject) {
                    $clone->setSubject($translatedSubject);
                }
            }

            // MJML
            if ($mjml !== '') {
                $mj = $mjmlService->translateMjml($mjml, $targetLangApi);
                $translatedMjml = $mj['mjml'] ?? $mjml;

                // Persist translated MJML to clone
                $affected = $conn->update('bundle_grapesjsbuilder', ['custom_mjml' => $translatedMjml], ['email_id' => $cloneId]);
                if ($affected === 0) {
                    $conn->insert('bundle_grapesjsbuilder', ['email_id' => $cloneId, 'custom_mjml' => $translatedMjml]);
                }

                // Compile MJML → HTML and set as custom_html so preview reflects translation immediately
                $compiled = $mjmlCompiler->compile($translatedMjml, $clone->getTemplate());
                if (($compiled['success'] ?? false) && !empty($compiled['html'])) {
                    $clone->setCustomHtml($compiled['html']);
                } else {
                    $logger->warning('[AiTranslate] MJML compile failed; keeping existing custom_html', [
                        'cloneId' => $cloneId,
                        'error'   => $compiled['error'] ?? 'unknown',
                    ]);
                }
            }

            // Save updated entity LAST so custom_html is persisted after translation
            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Translation / compile step failed', ['cloneId' => $cloneId, 'ex' => $e->getMessage()]);
        }

        // 6) Done
        $lockedMode  = isset($mj['lockedMode']) ? (bool) $mj['lockedMode'] : false;
        $lockedPairs = isset($mj['lockedPairs']) ? (int) $mj['lockedPairs'] : 0;

        $payload = [
            'success' => true,
            'message' => 'Done.',
            'source'  => [
                'emailId'   => $sourceEmail->getId(),
                'name'      => $emailName,
                'language'  => $sourceLangGuess,      // e.g. "en"
                'template'  => $sourceEmail->getTemplate(),
            ],
            'clone'   => [
                'emailId'   => $cloneId,
                'name'      => $clone->getName(),
                'subject'   => $clone->getSubject(),
                'template'  => $clone->getTemplate(),
                'language'  => $clone->getLanguage(), // lowercase (e.g. "de")
                'mjmlWrite' => $wroteMjml,
                'urls'      => [
                    'edit'    => $request->getSchemeAndHttpHost().'/s/emails/edit/'.$cloneId,
                    'view'    => $request->getSchemeAndHttpHost().'/s/emails/view/'.$cloneId,
                    'builder' => $request->getSchemeAndHttpHost().'/s/emails/builder/'.$cloneId,
                    'preview' => $request->getSchemeAndHttpHost().'/email/preview/'.$cloneId,
                ],
            ],
            'translation' => [
                'subjectChanged' => ($translatedSubject !== null),
                'mjmlChanged'    => ($translatedMjml !== null),
                'samples'        => array_slice($samples, 0, 4),
                'lockedMode'     => $lockedMode,
                'lockedPairs'    => $lockedPairs,
            ],
            'deeplProbe' => [
                'success'     => (bool) ($probe['success'] ?? false),
                'translation' => $probe['translation'] ?? null,
                'error'       => $probe['error'] ?? null,
                'host'        => $probe['host'] ?? null,
                'status'      => $probe['status'] ?? null,
            ],
            'note' => 'custom_html is now compiled from the translated MJML so preview reflects the translation immediately.',
        ];

        $logger->info('[AiTranslate] translateAction finished', [
            'cloneId'  => $cloneId,
            'changed'  => $payload['translation'],
        ]);

        return new JsonResponse($payload);
    }
}
