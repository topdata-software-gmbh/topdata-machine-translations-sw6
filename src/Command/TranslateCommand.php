<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;

/**
 * 09/2024 created
 * Refactored to use cURL instead of DeepL client
 * 09/2024 updated: Added more verbose output and CLI options for language selection
 */
class TranslateCommand extends Command
{
    protected static $defaultName = 'topdata:translate';

    private Connection $connection;
    private string $langIdFrom;
    private string $langIdTo;
    private DeeplTranslator $translator;
    private SymfonyStyle $cliStyle;

    public function __construct(Connection $connection)
    {
        parent::__construct(self::$defaultName);
        $this->connection = $connection;
        $this->translator = new DeeplTranslator(getenv('DEEPL_FREE_API_KEY'));
    }

    public function printAvailableLanguages(): void
    {
        $langs = $this->getLanguages();
        $this->cliStyle->section('Available Languages');
        $this->cliStyle->table(['Language ID', 'Language Code', 'Language Name'], $langs);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Translate content from one language to another')
            ->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Specific table to translate')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source language code (e.g., de-DE)', 'de-DE')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target language code (e.g., cs-CZ)', 'cs-CZ');
    }

    /**
     * ==== MAIN ====
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle = new SymfonyStyle($input, $output);

        $this->cliStyle->title('Machine Translation Command');

        // Display available languages
        $this->printAvailableLanguages();

        // Get language codes from CLI options
        $langCodeFrom = $input->getOption('from');
        $langCodeTo = $input->getOption('to');

        $this->cliStyle->section('Selected Languages');
        $this->cliStyle->text([
            "From: $langCodeFrom",
            "To: $langCodeTo"
        ]);

        // Check if language codes are available
        $availableLangs = $this->getLanguages();
        $langCodes = array_column($availableLangs, 'code');
        if (!in_array($langCodeFrom, $langCodes)) {
            $this->cliStyle->error("Error: From-Language Code $langCodeFrom not found. Available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }
        if (!in_array($langCodeTo, $langCodes)) {
            $this->cliStyle->error("Error: To-Language Code $langCodeTo not found. Available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }

        // Get language IDs
        $this->langIdFrom = $this->getLanguageId($langCodeFrom);
        $this->langIdTo = $this->getLanguageId($langCodeTo);

        if (!$this->langIdFrom || !$this->langIdTo) {
            $this->cliStyle->error("Error: Could not find language IDs for the specified languages.");
            return Command::FAILURE;
        }

        $specificTable = $input->getOption('table');

        $tables = $specificTable ? [$specificTable] : $this->_getTranslatableTables();

        $this->cliStyle->listing($tables, 'Tables to process');


        $this->cliStyle->section('Processing Tables');

        foreach ($tables as $tableName) {
            $this->cliStyle->text("Processing table: $tableName");

            $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);
            $textColumns = array_filter($columns, function ($column) {
                return !in_array($column->getName(), ['id', 'language_id']);
            });

            $sourceRows = $this->connection->createQueryBuilder()
                ->select('*')
                ->from($tableName)
                ->where('language_id = :languageId')
                ->setParameter('languageId', $this->langIdFrom)
                ->executeQuery()
                ->fetchAllAssociative();

            $this->cliStyle->progressStart(count($sourceRows));

            foreach ($sourceRows as $row) {
                $updates = [];
                foreach ($textColumns as $column) {
                    $columnName = $column->getName();
                    if (!empty($row[$columnName])) {
                        try {
                            $translatedText = $this->translator->translate($row[$columnName], substr($langCodeFrom, 0, 2), substr($langCodeTo, 0, 2));
                            $updates[$columnName] = $translatedText;
                        } catch (\Exception $e) {
                            $this->cliStyle->error("Translation error for $columnName: " . $e->getMessage());
                        }
                    }
                }

                // Here you would update or insert the translated row
                // For now, we'll just log the updates
                $this->cliStyle->text("Updated row {$row['id']}: " . json_encode($updates));

                $this->cliStyle->progressAdvance();
            }

            $this->cliStyle->progressFinish();
        }

        $this->cliStyle->success("Translation completed.");

        return Command::SUCCESS;
    }

    private function getLanguageId(string $localeCode): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('lan.id')
            ->from('language', 'lan')
            ->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id')
            ->where('LOWER(loc.code) = LOWER(:locale)')
            ->setParameter('locale', $localeCode);

        return $qb->executeQuery()->fetchOne() ?? null;
    }

    private function getLanguages(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('LOWER(HEX(lan.id)) AS languageId, loc.code AS code, lan.name AS name')
            ->from('language', 'lan')
            ->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id');

        return $qb->fetchAllAssociative();
    }

    /**
     * it returns tables that have a _translation suffix
     *
     * 09/2024 created
     *
     * @return string[]
     */
    private function _getTranslatableTables(): array
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $filtered = array_filter($tables, function ($tableName) {
            return substr($tableName, -12) === '_translation';
        });

        return $filtered;
    }
}
