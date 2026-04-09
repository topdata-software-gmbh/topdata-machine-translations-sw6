<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

final class SnippetPluginDiscovery
{
    public function resolvePluginPaths(string $pluginsRoot, array $pluginNames, bool $allPlugins): array
    {
        if (!is_dir($pluginsRoot)) {
            throw new \InvalidArgumentException(sprintf('Plugins root "%s" does not exist', $pluginsRoot));
        }

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

    public function resolveSnippetFiles(string $pluginPath, string $fromLocale, array $targetLocales): ?array
    {
        $resourcesDir = rtrim($pluginPath, '/\\') . '/src/Resources';
        if (!is_dir($resourcesDir)) {
            return null;
        }

        $sourceFiles = $this->collectSourceSnippetFiles($resourcesDir, $fromLocale);
        if (empty($sourceFiles)) {
            return null;
        }

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

    private function collectSourceSnippetFiles(string $resourcesDir, string $fromLocale): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resourcesDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

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

    private function isSnippetFilePath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        return strpos($normalizedPath, '/snippet/') !== false;
    }

    private function pathMatchesLocale(string $path, string $locale): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $dashLocale = $locale;
        $underscoreLocale = str_replace('-', '_', $locale);

        $basename = basename($normalizedPath);

        $patterns = [
            '/' . preg_quote($dashLocale, '/') . '\\.json$/i',
            '/\\.' . preg_quote($dashLocale, '/') . '\\.json$/i',
            '/' . preg_quote($underscoreLocale, '/') . '\\.json$/i',
            '/\\.' . preg_quote($underscoreLocale, '/') . '\\.json$/i',
            '/\/' . preg_quote($dashLocale, '/') . '\//i',
            '/\/' . preg_quote($underscoreLocale, '/') . '\//i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedPath) === 1 || preg_match($pattern, $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    private function mapPathToLocale(string $path, string $fromLocale, string $toLocale): string
    {
        $fromDash = $fromLocale;
        $fromUnderscore = str_replace('-', '_', $fromLocale);
        $toDash = $toLocale;
        $toUnderscore = str_replace('-', '_', $toLocale);

        return str_replace(
            [$fromDash, $fromUnderscore],
            [$toDash, $toUnderscore],
            $path
        );
    }
}
