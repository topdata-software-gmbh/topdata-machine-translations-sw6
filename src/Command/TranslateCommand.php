<?php

namespace Topdata\TopdataMachineTranslationsSW6\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataMachineTranslationsSW6\Helper\DeeplTranslator;
use Topdata\TopdataQueueHelperSW6\Util\UtilDebug;


/**
 * 09/2024 created
 * Refactored to use cURL instead of DeepL client
 * 09/2024 updated: Added more verbose output and CLI options for language selection
 */
class TranslateCommand extends Command
{
    const TABLE_SUFFIX_TRANSLATION = '_translation';
    protected static $defaultName = 'topdata:translate';

    private Connection $connection;
    private string $langIdFrom;
    private string $langIdTo;
    private DeeplTranslator $deeplTranslator;
    private SymfonyStyle $cliStyle;

    public function __construct(Connection $connection)
    {
        parent::__construct(self::$defaultName);
        $this->connection = $connection;
    }

    /**
     * 09/2024 created
     *
     * @param string $tableName eg state_machine_state_translation
     * @return string eg state_machine_state_id
     */
    private function _getParentTableReferenceColumnName(string $tableName): string
    {
        $parentTableName = substr($tableName, 0, -strlen(self::TABLE_SUFFIX_TRANSLATION));
        return $parentTableName . '_id';
    }

    /**
     * private helper. Processes a single table
     */
    private function _processTable(string $tableName, string $sourceLang, string $targetLang): void
    {
        $this->cliStyle->info("Processing table: $tableName");

        $textColumns = $this->_getTextColumnNames($tableName);

        $sourceRows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $this->langIdFrom)
            ->execute()
            ->fetchAllAssociative();

        // ---- we fetch dest rows to avoid overwriting existing translations
        $destRows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $this->langIdTo)
            ->execute()
            ->fetchAllAssociative();
        $mapDestRows = [];
        $referenceColumnName = $this->_getParentTableReferenceColumnName($tableName);
        foreach ($destRows as $row) {
            $mapDestRows[$row[$referenceColumnName]] = $row;
        }

# UtilDebug::dd($mapDestRows);

        // ---- translation loop
