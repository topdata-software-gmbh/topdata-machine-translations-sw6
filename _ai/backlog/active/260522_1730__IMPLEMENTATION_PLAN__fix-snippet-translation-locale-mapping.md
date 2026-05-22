### Diagnostic Analysis
When running the command with options `--from=de_DE --to=fr_CH`, the source locale is passed as `de_DE` (with an underscore) while the actual source file on disk is located at `topdata-better-checkout-sw6/src/Resources/snippet/de_DE/storefront.de-DE.json` (with a dash).

In `SnippetPluginDiscovery::mapPathToLocale`, the logic replaces the source locale with the target locale inside the file path. Because it replaces `de_DE` with `fr_CH`, the directory part `de_DE/` gets updated to `fr_CH/`. However, the filename contains `de-DE` (with a dash). Since `de_DE` does not match `de-DE`, the filename remains unmodified, resulting in `fr_CH/storefront.de-DE.json`.

To solve this, both the dash (`-`) and underscore (`_`) variants of the locales must be generated and mapped in parallel. This ensures that any directory or filename style is correctly matched and renamed. Additionally, we will integrate detailed read/write logging to provide the requested verbose output.

---

### Implementation Plan

```yaml
---
filename: "_ai/backlog/active/260522_1730__IMPLEMENTATION_PLAN__fix-snippet-translation-locale-mapping.md"
title: "Fix Locale Mapping in Snippet Translation and Enhance Verbose Logging"
createdAt: 2026-05-22 17:30
updatedAt: 2026-05-22 17:30
status: in-progress
priority: medium
tags: [translation, locale, bugfix, logging]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---
```

## 1. Problem Description
When executing `topdata:machine-translations:translate-snippets-json` with underscore-formatted locales (such as `--from=de_DE --to=fr_CH`), the resulting translations are stored under the correct directory (`fr_CH/`) but retain the original source locale in the filename (e.g., `storefront.de-DE.json`). Additionally, the console output is not verbose enough to let the user track which exact source files are being read and which target files are being written or backed up.

## 2. Executive Summary
This plan resolves the locale mapping bug by normalizing the search and replace patterns in `SnippetPluginDiscovery` to cover both dash (`-`) and underscore (`_`) formats regardless of how the CLI argument was formulated. It also improves `SnippetTranslationProcessor` to normalize target locales for DeepL, and introduces detailed `CliLogger` messages to output the paths of all read, backed up, and written snippet files.

## 3. Project Environment Details
- **Framework**: Shopware 6.7
- **Plugin Name**: `TopdataMachineTranslationsSW6`
- **Language**: PHP 8.1+
- **Database**: Doctrine DBAL / MySQL

---

## 4. Phased Implementation Steps

### Phase 1: Correcting Locale Mapping and Normalization
We will update `SnippetPluginDiscovery.php` to ensure that both dash and underscore variants of the locales are mapped and checked.

#### [MODIFY] `src/Service/SnippetPluginDiscovery.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

/**
 * Discovers and resolves snippet plugin paths and files for machine translation.
 * This class handles the discovery of plugins, collection of snippet files, 
 * and mapping between different locales.
 */
final class SnippetPluginDiscovery
{
    /**
     * Resolves plugin paths based on the provided root directory, plugin names, and a flag for all plugins.
     *
     * @param string $pluginsRoot The root directory where plugins are located
     * @param array $pluginNames Array of specific plugin names to resolve
     * @param bool $allPlugins Whether to resolve all plugins in the directory
     * @return array Array of resolved plugin paths with plugin names as keys
     * @throws \InvalidArgumentException If the plugins root directory does not exist
     */
    public function resolvePluginPaths(string $pluginsRoot, array $pluginNames, bool $allPlugins): array
    {
        // ---- Validate plugins root directory
        if (!is_dir($pluginsRoot)) {
            throw new \InvalidArgumentException(sprintf('Plugins root "%s" does not exist', $pluginsRoot));
        }

        // ---- Resolve all plugins in the directory
        if ($allPlugins) {
            $entries = scandir($pluginsRoot) ?: [];
            $resolved = [];

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $pluginPath = rtrim($pluginsRoot, '/\\') . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($pluginPath)) {
                    $resolved[$entry] = $pluginPath;
                }
            }

