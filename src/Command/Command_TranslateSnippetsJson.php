<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;
use Topdata\TopdataMachineTranslationsSW6\Service\SnippetPluginDiscovery;
use Topdata\TopdataMachineTranslationsSW6\Service\SnippetTranslationProcessor;

#[AsCommand(
    name: 'topdata:machine-translations:translate-snippets-json',
    description: 'Translate snippet JSON files for one or more plugins using DeepL'
)]
class Command_TranslateSnippetsJson extends AbstractTopdataCommand
{
    public function __construct(
        private readonly SnippetPluginDiscovery $discovery,
        private readonly SnippetTranslationProcessor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plugin', 'p', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Plugin technical name(s)')
            ->addOption('all-plugins', null, InputOption::VALUE_NONE, 'Process all plugins in plugins root')
            ->addOption('plugins-root', null, InputOption::VALUE_REQUIRED, 'Plugins root directory', '/www/custom/plugins')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source locale, e.g. de-DE')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target locale(s), e.g. en-GB')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing target values')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing files')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create backup files')
            ->addOption('skip-empty', null, InputOption::VALUE_NONE, 'Do not send empty source values to translator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        CliLogger::setCliStyle($this->cliStyle);

        if (!getenv('DEEPL_FREE_API_KEY')) {
            CliLogger::error('DEEPL_FREE_API_KEY environment variable is missing');
            return Command::FAILURE;
        }

        $allPlugins = (bool)$input->getOption('all-plugins');
        $pluginNames = $this->normalizeListOption((array)$input->getOption('plugin'));
        if (($allPlugins && !empty($pluginNames)) || (!$allPlugins && empty($pluginNames))) {
            CliLogger::error('Use exactly one mode: either --plugin=<name> or --all-plugins');
            return Command::FAILURE;
        }

        $fromLocale = trim((string)$input->getOption('from'));
        if ($fromLocale === '') {
            CliLogger::error('Option --from is required');
            return Command::FAILURE;
        }

        $targetLocales = $this->normalizeListOption((array)$input->getOption('to'));
        if (empty($targetLocales)) {
            CliLogger::error('Option --to is required at least once');
            return Command::FAILURE;
        }

        $pluginsRoot = trim((string)$input->getOption('plugins-root'));
        $force = (bool)$input->getOption('force');
        $dryRun = (bool)$input->getOption('dry-run');
        $backup = !$input->getOption('no-backup');
        $skipEmpty = (bool)$input->getOption('skip-empty');

        CliLogger::title('Snippet JSON Translation');
        CliLogger::section('Configuration');
        CliLogger::writeln(sprintf('Plugins root: %s', $pluginsRoot));
        CliLogger::writeln(sprintf('Mode: %s', $allPlugins ? 'all plugins' : 'selected plugins'));
        CliLogger::writeln(sprintf('Source locale: %s', $fromLocale));
        CliLogger::writeln(sprintf('Target locales: %s', implode(', ', $targetLocales)));
        CliLogger::writeln(sprintf('Force mode: %s', $force ? 'yes' : 'no'));
        CliLogger::writeln(sprintf('Dry-run: %s', $dryRun ? 'yes' : 'no'));

        try {
            $pluginPaths = $this->discovery->resolvePluginPaths($pluginsRoot, $pluginNames, $allPlugins);
        } catch (\Throwable $exception) {
            CliLogger::error($exception->getMessage());
            return Command::FAILURE;
        }

        if (!$allPlugins && count($pluginPaths) !== count($pluginNames)) {
            $missingPlugins = array_values(array_diff($pluginNames, array_keys($pluginPaths)));
            if (!empty($missingPlugins)) {
                CliLogger::warning(sprintf('Plugins not found and skipped: %s', implode(', ', $missingPlugins)));
            }
        }

        if (empty($pluginPaths)) {
            CliLogger::error('No plugin directories found to process');
            return Command::FAILURE;
        }

        $apiKey = (string)getenv('DEEPL_FREE_API_KEY');
        $translator = new DeeplTranslator($apiKey);

        $processedPlugins = 0;
        $failedPlugins = 0;
        $summaryRows = [];

        foreach ($pluginPaths as $pluginName => $pluginPath) {
            CliLogger::section(sprintf('Plugin: %s', $pluginName));

            $snippetMeta = $this->discovery->resolveSnippetFiles($pluginPath, $fromLocale, $targetLocales);
            if ($snippetMeta === null) {
                CliLogger::warning(sprintf('Missing snippet directory or source file (%s.json), plugin skipped', $fromLocale));
                $failedPlugins++;
                $summaryRows[] = [$pluginName, 0, 0, 0, 1, 'skipped'];
                continue;
            }

            if (empty($snippetMeta['targetFiles'])) {
                CliLogger::warning('No target locales to process after filtering source locale');
                $failedPlugins++;
                $summaryRows[] = [$pluginName, 0, 0, 0, 1, 'skipped'];
                continue;
            }

            try {
                $pluginResult = $this->processor->processPluginSnippets(
                    $snippetMeta,
                    $translator,
                    $fromLocale,
                    $force,
                    $dryRun,
                    $backup,
                    $skipEmpty
                );

                $scanned = 0;
                $translated = 0;
                $skipped = 0;
                $errors = 0;

                foreach ($pluginResult['locales'] as $targetLocale => $stats) {
                    $scanned += $stats['scanned'];
                    $translated += $stats['translated'];
                    $skipped += $stats['skipped'];
                    $errors += $stats['errors'];

                    CliLogger::info(sprintf(
                        '[%s] scanned=%d translated=%d skipped=%d errors=%d',
                        $targetLocale,
                        $stats['scanned'],
                        $stats['translated'],
                        $stats['skipped'],
                        $stats['errors']
                    ));
                }

                $processedPlugins++;
                $summaryRows[] = [$pluginName, $scanned, $translated, $skipped, $errors, 'ok'];
            } catch (\Throwable $exception) {
                $failedPlugins++;
                $summaryRows[] = [$pluginName, 0, 0, 0, 1, 'failed'];
                CliLogger::error(sprintf('Processing failed: %s', $exception->getMessage()));
            }
        }

        CliLogger::section('Summary');
        CliLogger::getCliStyle()->table(
            ['Plugin', 'Scanned', 'Translated', 'Skipped', 'Errors', 'Status'],
            $summaryRows
        );
        CliLogger::writeln(sprintf('Processed plugins: %d', $processedPlugins));
        CliLogger::writeln(sprintf('Failed/skipped plugins: %d', $failedPlugins));

        if ($processedPlugins === 0) {
            CliLogger::error('All plugin jobs failed or were skipped');
            return Command::FAILURE;
        }

        CliLogger::success('Snippet translation finished');
        return Command::SUCCESS;
    }

    private function normalizeListOption(array $rawValues): array
    {
        $values = [];

        foreach ($rawValues as $rawValue) {
            if (!is_string($rawValue)) {
                continue;
            }

            $parts = explode(',', $rawValue);
            foreach ($parts as $part) {
                $normalized = trim($part);
                if ($normalized === '') {
                    continue;
                }
                $values[] = $normalized;
            }
        }

        $values = array_values(array_unique($values));

        return $values;
    }
}
