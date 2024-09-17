<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;
use Topdata\TopdataMachineTranslationsSW6\Helper\TableBackuper;
use Topdata\TopdataMachineTranslationsSW6\Helper\TableTranslator;

class TranslateCommand extends Command
{
    protected static $defaultName = 'topdata:translate';

    private Connection $connection;
    private DeeplTranslator $deeplTranslator;
    private SymfonyStyle $cliStyle;

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
        $this->cliStyle = new SymfonyStyle($input, $output);
        assert(getenv('DEEPL_FREE_API_KEY'), 'DEEPL_FREE_API_KEY is missing');
        $this->deeplTranslator = new DeeplTranslator(getenv('DEEPL_FREE_API_KEY'));
        $databaseUrl = $this->connection->getParams()['url'];

        $this->cliStyle->title('Machine Translation Command');

        $this->printAvailableLanguages();

        $langCodeFrom = $input->getOption('from');
        $langCodeTo = $input->getOption('to');
        $sourceLang = substr($langCodeFrom, 0, 2);
        $targetLang = substr($langCodeTo, 0, 2);

        $this->cliStyle->section('Selected Languages');
        $this->cliStyle->text([
            "DB:   $databaseUrl",
            "From: $langCodeFrom",
            "To:   $langCodeTo"
        ]);

        // ----
        $availableLangs = $this->getLanguages();
        $langCodes = array_column($availableLangs, 'code');
        if (!in_array($langCodeFrom, $langCodes) || !in_array($langCodeTo, $langCodes)) {
            $this->cliStyle->error("Error: Invalid language codes. Available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }

        $langIdFrom = $this->getLanguageId($langCodeFrom);
        $langIdTo = $this->getLanguageId($langCodeTo);

        if (!$langIdFrom || !$langIdTo) {
            $this->cliStyle->error("Error: Could not find language IDs for the specified languages.");
            return Command::FAILURE;
        }

        $specificTables = $input->getOption('table');
        $tables = $this->getTablesForProcessing($specificTables);

        if (empty($tables)) {
            $this->cliStyle->error("No tables to process.");
            return Command::FAILURE;
        }

        $this->cliStyle->section('Processing Tables');
        $tableBackuper = new TableBackuper($databaseUrl, $this->cliStyle);
        $tableTranslator = new TableTranslator($this->connection, $this->deeplTranslator, $this->cliStyle);

        foreach ($tables as $tableName) {
            if($input->getOption('no-backup')) {
               $this->cliStyle->info("Skipping backup for table: $tableName");
            } else {
                $tableBackuper->backupTable($tableName);
            }
            $tableTranslator->translateTable($tableName, $langIdFrom, $langIdTo, $sourceLang, $targetLang);
        }

        $this->cliStyle->success("Translation completed.");

        return Command::SUCCESS;
    }

    private function printAvailableLanguages(): void
    {
        $langs = $this->getLanguages();
        $this->cliStyle->section('Available Languages');
        $this->cliStyle->table(['Language ID', 'Language Code', 'Language Name'], $langs);
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
            $this->cliStyle->text("Specific table: " . implode(", ", $specificTables));
            $translatableTables = $this->getTranslatableTables();
            $tables = array_intersect($specificTables, $translatableTables);
            if (count($tables) !== count($specificTables)) {
                $this->cliStyle->warning("Some specified tables are not translatable and will be skipped.");
            }
        } else {
            $tables = $this->getTranslatableTables();
            $this->cliStyle->table(['table name'], array_map(fn($x) => [$x], $tables));
            if (!$this->cliStyle->confirm("Do you want to process ALL tables?")) {
                return [];
            }
        }

        $this->cliStyle->listing($tables, 'Tables to process');
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