            ksort($resolved);

            return $resolved;
        }

        // ---- Resolve specific plugins by name
        $resolved = [];
        foreach ($pluginNames as $pluginName) {
            $pluginPath = rtrim($pluginsRoot, '/\\') . DIRECTORY_SEPARATOR . $pluginName;
            if (!is_dir($pluginPath)) {
                continue;
            }

            $resolved[$pluginName] = $pluginPath;
        }

        return $resolved;
    }

    /**
     * Resolves snippet files for a specific plugin and maps them to target locales.
     *
     * @param string $pluginPath Path to the plugin directory
     * @param string $fromLocale Source locale for the snippets
     * @param array $targetLocales Array of target locales to map to
     * @return array|null Array containing snippet file information or null if no files found
     */
    public function resolveSnippetFiles(string $pluginPath, string $fromLocale, array $targetLocales): ?array
    {
        // ---- Check if resources directory exists
        $resourcesDir = rtrim($pluginPath, '/\\') . '/src/Resources';
        if (!is_dir($resourcesDir)) {
            return null;
        }

        // ---- Collect source snippet files
        $sourceFiles = $this->collectSourceSnippetFiles($resourcesDir, $fromLocale);
        if (empty($sourceFiles)) {
            return null;
        }

        // ---- Create snippet jobs for target locales
        $snippetJobs = [];
        foreach ($sourceFiles as $sourceFile) {
            $targetFiles = [];
            foreach ($targetLocales as $targetLocale) {
                if (strcasecmp($targetLocale, $fromLocale) === 0) {
                    continue;
                }

                $targetFiles[$targetLocale] = $this->mapPathToLocale($sourceFile, $fromLocale, $targetLocale);
            }

            $snippetJobs[] = [
                'sourceFile' => $sourceFile,
                'targetFiles' => $targetFiles,
            ];
        }

        $firstSnippetDir = dirname($sourceFiles[0]);

        return [
            'pluginName' => basename($pluginPath),
            'pluginPath' => $pluginPath,
            'snippetDir' => $firstSnippetDir,
            'sourceFile' => $sourceFiles[0],
            'targetFiles' => $snippetJobs[0]['targetFiles'],
            'snippetJobs' => $snippetJobs,
        ];
    }

    /**
     * Collects all snippet files for a specific locale from the resources directory.
     *
     * @param string $resourcesDir Path to the resources directory
     * @param string $fromLocale Locale to collect snippets for
     * @return array Array of snippet file paths
     */
    private function collectSourceSnippetFiles(string $resourcesDir, string $fromLocale): array
    {
        // ---- Initialize file collection
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resourcesDir, \FilesystemIterator::SKIP_DOTS)
        );

        // ---- Iterate through all files in the resources directory
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            // ---- Check if file is a JSON snippet file
            if (strtolower($fileInfo->getExtension()) !== 'json') {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (!$this->isSnippetFilePath($path)) {
                continue;
            }

            if (!$this->pathMatchesLocale($path, $fromLocale)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Checks if a given path is a snippet file path.
     *
     * @param string $path File path to check
     * @return bool True if the path is a snippet file path
     */
    private function isSnippetFilePath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        return str_replace('/snippet/', '', $normalizedPath) !== $normalizedPath;
    }

    /**
     * Checks if a file path matches the specified locale.
     *
     * @param string $path File path to check
     * @param string $locale Locale to match against
     * @return bool True if the path matches the locale
     */
    private function pathMatchesLocale(string $path, string $locale): bool
    {
        // ---- Normalize path for consistent matching
        $normalizedPath = str_replace('\\', '/', $path);
        $dashLocale = str_replace('_', '-', $locale);
        $underscoreLocale = str_replace('-', '_', $locale);

        $basename = basename($normalizedPath);

        // ---- Define patterns to match locale in path
        $patterns = [
            '/' . preg_quote($dashLocale, '/') . '\\.json$/i',
            '/\\.' . preg_quote($dashLocale, '/') . '\\.json$/i',
            '/' . preg_quote($underscoreLocale, '/') . '\\.json$/i',
            '/\\.' . preg_quote($underscoreLocale, '/') . '\\.json$/i',
            '/\/' . preg_quote($dashLocale, '/') . '\//i',
            '/\/' . preg_quote($underscoreLocale, '/') . '\//i',
        ];

        // ---- Check if any pattern matches the path
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedPath) === 1 || preg_match($pattern, $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps a source file path to a target locale by replacing locale identifiers.
     *
     * @param string $path Source file path
     * @param string $fromLocale Source locale
     * @param string $toLocale Target locale
     * @return string Mapped file path for the target locale
     */
    private function mapPathToLocale(string $path, string $fromLocale, string $toLocale): string
    {
        $fromDash = str_replace('_', '-', $fromLocale);
        $fromUnderscore = str_replace('-', '_', $fromLocale);
        $toDash = str_replace('_', '-', $toLocale);
        $toUnderscore = str_replace('-', '_', $toLocale);

        return str_replace(
            [$fromDash, $fromUnderscore],
            [$toDash, $toUnderscore],
            $path
        );
    }
}
```

---

### Phase 2: Adding Detailed Logging and Target Locale Normalization
We will update `SnippetTranslationProcessor.php` to include verbose logging of source/target file operations and to ensure DeepL target locale codes are appropriately converted.

#### [MODIFY] `src/Service/SnippetTranslationProcessor.php`
```php
<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

use Topdata\TopdataFoundationSW6\Util\CliLogger;
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
            CliLogger::info(sprintf('Reading source snippet file: %s', $snippetJob['sourceFile']));
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

                CliLogger::info(sprintf('Reading target snippet file: %s', $targetFile));
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
                    if ($backup && file_exists($targetFile)) {
                        $backupPath = $this->persister->backupFile($targetFile);
                        if ($backupPath) {
                            CliLogger::info(sprintf('Created backup of target snippet file: %s', $backupPath));
                        }
                    }
                    CliLogger::info(sprintf('Writing updated target snippet file: %s', $targetFile));
                    $this->persister->writeJsonFile($targetFile, $translatedPayload);
                } else {
                    CliLogger::info(sprintf('[Dry-run] Would write updated target snippet file: %s', $targetFile));
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
        $normalizedLocale = str_replace('_', '-', strtoupper($locale));
        if (in_array($normalizedLocale, ['EN-GB', 'EN-US', 'PT-BR', 'PT-PT'], true)) {
            return $normalizedLocale;
        }

        return strtoupper(substr($normalizedLocale, 0, 2));
    }
}
```

---

### Phase 3: Updating Documentation
We will update user manuals in English and German to inform developers and administrators of the locale parameter flexibility.

#### [MODIFY] `manual/36-snippet-translation.en.md`
```markdown
## Behavior Details

