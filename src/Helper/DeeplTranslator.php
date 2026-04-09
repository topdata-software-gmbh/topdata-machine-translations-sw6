<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 09/2024 created
 * 11/2025 updated to header-based authentication and exponential backoff
 */
class DeeplTranslator
{
    private string $apiKey;
    private string $apiUrl = 'https://api-free.deepl.com/v2/translate';

    // Backoff configuration
    private int $maxRetries = 5;
    private int $initialDelay = 1; // seconds

    public function __construct(string $apiKey)
    {
        assert(strlen($apiKey) > 0, 'DeepL API key is missing');
        $this->apiKey = $apiKey;
    }

    /**
     * Translates text with built-in retry logic for rate limits (HTTP 429)
     */
    public function translate(string $text, string $sourceLang, string $targetLang, $meta = null): string
    {
        assert(strlen($this->apiKey) > 0, 'DeepL API key is missing');

        $data = [
            'text'        => $text,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
        ];

        $headers = [
            'Authorization: DeepL-Auth-Key ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        $attempt = 0;
        $delay = $this->initialDelay;

        while ($attempt <= $this->maxRetries) {
            $ch = curl_init($this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);

            // 1. Handle Connection Errors (Network issues)
            if ($curlError) {
                if ($attempt < $this->maxRetries) {
                    $this->logRetry($attempt, $delay, "Network error: $curlError");
                    $this->wait($delay);
                    $attempt++;
                    $delay *= 2;
                    continue;
                }
                throw new \Exception('cURL error: ' . $curlError);
            }

            // 2. Handle Success
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['translations'][0]['text'])) {
                    return $result['translations'][0]['text'];
                }
            }

            // 3. Handle Rate Limiting (HTTP 429)
            if ($httpCode === 429) {
                if ($attempt < $this->maxRetries) {
                    $this->logRetry($attempt, $delay, "Rate limit reached (429)");
                    $this->wait($delay);
                    $attempt++;
                    $delay *= 2; // Exponential increase: 1s, 2s, 4s, 8s, 16s
                    continue;
                }
            }

            // 4. Handle Final Failure (Other HTTP codes or exhausted retries)
            $result = json_decode($response, true);
            $errorMessage = $result['message'] ?? $response;
            throw new \Exception("Translation failed (HTTP $httpCode): " . $errorMessage);
        }

        throw new \Exception("Translation failed after {$this->maxRetries} retries.");
    }

    /**
     * Log retry attempt to console if possible
     */
    private function logRetry(int $attempt, int $delay, string $reason): void
    {
        $msg = sprintf("   [Retry] %s. Waiting %ds before attempt #%d...", $reason, $delay, $attempt + 2);

        // Use CliLogger if it's initialized (to stay consistent with plugin output)
        try {
            CliLogger::warning($msg);
        } catch (\Throwable) {
            // Fallback to error_log if CliLogger is not available in current context
            error_log($msg);
        }
    }

    /**
     * Helper to wait/sleep
     */
    private function wait(int $seconds): void
    {
        sleep($seconds);
    }
}