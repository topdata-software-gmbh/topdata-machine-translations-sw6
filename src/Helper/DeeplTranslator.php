<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

/**
 * 09/2024 created
 */
class DeeplTranslator
{
    private string $apiKey;
    private string $apiUrl = 'https://api-free.deepl.com/v2/translate';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function translate(string $text, string $sourceLang, string $targetLang): string
    {
        $data = [
            'auth_key'    => $this->apiKey,
            'text'        => $text,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['translations'][0]['text'])) {
            return $result['translations'][0]['text'];
        } else {
            throw new \Exception('Translation failed: ' . $response);
        }
    }
}
