<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;
use MauticPlugin\GrapesJsBuilderBundle\Model\GrapesJsBuilderModel;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\DeeplClientService;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\FeatureGateService;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlCompileService;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\MjmlTranslateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        TranslatorInterface $translator,
        FeatureGateService $featureGate
    ): Response {
        $logger->info('[LeuchtfeuerTranslations] translateAction start', [
            'objectId'   => $objectId,
            'targetLang' => $request->get('targetLang'),
        ]);

        // Respect plugin toggle (Published switch in Plugins UI)
        if (!$featureGate->isEnabled()) {
            $logger->info('[LeuchtfeuerTranslations] translateAction blocked: integration disabled');

            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $translator->trans('plugin.leuchtfeuertranslations.error.integration_disabled'),
                ],
                Response::HTTP_FORBIDDEN
            );
        }

        /** @var EmailModel $model */
        $model = $this->getModel(EmailModel::class);

        /** @var Email|null $sourceEmail */
        $sourceEmail = $model->getEntity($objectId);

        if (
            null === $sourceEmail
            || !$security->hasEntityAccess(
                'email:emails:view:own',
                'email:emails:view:other',
                $sourceEmail->getCreatedBy()
            )
        ) {
            $logger->warning('[LeuchtfeuerTranslations] email not found or access denied', ['objectId' => $objectId]);

            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $translator->trans('plugin.leuchtfeuertranslations.error.email_not_found_or_access_denied'),
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        /**
         * Read input consistently based on HTTP verb (POST preferred).
         * Check requirements BEFORE any processing to avoid unnecessary work.
         */
        $targetLangRaw = $request->isMethod('POST')
            ? $request->request->get('targetLang')
            : $request->query->get('targetLang');

        $targetLangRaw = is_string($targetLangRaw) ? trim($targetLangRaw) : '';

        if ($targetLangRaw === '') {
            return new JsonResponse(
                [
                    'success' => false,
                    'message' => $translator->trans('plugin.leuchtfeuertranslations.error.target_language_missing'),
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Normalize for DeepL (UPPER) and Mautic (lower)
        $targetLangApi = strtoupper($targetLangRaw);
        $targetLangIso = strtolower($targetLangApi);

        $sourceLangGuess = strtolower($sourceEmail->getLanguage() ?: '');
        $emailName       = $sourceEmail->getName() ?: '';

        // 1) Quick probe (do not leak probe details to client)
        $probe = $deepl->translate('Hello from Mautic', $targetLangApi);
        if ($probe['success'] !== true) {
            $logger->error('[LeuchtfeuerTranslations] DeepL probe failed', [
                'error'  => $probe['error'],
                'host'   => $probe['host'],
                'status' => $probe['status'],
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => $translator->trans('plugin.leuchtfeuertranslations.error.deepl_probe_failed'),
            ], Response::HTTP_BAD_REQUEST);
        }

        // Prepare GrapesJs model once; reuse
        /** @var GrapesJsBuilderModel $grapesModel */
        $grapesModel = $this->getModel(GrapesJsBuilderModel::class);

        // 2) Read MJML using GrapesJsBuilderModel (avoid raw SQL / missing table prefix)
        $mjml = '';
        try {
            $grapes = $grapesModel->getGrapesJsFromEmailId((int) $sourceEmail->getId());
            $mjml   = $grapes?->getCustomMjml() ?? '';
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Failed to fetch MJML via GrapesJsBuilderModel', [
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

            // Name + target language suffix (rtrim not needed)
            $clone->setName(($emailName !== '' ? $emailName : 'Email') . ' [' . $targetLangApi . ']');

            // IMPORTANT: Mautic expects lowercase language code
            $clone->setLanguage($targetLangIso ?: $sourceLangGuess);

            // Ensure HTML is not null (prevents PlainTextHelper error on /view)
            $sourceHtml = $sourceEmail->getCustomHtml();
            if (null === $sourceHtml) {
                $sourceHtml = '<!doctype html><html><body></body></html>';
            }
            $clone->setCustomHtml($sourceHtml);

            // Persist clone to get its ID
            $model->saveEntity($clone);

            // Ensure Doctrine assigned an ID (avoid casting null→0)
            $cloneId = $clone->getId();
            if ($cloneId === null) {
                $logger->error('[LeuchtfeuerTranslations] Clone persisted but ID is still null');

                return new JsonResponse([
                    'success' => false,
                    'message' => $translator->trans('plugin.leuchtfeuertranslations.error.clone_persist_failed'),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $cloneId = (int) $cloneId;
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Clone (entity __clone) failed', ['ex' => $e->getMessage()]);

            return new JsonResponse([
                'success' => false,
                'message' => $translator->trans('plugin.leuchtfeuertranslations.error.clone_failed', ['%error%' => $e->getMessage()]),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4) If we had MJML, write it to the clone via entity/repository (no raw SQL)
        $wroteMjml = false;
        if ($mjml !== '') {
            try {
                /** @var GrapesJsBuilder|null $cloneGrapes */
                $cloneGrapes = $grapesModel->getGrapesJsFromEmailId($cloneId);
                if ($cloneGrapes === null) {
                    $cloneGrapes = new GrapesJsBuilder();
                    $cloneGrapes->setEmail($clone);
                }

                if ($cloneGrapes->getCustomMjml() !== $mjml) {
                    $cloneGrapes->setCustomMjml($mjml);
                    $grapesModel->getRepository()->saveEntity($cloneGrapes);
                }
                $wroteMjml = true;
            } catch (\Throwable $e) {
                $logger->error('[LeuchtfeuerTranslations] Failed initial MJML write for clone (entity/repository)', [
                    'cloneId' => $cloneId,
                    'ex'      => $e->getMessage(),
                ]);
            }
        }

        // 5) Translate subject + MJML (if present) and set custom_html to compiled HTML
        $translatedSubject = null;
        $translatedMjml    = null;
        $samples           = [];
        $mj                = []; // ensure defined

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
                $mj             = $mjmlService->translateMjml($mjml, $targetLangApi);
                $translatedMjml = $mj['mjml'];

                /** @var GrapesJsBuilder|null $cloneGrapes */
                $cloneGrapes = $grapesModel->getGrapesJsFromEmailId($cloneId);
                if ($cloneGrapes === null) {
                    $cloneGrapes = new GrapesJsBuilder();
                    $cloneGrapes->setEmail($clone);
                }
                if ($cloneGrapes->getCustomMjml() !== $translatedMjml) {
                    $cloneGrapes->setCustomMjml($translatedMjml);
                    $grapesModel->getRepository()->saveEntity($cloneGrapes);
                }

                // Compile MJML → HTML and set as custom_html so preview reflects translation immediately
                $compiled     = $mjmlCompiler->compile($translatedMjml, $clone->getTemplate());
                $compileOk    = ($compiled['success'] ?? false) === true;
                $compiledHtml = isset($compiled['html']) && is_string($compiled['html']) ? $compiled['html'] : '';

                if ($compileOk && $compiledHtml !== '') {
                    $clone->setCustomHtml($compiledHtml);
                } else {
                    $logger->warning('[LeuchtfeuerTranslations] MJML compile failed; keeping existing custom_html', [
                        'cloneId' => $cloneId,
                        'error'   => $compiled['error'] ?? 'unknown',
                    ]);
                }
            }

            // Save updated entity LAST so custom_html is persisted after translation
            $model->saveEntity($clone);
        } catch (\Throwable $e) {
            $logger->error('[LeuchtfeuerTranslations] Translation / compile step failed', ['cloneId' => $cloneId, 'ex' => $e->getMessage()]);
        }

        // 6) Done
        $lockedMode  = isset($mj['lockedMode']) ? (bool) $mj['lockedMode'] : false;
        $lockedPairs = isset($mj['lockedPairs']) ? (int) $mj['lockedPairs'] : 0;

        $payload = [
            'success' => true,
            'message' => $translator->trans('plugin.leuchtfeuertranslations.done'),
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
                    'edit'    => $this->generateUrl('mautic_email_action', ['objectAction' => 'edit',    'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'view'    => $this->generateUrl('mautic_email_action', ['objectAction' => 'view',    'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'builder' => $this->generateUrl('mautic_email_action', ['objectAction' => 'builder', 'objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                    'preview' => $this->generateUrl('mautic_email_preview', ['objectId' => $cloneId], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            ],
            'translation' => [
                'subjectChanged' => ($translatedSubject !== null),
                'mjmlChanged'    => ($translatedMjml !== null),
                'samples'        => array_slice($samples, 0, 4),
                'lockedMode'     => $lockedMode,
                'lockedPairs'    => $lockedPairs,
            ],
            // NOTE: removed 'deeplProbe' block from client response to avoid leaking details
            'note' => $translator->trans('plugin.leuchtfeuertranslations.note_compiled_from_translated_mjml'),
        ];

        $logger->info('[LeuchtfeuerTranslations] translateAction finished', [
            'cloneId'  => $cloneId,
            'changed'  => $payload['translation'],
        ]);

        return new JsonResponse($payload);
    }
}
