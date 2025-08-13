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
        $this->logger = $logger;
    }

    /**
     * @return array{
     *   success: bool,
     *   translation?: string|null,
     *   error?: string|null,
     *   apiKey?: string,
     *   host?: string,
     *   status?: int|null,
     *   body?: string|null,
     *   raw?: mixed
     * }
     */
    public function translate(string $text, string $targetLang = 'DE'): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('AiTranslate');
        $keys        = $integration ? $integration->getDecryptedApiKeys() : [];
        $apiKey      = $keys['deepl_api_key'] ?? '';

        $host = (is_string($apiKey) && str_starts_with($apiKey, 'deepl-'))
            ? $this->apiUrlPro
            : $this->apiUrlFree;

        // DEBUG: log raw key + host
        if ($this->logger) {
            $this->logger->info('[AiTranslate][DeeplClientService] Using API key + host', [
                'apiKey' => $apiKey,
                'host'   => $host,
            ]);
        } else {
            @error_log('[AiTranslate][DeeplClientService] Using API key: '.$apiKey.' | host: '.$host);
        }

        if ($apiKey === '') {
            return [
                'success' => false,
                'error'   => 'API key not set',
                'apiKey'  => $apiKey,
                'host'    => $host,
                'status'  => null,
                'body'    => null,
            ];
        }

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
            if ($this->logger) {
                $this->logger->error('[AiTranslate][DeeplClientService] cURL error', [
                    'errno' => $errno,
                    'error' => $error,
                ]);
            } else {
                @error_log('[AiTranslate][DeeplClientService] cURL error #'.$errno.': '.$error);
            }

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
}