//            $this->cliStyle->progressStart(count($sourceRows));

        foreach ($sourceRows as $row) {
            $updates = [];
            foreach ($textColumns as $column) {
                $columnName = $column->getName();
                // ---- check if translation already exists
                $originalText = $row[$columnName];
                $existingTranslation = $mapDestRows[$row[$referenceColumnName]][$columnName] ?? null;
                if($existingTranslation) {
                    $this->cliStyle->writeln("Translation already exists for $columnName [$originalText --> $existingTranslation] >>> SKIP");
                    continue;
                }
                if (!empty($originalText)) {
                    try {
                        $translatedText = $this->deeplTranslator->translate(
                            $originalText,
                            $sourceLang,
                            $targetLang,
                            ['table' => $tableName, 'column' => $columnName]
                        );
                        $updates[$columnName] = $translatedText;
                        //  write the translation to the console
                        $this->cliStyle->writeln("> {$originalText} [$sourceLang] --> {$translatedText} [$targetLang]");
                    } catch (\Exception $e) {
                        $this->cliStyle->error("Translation error for $columnName: " . $e->getMessage());
                    }
                }
            }

            if(empty($updates)) {
                $this->cliStyle->writeln("No updates for row --> SKIP");
                continue;
            }

            // ---- after all columns of the row are translated:
            $updates['updated_at'] = date('Y-m-d H:i:s');
            // For now, we'll just log the updates
            $this->cliStyle->text("Updates: " . json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            // Here you would update or insert the translated row
            $crit = $this->_buildUpdateRowCrit($tableName, $row);
//            UtilDebug::dd($crit);
            $numUpdates = $this->connection->update($tableName, $updates, $crit);
            if ($numUpdates === 0) {
                $this->cliStyle->writeln("Error updating row ... we insert instead");
                $new = array_merge($crit, $updates);
                $new['created_at'] = date('Y-m-d H:i:s');
                $numInserted = $this->connection->insert($tableName, $new);
                if ($numInserted === 0) {
                    $this->cliStyle->error("Error inserting row");
                }
            }

//                $this->cliStyle->progressAdvance();
        }

//            $this->cliStyle->progressFinish();
    }

    /**
     * build the criteria for updating a row in a translation table
     */
    private function _buildUpdateRowCrit(string $tableName, array $row): array
    {
        $parent_table_id = $this->_getParentTableReferenceColumnName($tableName);

        return [
            'language_id'    => $this->langIdTo,
            $parent_table_id => $row[$parent_table_id], // eg state_machine_state_id
        ];
    }

    private function _getTextColumnNames(string $tableName): array
    {
        $columns = $this->connection->getSchemaManager()->listTableColumns($tableName);
        $textColumns = array_filter($columns, function ($column) {
            return $column->getType()->getName() === 'string'
                && !in_array($column->getName(), ['id'])
                && substr($column->getName(), -3) !== '_id'
                && substr($column->getName(), -7) !== '_config';
        });

        return $textColumns;
    }

    private function printAvailableLanguages(): void
    {
        $langs = $this->getLanguages();
        $this->cliStyle->section('Available Languages');
        $this->cliStyle->table(['Language ID', 'Language Code', 'Language Name'], $langs);
    }

    protected function configure(): void
    {
        $this->setDescription('Translate content from one language to another');
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific table to translate');
        $this->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source language code (e.g., de-DE)', 'de-DE');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target language code (e.g., cs-CZ)', 'cs-CZ');
    }

    /**
     * ==== MAIN ====
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ---- init
        $this->cliStyle = new SymfonyStyle($input, $output);
        assert(getenv('DEEPL_FREE_API_KEY'), 'DEEPL_FREE_API_KEY is missing');
        $this->deeplTranslator = new DeeplTranslator(getenv('DEEPL_FREE_API_KEY'));

        $this->cliStyle->title('Machine Translation Command');

        // ---- Display available languages
        $this->printAvailableLanguages();

        // ---- Get language codes from CLI options
        $langCodeFrom = $input->getOption('from');
        $langCodeTo = $input->getOption('to');
        $sourceLang = substr($langCodeFrom, 0, 2);
        $targetLang = substr($langCodeTo, 0, 2);
        // ---- display selected languages
        $this->cliStyle->section('Selected Languages');
        $this->cliStyle->text([
            "From: $langCodeFrom",
            "To: $langCodeTo"
        ]);

        // ---- Check if language codes are available in sw6
        $availableLangs = $this->getLanguages();
        $langCodes = array_column($availableLangs, 'code');
        if (!in_array($langCodeFrom, $langCodes)) {
            $this->cliStyle->error("Error: From-Language Code $langCodeFrom not found. Available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }
        // ---- Get language IDs from SW6 DB
        $this->langIdFrom = $this->getLanguageId($langCodeFrom);
        $this->langIdTo = $this->getLanguageId($langCodeTo);

        if (!$this->langIdFrom || !$this->langIdTo) {
            $this->cliStyle->error("Error: Could not find language IDs for the specified languages.");

            return Command::FAILURE;
        }
        if (!in_array($langCodeTo, $langCodes)) {
            $this->cliStyle->error("Error: To-Language Code $langCodeTo not found. Available: " . implode(', ', $langCodes));

            return Command::FAILURE;
        }


        // ---- DB table/s
        $specificTables = $input->getOption('table');
        if (!empty($specificTables)) {
            // ---- CASE A - specific table/s
            $this->cliStyle->text("Specific table: " . implode(", ", $specificTables));
            // Check if the table is translatable
            foreach ($specificTables as $specificTable) {
                if (!in_array($specificTable, $this->_getTranslatableTables())) {
                    $this->cliStyle->error("Error: Table $specificTable not found or not translatable.");

                    return Command::FAILURE;
                }
            }
            $tables = $specificTables;
        } else {
            // ---- CASE B - all translatable tables
            $tables = $this->_getTranslatableTables();
            // print tables and ask for confirmation
            $this->cliStyle->table(['table name'], array_map(fn($x) => [$x], $tables));
            $this->cliStyle->confirm("Do you want to process ALL tables?");
        }

        $this->cliStyle->listing($tables, 'Tables to process');


        $this->cliStyle->section('Processing Tables');

        foreach ($tables as $tableName) {
            $this->_processTable($tableName, $sourceLang, $targetLang);
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

    /**
     * it returns tables that have a _translation suffix
     *
     * 09/2024 created
     *
     * @return string[]
     */
    private function _getTranslatableTables(): array
    {
        $tables = $this->connection->getSchemaManager()->listTableNames();
        $filtered = array_filter($tables, function ($tableName) {
            return substr($tableName, -strlen(self::TABLE_SUFFIX_TRANSLATION)) === self::TABLE_SUFFIX_TRANSLATION;
        });

        return $filtered;
    }
}
