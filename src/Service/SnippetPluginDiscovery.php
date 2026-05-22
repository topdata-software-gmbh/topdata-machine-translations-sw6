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
        return strpos($normalizedPath, '/snippet/') !== false;
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