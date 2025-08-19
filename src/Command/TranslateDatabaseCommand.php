<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;
use Topdata\TopdataMachineTranslationsSW6\Helper\TableBackuper;
use Topdata\TopdataMachineTranslationsSW6\Helper\TableTranslator;

/**
 * 09/2024 created
 */
class TranslateDatabaseCommand extends Command
{
    protected static $defaultName = 'topdata:machine-translations:translate-database';

    private Connection $connection;
    private DeeplTranslator $deeplTranslator;

    public function __construct(Connection $connection)
    {
        parent::__construct(self::$defaultName);
        $this->connection = $connection;
    }

    protected function configure(): void
    {
        $this->setDescription('Translate content from one language to another');
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific table to translate');
        $this->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source language code (e.g., de-DE)', 'de-DE');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target language code (e.g., cs-CZ)', 'cs-CZ');
        $this->addOption('--no-backup', null, InputOption::VALUE_NONE, 'Do not create a backup of the original data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- init
        assert(getenv('DEEPL_FREE_API_KEY'), 'DEEPL_FREE_API_KEY is missing');
        $this->deeplTranslator = new DeeplTranslator(getenv('DEEPL_FREE_API_KEY'));
        $databaseUrl = $this->connection->getParams()['url'];

        CliLogger::title('Machine Translation Command');

        $this->printAvailableLanguages();

        $langCodeFrom = $input->getOption('from');
        $langCodeTo = $input->getOption('to');
        $sourceLang = substr($langCodeFrom, 0, 2);
        $targetLang = substr($langCodeTo, 0, 2);

        CliLogger::section('Selected Languages');
        CliLogger::writeln("
DB:   $databaseUrl
From: $langCodeFrom
To:   $langCodeTo
        ");

        // ----
        $availableLangs = $this->getLanguages();
        $langCodes = array_column($availableLangs, 'code');
        if (!in_array($langCodeFrom, $langCodes) || !in_array($langCodeTo, $langCodes)) {
            CliLogger::error("Error: Invalid language codes. Available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }

        $langIdFrom = $this->getLanguageId($langCodeFrom);
        $langIdTo = $this->getLanguageId($langCodeTo);

        if (!$langIdFrom || !$langIdTo) {
            CliLogger::error("Error: Could not find language IDs for the specified languages.");
            return Command::FAILURE;
        }

        $specificTables = $input->getOption('table');
        $tables = $this->getTablesForProcessing($specificTables);

        if (empty($tables)) {
            CliLogger::error("No tables to process.");
            return Command::FAILURE;
        }

        CliLogger::section('Processing Tables');
        $tableBackuper = new TableBackuper($databaseUrl, CliLogger::getCliStyle());
        $tableTranslator = new TableTranslator($this->connection, $this->deeplTranslator, CliLogger::getCliStyle());

        foreach ($tables as $tableName) {
            if($input->getOption('no-backup')) {
               CliLogger::info("Skipping backup for table: $tableName");
            } else {
                $tableBackuper->backupTable($tableName);
            }
            $tableTranslator->translateTable($tableName, $langIdFrom, $langIdTo, $sourceLang, $targetLang);
        }

        CliLogger::success("Translation completed.");

        return Command::SUCCESS;
    }

    private function printAvailableLanguages(): void
    {
        $langs = $this->getLanguages();
        CliLogger::section('Available Languages');
        CliLogger::getCliStyle()->table(['Language ID', 'Language Code', 'Language Name'], $langs);
    }

    private function getLanguageId(string $localeCode): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('lan.id')
            ->from('language', 'lan')
            ->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id')
            ->where('LOWER(loc.code) = LOWER(:locale)')
            ->setParameter('locale', $localeCode);

        return $qb->execute()->fetchOne() ?? null;
    }

    private function getLanguages(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('LOWER(HEX(lan.id)) AS languageId, loc.code AS code, lan.name AS name')
            ->from('language', 'lan')
            ->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id');

        return $qb->execute()->fetchAllAssociative();
    }

    private function getTablesForProcessing(array $specificTables): array
    {
        if (!empty($specificTables)) {
            CliLogger::writeln("Specific table: " . implode(", ", $specificTables));
            $translatableTables = $this->getTranslatableTables();
            $tables = array_intersect($specificTables, $translatableTables);
            if (count($tables) !== count($specificTables)) {
                CliLogger::warning("Some specified tables are not translatable and will be skipped.");
            }
        } else {
            $tables = $this->getTranslatableTables();
            CliLogger::getCliStyle()->table(['table name'], array_map(fn($x) => [$x], $tables));
            if (!CliLogger::getCliStyle()->confirm("Do you want to process ALL tables?")) {
                return [];
            }
        }

        CliLogger::getCliStyle()->listing($tables, 'Tables to process');
        return $tables;
    }

    private function getTranslatableTables(): array
    {
        $tables = $this->connection->getSchemaManager()->listTableNames();
        return array_filter($tables, function ($tableName) {
            return substr($tableName, -strlen('_translation')) === '_translation';
        });
    }
}