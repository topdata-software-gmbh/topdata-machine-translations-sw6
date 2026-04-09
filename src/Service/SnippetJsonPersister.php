<?php

namespace Topdata\TopdataMachineTranslationsSW6\Service;

final class SnippetJsonPersister
{
    public function readJsonFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read JSON file "%s"', $path));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Invalid JSON in "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('JSON root in "%s" must be an object or array', $path));
        }

        return $decoded;
    }

    public function backupFile(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $backupPath = $path . '.backup.' . date('Y-m-d_H-i-s');
        if (!copy($path, $backupPath)) {
            throw new \RuntimeException(sprintf('Failed to create backup file "%s"', $backupPath));
        }

        return $backupPath;
    }

    public function writeJsonFile(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s"', $directory));
        }

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Failed to encode JSON for "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        $tmpPath = $path . '.tmp.' . uniqid('', true);
        $bytes = file_put_contents($tmpPath, $json . PHP_EOL);
        if ($bytes === false) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('Failed to write temporary file "%s"', $tmpPath));
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(sprintf('Failed to replace JSON file "%s"', $path));
        }
    }
}
