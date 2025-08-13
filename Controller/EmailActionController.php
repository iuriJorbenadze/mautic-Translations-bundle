<?php
// plugins/AiTranslateBundle/Controller/EmailActionController.php

namespace MauticPlugin\AiTranslateBundle\Controller;

use Doctrine\DBAL\Connection;
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

        $targetLang = strtoupper((string) $request->get('targetLang', ''));
        if ($targetLang === '') {
            return new JsonResponse(['success' => false, 'message' => 'Target language not provided.'], Response::HTTP_BAD_REQUEST);
        }

        $sourceLangGuess = $sourceEmail->getLanguage() ?: '';
        $emailName       = $sourceEmail->getName() ?: '';
        $isCodeMode      = $sourceEmail->getTemplate() === 'mautic_code_mode';

        // 1) Probe DeepL quickly
        $probe = $deepl->translate('Hello from Mautic', $targetLang);

        // 2) Read MJML from GrapesJS builder storage (bundle_grapesjsbuilder.custom_mjml)
        $mjml     = '';
        $tableHit = null;

        try {
            $sql  = 'SELECT custom_mjml FROM bundle_grapesjsbuilder WHERE email_id = :id LIMIT 1';
            $row  = $conn->fetchAssociative($sql, ['id' => $sourceEmail->getId()]);
            $mjml = isset($row['custom_mjml']) ? (string) $row['custom_mjml'] : '';
            $tableHit = 'bundle_grapesjsbuilder.custom_mjml';
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Failed to fetch MJML from bundle_grapesjsbuilder', [
                'emailId' => $sourceEmail->getId(),
                'ex'      => $e->getMessage(),
            ]);
        }

        // 3) Manually create a cloned Email entity (copy only safe fields)
        try {
            $clone = new Email();

            // Name with language suffix
            $suffix = sprintf(' [%s]', $targetLang);
            if (method_exists($clone, 'setName')) {
                $clone->setName(rtrim(($emailName ?: 'Email').$suffix));
            }

            // Copy subject
            if (method_exists($clone, 'setSubject') && method_exists($sourceEmail, 'getSubject')) {
                $clone->setSubject((string) $sourceEmail->getSubject());
            }

            // Copy template
            if (method_exists($clone, 'setTemplate') && method_exists($sourceEmail, 'getTemplate')) {
                $clone->setTemplate((string) $sourceEmail->getTemplate());
            }

            // Copy language (mark as target)
            if (method_exists($clone, 'setLanguage')) {
                $clone->setLanguage($targetLang ?: $sourceLangGuess);
            }

            // Copy customHtml from source to avoid PlainTextHelper::$html = null when viewing
            $sourceHtml = method_exists($sourceEmail, 'getCustomHtml') ? $sourceEmail->getCustomHtml() : null;
            if ($sourceHtml === null) {
                $sourceHtml = '<!doctype html><html><body></body></html>';
            }
            if (method_exists($clone, 'setCustomHtml')) {
                $clone->setCustomHtml($sourceHtml);
            }

            // Optionally copy a few other safe, common fields if available (guarded)
            if (method_exists($clone, 'setDescription') && method_exists($sourceEmail, 'getDescription')) {
                $clone->setDescription((string) $sourceEmail->getDescription());
            }
            if (method_exists($clone, 'setIsPublished')) {
                $clone->setIsPublished(false); // keep draft by default
            }

            // Persist to get an ID
            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[AiTranslate] Clone (manual new Email) failed', ['ex' => $e->getMessage()]);
            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to clone email: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4) Write MJML for the cloned email ID (if we found MJML on the source)
        $cloneId   = (int) $clone->getId();
        $mjmlWrite = false;
        if ($mjml !== '') {
            try {
                // UPDATE first
                $affected = $conn->update(
                    'bundle_grapesjsbuilder',
                    ['custom_mjml' => $mjml],
                    ['email_id' => $cloneId]
                );

                if ($affected === 0) {
                    // If no row existed yet, INSERT
                    $conn->insert('bundle_grapesjsbuilder', [
                        'email_id'    => $cloneId,
                        'custom_mjml' => $mjml,
                    ]);
                }

                $mjmlWrite = true;
            } catch (\Throwable $e) {
                $logger->error('[AiTranslate] Failed to write MJML for clone', [
                    'cloneId' => $cloneId,
                    'ex'      => $e->getMessage(),
                ]);
            }
        }

        // 5) Prepare response
        $payload = [
            'success'   => true,
            'message'   => 'Probe OK. Cloned email. (No content translation yet.)',
            'source'    => [
                'emailId'   => $sourceEmail->getId(),
                'name'      => $emailName,
                'language'  => $sourceLangGuess,
                'template'  => $sourceEmail->getTemplate(),
            ],
            'clone'     => [
                'emailId'   => $cloneId,
                'name'      => method_exists($clone, 'getName') ? $clone->getName() : ('Email '.$cloneId),
                'subject'   => method_exists($clone, 'getSubject') ? $clone->getSubject() : '',
                'subjectTranslated' => false, // not yet
                'template'  => method_exists($clone, 'getTemplate') ? $clone->getTemplate() : '',
                'mjmlWrite' => $mjmlWrite,
                'urls'      => [
                    'edit'    => $request->getSchemeAndHttpHost().'/s/emails/edit/'.$cloneId,
                    'view'    => $request->getSchemeAndHttpHost().'/s/emails/view/'.$cloneId,
                    'builder' => $request->getSchemeAndHttpHost().'/s/emails/builder/'.$cloneId,
                ],
            ],
            'deeplProbe' => [
                'success'     => (bool) ($probe['success'] ?? false),
                'translation' => $probe['translation'] ?? null,
                'error'       => $probe['error'] ?? null,
                'apiKey'      => $probe['apiKey'] ?? null,
                'host'        => $probe['host'] ?? null,
                'status'      => $probe['status'] ?? null,
                'body'        => $probe['body'] ?? null,
            ],
            'note' => 'Cloned entity + copied MJML and HTML. Next step: translate content inside MJML.',
        ];

        $logger->info('[AiTranslate] clone done (manual)', [
            'sourceId'  => $sourceEmail->getId(),
            'cloneId'   => $cloneId,
            'mjmlWrite' => $mjmlWrite,
        ]);

        return new JsonResponse($payload);
    }
}
