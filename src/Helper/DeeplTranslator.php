<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Handles translation using the DeepL API with built-in retry logic for rate limits.
 * Implements exponential backoff strategy when encountering HTTP 429 (rate limit) errors.
 * 
 * @since 09/2024
 * @updated 11/2025 to header-based authentication and exponential backoff
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
     * Translates text using the DeepL API with built-in retry logic for rate limits (HTTP 429).
     * Implements exponential backoff strategy when encountering rate limit errors.
     *
     * @param string $text The text to translate
     * @param string $sourceLang The source language code
     * @param string $targetLang The target language code
     * @param mixed $meta Optional metadata for the translation request
     * @return string The translated text
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

            // ---- Connection Errors (Network issues)
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

            // ---- Success
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['translations'][0]['text'])) {
                    return $result['translations'][0]['text'];
                }
            }

            // ---- Rate Limiting (HTTP 429)
            if ($httpCode === 429) {
                if ($attempt < $this->maxRetries) {
                    $this->logRetry($attempt, $delay, "Rate limit reached (429)");
                    $this->wait($delay);
                    $attempt++;
                    $delay *= 2; // Exponential increase: 1s, 2s, 4s, 8s, 16s
                    continue;
                }
            }

            // ---- Final Failure (Other HTTP codes or exhausted retries)
            $result = json_decode($response, true);
            $errorMessage = $result['message'] ?? $response;
            throw new \Exception("Translation failed (HTTP $httpCode): " . $errorMessage);
        }

        throw new \Exception("Translation failed after {$this->maxRetries} retries.");
    }

    /**
     * Logs a retry attempt to the console or error log if CliLogger is not available.
     *
     * @param int $attempt The current attempt number (0-based)
     * @param int $delay The delay in seconds before the next attempt
     * @param string $reason The reason for the retry
     * @return void
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
     * Pauses execution for the specified number of seconds.
     *
     * @param int $seconds The number of seconds to wait
     * @return void
     */
    private function wait(int $seconds): void
    {
        sleep($seconds);
    }
}