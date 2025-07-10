<?php

namespace MauticPlugin\AiTranslateBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;

class DeeplClientService
{
    private $integrationHelper;
    private $apiUrl = 'https://api-free.deepl.com/v2/translate';

    public function __construct(IntegrationHelper $integrationHelper)
    {
        $this->integrationHelper = $integrationHelper;
    }

    public function translate($text, $targetLang = 'DE')
    {
        // Get the integration object by name (must match getName() in your Integration class)
        $integration = $this->integrationHelper->getIntegrationObject('AiTranslate');
        $keys = $integration ? $integration->getDecryptedApiKeys() : [];
        $apiKey = $keys['deepl_api_key'] ?? '';

        if (!$apiKey) {
            return ['success' => false, 'error' => 'API key not set'];
        }

        $data = [
            'auth_key'    => $apiKey,
            'text'        => $text,
            'target_lang' => $targetLang,
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        $response = curl_exec($ch);
        if ($response === false) {
            return ['success' => false, 'error' => curl_error($ch)];
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);
        if ($httpCode !== 200) {
            $error = isset($json['message']) ? $json['message'] : 'HTTP error ' . $httpCode;
            return ['success' => false, 'error' => $error];
        }
        if (!isset($json['translations'][0]['text'])) {
            return ['success' => false, 'error' => 'Unexpected API response'];
        }
        return ['success' => true, 'translation' => $json['translations'][0]['text']];
    }
}