- Source file: `<plugin>/src/Resources/snippet/<from>.json`
- Target files: `<plugin>/src/Resources/snippet/<to>.json`
- Both dash (`de-DE`) and underscore (`de_DE`) formats are supported for the `--from` and `--to` parameters. The command maps and converts them matching the filenames and directory structures accordingly.
- Existing non-empty target values are kept by default
- Nested JSON key structure is preserved recursively
- Backups are created for existing target files unless `--no-backup` is set
- If source snippet file is missing, the plugin is skipped and listed in summary
```

#### [MODIFY] `manual/36-snippet-translation.de.md`
```markdown
## Verhaltensdetails

- Quelldatei: `<plugin>/src/Resources/snippet/<from>.json`
- Zieldateien: `<plugin>/src/Resources/snippet/<to>.json`
- Sowohl das Bindestrich-Format (`de-DE`) als auch das Unterstrich-Format (`de_DE`) werden für die Parameter `--from` und `--to` unterstützt. Der Befehl mappt und konvertiert diese passend zu den Dateinamen und Ordnerstrukturen.
- Bestehende, nicht-leere Zielwerte bleiben standardmäßig erhalten
- Verschachtelte JSON-Key-Struktur wird rekursiv beibehalten
- Für bestehende Zieldateien werden Sicherungen erstellt, außer bei `--no-backup`
- Fehlt die Quell-Snippet-Datei, wird das Plugin übersprungen und in der Zusammenfassung angezeigt
```

---

## 5. Implementation Verification & Testing
1. Run the snippet translator with underscore locales:
   `bin/console topdata:machine-translations:translate-snippets-json --plugin=topdata-better-checkout-sw6 --from=de_DE --to=fr_CH`
2. Verify that:
   - The directory created matches `fr_CH` or `fr-CH` matching the source structure.
   - The file itself is written to `storefront.fr-CH.json` (correctly mapping the dashes).
   - Console outputs include explicit entries indicating which source and target files were read, backed up, and updated.

---

### Implementation Report

```yaml
---
filename: "_ai/backlog/reports/260522_1730__IMPLEMENTATION_REPORT__fix-snippet-translation-locale-mapping.md"
title: "Report: Fix Locale Mapping in Snippet Translation and Enhance Verbose Logging"
createdAt: 2026-05-22 17:30
updatedAt: 2026-05-22 17:30
planFile: "_ai/backlog/active/260522_1730__IMPLEMENTATION_PLAN__fix-snippet-translation-locale-mapping.md"
project: "TopdataMachineTranslationsSW6"
status: completed
filesCreated: 0
filesModified: 4
filesDeleted: 0
tags: [translation, locale, bugfix, logging]
documentType: IMPLEMENTATION_REPORT
---
```

### 1. Summary
The bug in the snippet translation mapping has been resolved. The translation command now correctly supports both underscore (`de_DE`) and dash (`de-DE`) format inputs across filenames and directories. Verbose logging has been added to output the paths of source, backup, and target files during translation.

### 2. Files Changed
- **Modified**:
  - `src/Service/SnippetPluginDiscovery.php`: Corrected mapping algorithms to replace both dash and underscore versions of locales in files and paths.
  - `src/Service/SnippetTranslationProcessor.php`: Enhanced with extensive logging to show which source and target files are read and written, and fixed DeepL target locale target resolution.
  - `manual/36-snippet-translation.en.md`: Added behavioral details regarding flexible locale formats.
  - `manual/36-snippet-translation.de.md`: Translated behavioral updates into the German guide.

### 3. Key Changes
- Modified `SnippetPluginDiscovery::mapPathToLocale` and `SnippetPluginDiscovery::pathMatchesLocale` to generate and apply replacements using both `_` and `-` variants of the input parameters.
- Integrated `CliLogger` inside `SnippetTranslationProcessor` to write file-level tracking messages in real-time.
- Standardized DeepL API locale inputs in `SnippetTranslationProcessor::toDeepLTargetLanguage` to replace underscores with dashes prior to checking specialized country tags (e.g., `EN-US`, `PT-BR`).

### 4. Deviations from Plan
None. All components were updated according to the planned approach.

### 5. Technical Decisions
By normalizing strings using both underscore and dash versions inside replacement arrays, we avoided rigid formatting rules. This keeps the codebase resilient without demanding strict input sanitization from the user on CLI level.

### 6. Testing Notes
Verify by calling:
`bin/console topdata:machine-translations:translate-snippets-json --plugin=topdata-better-checkout-sw6 --from=de_DE --to=fr_CH`

You should see logs indicating:
- `Reading source snippet file: .../snippet/de_DE/storefront.de-DE.json`
- `Reading target snippet file: .../snippet/fr_CH/storefront.fr-CH.json`
- `Created backup of target snippet file: ...` (if file previously existed)
- `Writing updated target snippet file: .../snippet/fr_CH/storefront.fr-CH.json`

### 7. Documentation Updates
Both `manual/36-snippet-translation.en.md` and `manual/36-snippet-translation.de.md` are updated to document support for mixed formats.

