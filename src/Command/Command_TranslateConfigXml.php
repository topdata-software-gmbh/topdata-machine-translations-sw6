<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;

/**
 * 08/2025 created
 */
#[AsCommand(
    name: 'topdata:machine-translations:translate-config-xml',
    description: 'Translate configuration XML files using DeepL'
)]
class Command_TranslateConfigXml extends AbstractTopdataCommand
{
    private DeeplTranslator $deeplTranslator;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the XML file to translate')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source language code (e.g., de-DE)')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target language code(s) (e.g., cs-CZ)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force translation even if file already exists')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create a backup of the original file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Initialize CLI logger
        CliLogger::setCliStyle($this->cliStyle);

        // Get arguments and options
        $file = $input->getArgument('file');
        $from = $input->getOption('from');
        $to = $input->getOption('to');
        $force = $input->getOption('force');
        $noBackup = $input->getOption('no-backup');

        // Validate DEEPL_FREE_API_KEY
        if (!getenv('DEEPL_FREE_API_KEY')) {
            CliLogger::error('DEEPL_FREE_API_KEY environment variable is missing');
            return Command::FAILURE;
        }

        // Validate file existence
        if (!file_exists($file)) {
            CliLogger::error(sprintf('File "%s" does not exist', $file));
            return Command::FAILURE;
        }

        // Create backup unless --no-backup flag is set
        if (!$noBackup) {
            $backupFile = $file . '.backup.' . date('Y-m-d_H-i-s');
            if (!copy($file, $backupFile)) {
                CliLogger::error(sprintf('Failed to create backup file "%s"', $backupFile));
                return Command::FAILURE;
            }
            CliLogger::info(sprintf('Backing up file to: %s', $backupFile));
        }

        // Load XML with DOMDocument
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = true;

        if (!$dom->load($file)) {
            CliLogger::error(sprintf('Failed to load XML file "%s"', $file));
            return Command::FAILURE;
        }

        // Use DOMXPath to find translatable nodes
        $xpath = new \DOMXPath($dom);
        $translatableNodes = $xpath->query('//title|//label|//helpText|//resetOption');

        if ($translatableNodes->length === 0) {
            CliLogger::info('No translatable nodes found in XML file');
            return Command::SUCCESS;
        }

        CliLogger::info(sprintf('Found %d translatable nodes', $translatableNodes->length));

        // Iterate target languages
        foreach ($to as $targetLanguage) {
            CliLogger::info(sprintf('Processing language: %s', $targetLanguage));

            $translationsCount = 0;

            // Process each translatable node
            foreach ($translatableNodes as $node) {
                // Find source text
                $sourceText = null;

                // Check for child node with --from lang
                $fromNodes = $xpath->query('./text[@lang="' . $from . '"]', $node);
                if ($fromNodes->length > 0) {
                    $sourceText = trim($fromNodes->item(0)->textContent);
                } else {
                    // Use main node text if no lang-specific child found
                    $sourceText = trim($node->textContent);
                }

                if (empty($sourceText)) {
                    continue;
                }

                // Check existing --to translation
                $existingTranslations = $xpath->query('./text[@lang="' . $targetLanguage . '"]', $node);
                if ($existingTranslations->length > 0 && !$force) {
                    CliLogger::debug(sprintf('Skipping existing translation for "%s" in %s', $sourceText, $targetLanguage));
                    continue;
                }

                // ----  init DeeplTranslator
                assert(getenv('DEEPL_FREE_API_KEY'), 'DEEPL_FREE_API_KEY is missing');
                $this->deeplTranslator = new DeeplTranslator(getenv('DEEPL_FREE_API_KEY'));

                // Translate via DeeplTranslator
                try {
                    $translatedText = $this->deeplTranslator->translate($sourceText, $from, $targetLanguage);

                    if (empty($translatedText)) {
                        CliLogger::warning(sprintf('Empty translation returned for "%s"', $sourceText));
                        continue;
                    }

                    // Create new element with translated text and lang attribute
                    $textElement = $dom->createElement('text', $translatedText);
                    $textElement->setAttribute('lang', $targetLanguage);

                    // Remove existing translation if force mode
                    if ($existingTranslations->length > 0 && $force) {
                        $node->removeChild($existingTranslations->item(0));
                    }

                    // Append to parent node
                    $node->appendChild($textElement);
                    $translationsCount++;

                    CliLogger::debug(sprintf('Translated "%s" to %s: "%s"', $sourceText, $targetLanguage, $translatedText));

                } catch (\Exception $e) {
                    CliLogger::error(sprintf('Translation failed for "%s": %s', $sourceText, $e->getMessage()));
                    continue;
                }
            }

            CliLogger::info(sprintf('Added %d translations for language %s', $translationsCount, $targetLanguage));
        }

        // Save modified DOMDocument
        if (!$dom->save($file)) {
            CliLogger::error(sprintf('Failed to save XML file "%s"', $file));
            return Command::FAILURE;
        }

        CliLogger::info(sprintf('Successfully updated XML file: %s', $file));

        return Command::SUCCESS;
    }
}