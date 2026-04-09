<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;

/**
 * Processes translation of plugin snippets using the DeepL translation service.
 * Handles reading source and target files, translating content, and writing back translated files.
 */
final class SnippetTranslationProcessor
{
    public function __construct(private readonly SnippetJsonPersister $persister)
    {
    }

    /**
     * Processes plugin snippets for translation.
     * 
     * @param array $snippetMeta Metadata about the plugin and snippets to be translated
     * @param DeeplTranslator $translator The translation service instance
     * @param string $fromLocale The source locale
     * @param bool $force Whether to force translation of already translated content
     * @param bool $dryRun If true, no actual translation or file writing will occur
     * @param bool $backup Whether to create a backup of the target file before writing
     * @param bool $skipEmpty Whether to skip empty strings during translation
     * @return array Summary of translation operations including counts for each locale
     */
    public function processPluginSnippets(
        array $snippetMeta,
        DeeplTranslator $translator,
        string $fromLocale,
        bool $force,
        bool $dryRun,
        bool $backup,
        bool $skipEmpty
    ): array {
        // ---- Initialize snippet jobs and summary
        $snippetJobs = $snippetMeta['snippetJobs'] ?? [[
            'sourceFile' => $snippetMeta['sourceFile'],
            'targetFiles' => $snippetMeta['targetFiles'],
        ]];

        $summary = [
            'plugin' => $snippetMeta['pluginName'],
            'locales' => [],
        ];

        // ---- Process each snippet job
        foreach ($snippetJobs as $snippetJob) {
            $sourceData = $this->persister->readJsonFile($snippetJob['sourceFile']);

            // ---- Process each target file for the current snippet job
            foreach ($snippetJob['targetFiles'] as $targetLocale => $targetFile) {
                $localeCounters = $summary['locales'][$targetLocale] ?? [
                    'scanned' => 0,
                    'translated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'errorDetails' => [],
                ];

                if (!isset($localeCounters['errorDetails']) || !is_array($localeCounters['errorDetails'])) {
                    $localeCounters['errorDetails'] = [];
                }

                $targetData = $this->persister->readJsonFile($targetFile);
                $translatedPayload = $this->translateNode(
                    $sourceData,
                    $targetData,
                    $translator,
                    $this->toDeepLSourceLanguage($fromLocale),
                    $this->toDeepLTargetLanguage($targetLocale),
                    $force,
                    $skipEmpty,
                    $localeCounters,
                    $snippetJob['sourceFile'],
                    $targetFile
                );

                // ---- Write translated data if not in dry run mode
                if (!$dryRun) {
                    if ($backup) {
                        $this->persister->backupFile($targetFile);
                    }
                    $this->persister->writeJsonFile($targetFile, $translatedPayload);
                }

                $summary['locales'][$targetLocale] = $localeCounters;
            }
        }

        return $summary;
    }

    /**
     * Recursively translates a node (array or string) from source to target language.
     * 
     * @param mixed $sourceNode The source node to translate
     * @param mixed $targetNode The existing target node for reference
     * @param DeeplTranslator $translator The translation service instance
     * @param string $sourceLang The source language code
     * @param string $targetLang The target language code
     * @param bool $force Whether to force translation of already translated content
     * @param bool $skipEmpty Whether to skip empty strings during translation
     * @param array &$counters Reference to counters for tracking translation statistics
     * @return mixed The translated node
     */
    private function translateNode(
        mixed $sourceNode,
        mixed $targetNode,
        DeeplTranslator $translator,
        string $sourceLang,
        string $targetLang,
        bool $force,
        bool $skipEmpty,
        array &$counters,
        string $sourceFile,
        string $targetFile,
        array $nodePath = []
    ): mixed {
        // ---- Handle array nodes
        if (is_array($sourceNode)) {
            if (!is_array($targetNode)) {
                $targetNode = [];
            }

            // ---- Handle list arrays
            if (array_is_list($sourceNode)) {
                $result = [];
                foreach ($sourceNode as $index => $sourceValue) {
                    $targetValue = $targetNode[$index] ?? null;
                    $result[$index] = $this->translateNode(
                        $sourceValue,
                        $targetValue,
                        $translator,
                        $sourceLang,
                        $targetLang,
                        $force,
                        $skipEmpty,
                        $counters,
                        $sourceFile,
                        $targetFile,
                        [...$nodePath, (string)$index]
                    );
                }

                return $result;
            }

            // ---- Handle associative arrays
            $result = $targetNode;
            foreach ($sourceNode as $key => $sourceValue) {
                $targetValue = $targetNode[$key] ?? null;
                $result[$key] = $this->translateNode(
                    $sourceValue,
                    $targetValue,
                    $translator,
                    $sourceLang,
                    $targetLang,
                    $force,
                    $skipEmpty,
                    $counters,
                    $sourceFile,
                    $targetFile,
                    [...$nodePath, (string)$key]
                );
            }

            return $result;
        }

        // ---- Handle string nodes
        $counters['scanned']++;

        if (is_string($sourceNode)) {
            $sourceText = trim($sourceNode);

            // ---- Skip empty strings if configured
            if ($sourceText === '') {
                if ($skipEmpty) {
                    $counters['skipped']++;
                    if (is_string($targetNode)) {
                        return $targetNode;
                    }
                }

                return $sourceNode;
            }

            // ---- Skip already translated content if not forcing
            if (is_string($targetNode) && $targetNode !== '' && !$force) {
                $counters['skipped']++;
                return $targetNode;
            }

            // ---- Perform translation
            try {
                $translated = $translator->translate($sourceNode, $sourceLang, $targetLang, [
                    'sourceLang' => $sourceLang,
                    'targetLang' => $targetLang,
                ]);
                $counters['translated']++;

                return $translated;
            } catch (\Throwable $exception) {
                $counters['errors']++;
                $counters['errorDetails'][] = [
                    'sourceFile' => $sourceFile,
                    'targetFile' => $targetFile,
                    'nodePath' => $this->formatNodePath($nodePath),
                    'message' => $exception->getMessage(),
                    'sourcePreview' => $this->truncateText($sourceNode, 120),
                ];

                if (is_string($targetNode) && $targetNode !== '') {
                    return $targetNode;
                }

                return $sourceNode;
            }
        }

        // ---- For non-string and non-array nodes, return target node if available
        if ($targetNode !== null) {
            return $targetNode;
        }

        return $sourceNode;
    }

    private function formatNodePath(array $segments): string
    {
        if (empty($segments)) {
            return '<root>';
        }

        return implode('.', $segments);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Converts a locale string to DeepL source language format.
     * 
     * @param string $locale The locale string (e.g., 'en-US')
     * @return string The language code in DeepL format (e.g., 'EN')
     */
    private function toDeepLSourceLanguage(string $locale): string
    {
        return strtoupper(substr($locale, 0, 2));
    }

    /**
     * Converts a locale string to DeepL target language format.
     * Handles special cases for certain locale variants.
     * 
     * @param string $locale The locale string (e.g., 'en-US')
     * @return string The language code in DeepL format (e.g., 'EN-US' for US English)
     */
    private function toDeepLTargetLanguage(string $locale): string
    {
        $normalizedLocale = strtoupper($locale);
        if (in_array($normalizedLocale, ['EN-GB', 'EN-US', 'PT-BR', 'PT-PT'], true)) {
            return $normalizedLocale;
        }

        return strtoupper(substr($normalizedLocale, 0, 2));
    }
}