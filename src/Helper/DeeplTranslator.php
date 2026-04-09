<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

/**
 * 09/2024 created
 * 11/2025 updated to header-based authentication
 */
class DeeplTranslator
{
    private string $apiKey;
    private string $apiUrl = 'https://api-free.deepl.com/v2/translate';

    public function __construct(string $apiKey)
    {
        assert(strlen($apiKey) > 0, 'DeepL API key is missing');
        $this->apiKey = $apiKey;
    }

    /**
     * 09/2024 created
     * 11/2025: Moved auth_key from POST body to Authorization header
     */
    public function translate(string $text, string $sourceLang, string $targetLang, $meta = null): string
    {
        assert(strlen($this->apiKey) > 0, 'DeepL API key is missing');

        // Data no longer contains 'auth_key'
        $data = [
            'text'        => $text,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
        ];

        $ch = curl_init($this->apiUrl);

        // Define headers according to DeepL's new requirements
        $headers = [
            'Authorization: DeepL-Auth-Key ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['translations'][0]['text'])) {
            return $result['translations'][0]['text'];
        } else {
            // Provide more descriptive error messages from the API if available
            $errorMessage = $result['message'] ?? $response;
            throw new \Exception("Translation failed (HTTP $httpCode): " . $errorMessage);
        }
    }
}
