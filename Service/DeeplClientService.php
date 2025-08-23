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
     * Plain-text translation (no HTML handling).
     * $params can include DeepL options like formality, etc.
     */
    public function translate(string $text, string $targetLang = 'DE', array $params = []): array
    {
        $payload = array_merge([
            'text'              => $text,
            'target_lang'       => strtoupper($targetLang),
            // keep formatting when possible
            'preserve_formatting' => 1,
        ], $params);

        return $this->requestWithHostFailover($payload);
    }

    /**
     * HTML-aware translation (DeepL tag_handling=html).
     * We do not pre/post "shield" HTML – we let DeepL handle markup.
     */
    public function translateHtml(string $html, string $targetLang = 'DE', array $params = []): array
    {
        $payload = array_merge([
            'text'                => $html,
            'target_lang'         => strtoupper($targetLang),
            'tag_handling'        => 'html',
            // DeepL defaults to nonewlines with tag_handling=html in next-gen; set explicitly for clarity
            'split_sentences'     => 'nonewlines',
            // Helps keep whitespace/line-breaks intact where possible
            'preserve_formatting' => 1,
        ], $params);

        return $this->requestWithHostFailover($payload);
    }

    /**
     * Detect plan by key and try free/pro host accordingly with 403 fallback.
     */
    private function requestWithHostFailover(array $payload): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('AiTranslate');
        $keys        = $integration ? $integration->getDecryptedApiKeys() : [];
        $apiKey      = $keys['deepl_api_key'] ?? '';

        if ($apiKey === '') {
            return [
                'success' => false,
                'error'   => 'API key not set',

                'host'    => null,
                'status'  => null,
                'body'    => null,
            ];
        }

        $guessFree = str_ends_with($apiKey, ':fx');
        $firstHost = $guessFree ? $this->apiUrlFree : $this->apiUrlPro;
        $altHost   = $guessFree ? $this->apiUrlPro  : $this->apiUrlFree;

        $this->log('[AiTranslate][DeepL] plan guess', [
            'guess'     => $guessFree ? 'free' : 'pro',
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
        ]);

        $first = $this->callDeepL($firstHost, $apiKey, $payload);
        if (($first['status'] ?? null) !== 403) {
            return $first;
        }

        $this->log('[AiTranslate][DeepL] 403 on first host, trying fallback', [
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
            'status'    => $first['status'] ?? null,
            'body'      => $first['body'] ?? null,
        ]);

        $second = $this->callDeepL($altHost, $apiKey, $payload);
        return $second['success'] ? $second : $second;
    }

    /**
     * Low-level HTTP request.
     * $payload is the full DeepL form body (we add auth_key here).
     */
    private function callDeepL(string $host, string $apiKey, array $payload): array
    {
        $data = array_merge(['auth_key' => $apiKey], $payload);

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
