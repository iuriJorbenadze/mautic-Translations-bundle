<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Integration\LeuchtfeuerTranslationsIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class DeeplClientService
{
    /** DeepL endpoints */
    private const API_URL_FREE = 'https://api-free.deepl.com/v2/translate';
    private const API_URL_PRO  = 'https://api.deepl.com/v2/translate';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger,   // logger is always available
        private Client $http,              // use Guzzle instead of cURL
    ) {
    }

    /**
     * Plain-text translation (no HTML handling).
     * $params can include DeepL options like formality, etc.
     *
     * @return array{
     *   success:bool,
     *   translation?:string,
     *   error?:string,
     *   host:string|null,
     *   status:int|null
     * }
     */
    public function translate(string $text, string $targetLang = 'DE', array $params = []): array
    {
        $payload = array_merge([
            'text'                => $text,
            'target_lang'         => strtoupper($targetLang),
            'preserve_formatting' => 1,
        ], $params);

        return $this->requestWithHostFailover($payload);
    }

    /**
     * HTML-aware translation (DeepL tag_handling=html).
     *
     * @return array{
     *   success:bool,
     *   translation?:string,
     *   error?:string,
     *   host:string|null,
     *   status:int|null
     * }
     */
    public function translateHtml(string $html, string $targetLang = 'DE', array $params = []): array
    {
        $payload = array_merge([
            'text'                => $html,
            'target_lang'         => strtoupper($targetLang),
            'tag_handling'        => 'html',
            'split_sentences'     => 'nonewlines',
            'preserve_formatting' => 1,
        ], $params);

        return $this->requestWithHostFailover($payload);
    }

    /**
     * Detect plan by key and try free/pro host accordingly with 403 fallback.
     *
     * @return array{
     *   success:bool,
     *   translation?:string,
     *   error?:string,
     *   host:string|null,
     *   status:int|null
     * }
     */
    private function requestWithHostFailover(array $payload): array
    {
        $integration = $this->integrationHelper->getIntegrationObject(LeuchtfeuerTranslationsIntegration::NAME);
        $keys        = $integration ? $integration->getDecryptedApiKeys() : [];
        $apiKey      = $keys['deepl_api_key'] ?? '';

        if ('' === $apiKey) {
            return [
                'success' => false,
                'error'   => 'API key not set',
                'host'    => null,
                'status'  => null,
            ];
        }

        $guessFree = str_ends_with($apiKey, ':fx');
        $firstHost = $guessFree ? self::API_URL_FREE : self::API_URL_PRO;
        $altHost   = $guessFree ? self::API_URL_PRO : self::API_URL_FREE;

        $this->log('[LeuchtfeuerTranslations][DeepL] plan guess', [
            'guess'     => $guessFree ? 'free' : 'pro',
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
        ]);

        $first = $this->callDeepL($firstHost, $apiKey, $payload);
        if (403 !== ($first['status'] ?? null)) {
            return $first;
        }

        $this->log('[LeuchtfeuerTranslations][DeepL] 403 on first host, trying fallback', [
            'firstHost' => $firstHost,
            'altHost'   => $altHost,
            'status'    => $first['status'],
        ]);

        return $this->callDeepL($altHost, $apiKey, $payload);
    }

    /**
     * Low-level HTTP request (Guzzle).
     * $payload is the full DeepL form body (we add auth_key here).
     *
     * @return array{
     *   success:bool,
     *   translation?:string,
     *   error?:string,
     *   host:string|null,
     *   status:int|null
     * }
     */
    private function callDeepL(string $host, string $apiKey, array $payload): array
    {
        $data = array_merge(['auth_key' => $apiKey], $payload);

        try {
            $resp     = $this->http->request('POST', $host, [
                'headers'     => ['Accept' => 'application/json'],
                'form_params' => $data,
            ]);
            $httpCode = $resp->getStatusCode();
            $body     = (string) $resp->getBody();
        } catch (GuzzleException $e) {
            $this->log('[LeuchtfeuerTranslations][DeepL] HTTP error', [
                'host'  => $host,
                'error' => $e->getMessage(),
                'code'  => (int) $e->getCode(),
            ]);

            $status = (int) $e->getCode();
            $status = $status > 0 ? $status : null;

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'host'    => $host,
                'status'  => $status,
            ];
        }

        // Decode JSON with exceptions and validate structure
        $json = null;
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // If non-200 and body is not valid JSON, we still want to surface HTTP error below.
            $json = null;
        }

        if (Response::HTTP_OK !== $httpCode) {
            $msg = (is_array($json) && isset($json['message']))
                ? (string) $json['message']
                : ('HTTP error '.$httpCode);

            return [
                'success' => false,
                'error'   => $msg,
                'host'    => $host,
                'status'  => $httpCode,
            ];
        }

        if (!is_array($json) || !isset($json['translations']) || !is_array($json['translations']) || !isset($json['translations'][0]['text'])) {
            return [
                'success' => false,
                'error'   => 'Unexpected API response (missing translations[0].text)',
                'host'    => $host,
                'status'  => $httpCode,
            ];
        }

        $translation = (string) $json['translations'][0]['text'];

        return [
            'success'     => true,
            'translation' => $translation,
            'host'        => $host,
            'status'      => $httpCode,
        ];
    }

    private function log(string $msg, array $ctx = []): void
    {
        $this->logger->info($msg, $ctx);
    }
}
