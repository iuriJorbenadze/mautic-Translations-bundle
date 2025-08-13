<?php

namespace MauticPlugin\AiTranslateBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class DeeplClientService
{
    private IntegrationHelper $integrationHelper;
    private ?LoggerInterface $logger;

    private string $apiUrlFree = 'https://api-free.deepl.com/v2/translate';
    private string $apiUrlPro  = 'https://api.deepl.com/v2/translate';

    public function __construct(IntegrationHelper $integrationHelper, ?LoggerInterface $logger = null)
    {
        $this->integrationHelper = $integrationHelper;
        $this->logger            = $logger;
    }

    /**
     * Try translate; auto-detect plan by key, fallback on 403.
     */
    public function translate(string $text, string $targetLang = 'DE'): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('AiTranslate');
        $keys        = $integration ? $integration->getDecryptedApiKeys() : [];
        $apiKey      = $keys['deepl_api_key'] ?? '';

        if ($apiKey === '') {
            return [
                'success' => false,
                'error'   => 'API key not set',
                'apiKey'  => $apiKey,
                'host'    => null,
                'status'  => null,
                'body'    => null,
            ];
        }

        // 1) Detect plan by suffix (DeepL Free keys typically end with ":fx")
        $guessFree = str_ends_with($apiKey, ':fx');
        $firstHost = $guessFree ? $this->apiUrlFree : $this->apiUrlPro;
        $altHost   = $guessFree ? $this->apiUrlPro  : $this->apiUrlFree;

        $this->log('[AiTranslate][DeepL] Plan guess', [
            'guess' => $guessFree ? 'free' : 'pro',
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
            'apiKey'    => $apiKey,
        ]);

        // 2) Try first host
        $first = $this->callDeepL($firstHost, $apiKey, $text, $targetLang);
        if ($first['status'] !== 403) {
            return $first; // success or other error (not auth/endpoint mismatch)
        }

        // 3) If 403, fallback to the other host once
        $this->log('[AiTranslate][DeepL] 403 on first host, trying fallback', [
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
            'status'    => $first['status'],
            'body'      => $first['body'],
        ]);

        $second = $this->callDeepL($altHost, $apiKey, $text, $targetLang);

        // Prefer success if second worked; otherwise return richer error (second)
        return $second['success'] ? $second : $second;
    }

    /**
     * Low-level call helper (keeps your diagnostics).
     */
    private function callDeepL(string $host, string $apiKey, string $text, string $targetLang): array
    {
        $data = [
            'auth_key'    => $apiKey,
            'text'        => $text,
            'target_lang' => strtoupper($targetLang),
        ];

        $ch = curl_init($host);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->log('[AiTranslate][DeepL] cURL error', [
                'host'  => $host,
                'errno' => $errno,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'error'   => 'cURL error #'.$errno.': '.$error,
                'apiKey'  => $apiKey,
                'host'    => $host,
                'status'  => null,
                'body'    => null,
            ];
        }

        $rawBody = $response;
        $json    = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = is_array($json) && isset($json['message'])
                ? (string) $json['message']
                : ('HTTP error '.$httpCode);

            return [
                'success' => false,
                'error'   => $msg,
                'apiKey'  => $apiKey,
                'host'    => $host,
                'status'  => $httpCode,
                'body'    => $rawBody,
            ];
        }

        $translation = $json['translations'][0]['text'] ?? null;
        if ($translation === null) {
            return [
                'success' => false,
                'error'   => 'Unexpected API response (no translations[0].text)',
                'apiKey'  => $apiKey,
                'host'    => $host,
                'status'  => $httpCode,
                'body'    => $rawBody,
                'raw'     => $json,
            ];
        }

        return [
            'success'     => true,
            'translation' => $translation,
            'apiKey'      => $apiKey,
            'host'        => $host,
            'status'      => $httpCode,
            'body'        => $rawBody,
            'raw'         => $json,
        ];
    }

    private function log(string $msg, array $ctx = []): void
    {
        if ($this->logger) {
            $this->logger->info($msg, $ctx);
        } else {
            @error_log($msg.' '.json_encode($ctx));
        }
    }
}
