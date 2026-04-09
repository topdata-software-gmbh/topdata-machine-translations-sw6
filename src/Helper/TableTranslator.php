<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * Handles translation of database table content between languages.
 * This class identifies text columns in tables, translates content using DeepL,
 * and updates or inserts translated records in the target language table.
 *
 * 09/2024 created
 */
class TableTranslator
{
    private const TABLE_SUFFIX_TRANSLATION = '_translation';

    private Connection $connection;
    private DeeplTranslator $deeplTranslator;

    public function __construct(Connection $connection, DeeplTranslator $deeplTranslator)
    {
        $this->connection = $connection;
        $this->deeplTranslator = $deeplTranslator;
    }

    /**
     * Translates all content in a table from source language to target language.
     * 
     * @param string $tableName The name of the table to translate
     * @param string $langIdFrom The source language ID
     * @param string $langIdTo The target language ID
     * @param string $sourceLang The source language code (e.g., 'en')
     * @param string $targetLang The target language code (e.g., 'de')
     */
    public function translateTable(string $tableName, string $langIdFrom, string $langIdTo, string $sourceLang, string $targetLang): void
    {
        CliLogger::info("Processing table: $tableName");

        $textColumns = $this->getTextColumnNames($tableName);
        $sourceRows = $this->getSourceRows($tableName, $langIdFrom);
        $mapDestRows = $this->getDestinationRows($tableName, $langIdTo);

        // ---- Process each source row for translation
        foreach ($sourceRows as $row) {
            $updates = $this->translateRow($row, $textColumns, $mapDestRows, $tableName, $sourceLang, $targetLang);

            if (empty($updates)) {
                CliLogger::writeln("No updates for row --> SKIP");
                continue;
            }

            $this->updateOrInsertTranslation($tableName, $row, $updates, $langIdTo);
        }
    }

    /**
     * Identifies and returns all text columns in a table that should be translated.
     * 
     * @param string $tableName The name of the table to analyze
     * @return array Array of text columns to translate
     */
    private function getTextColumnNames(string $tableName): array
    {
        $schemaManager = method_exists($this->connection, 'createSchemaManager')
            ? $this->connection->createSchemaManager()
            : $this->connection->getSchemaManager();

        $columns = $schemaManager->listTableColumns($tableName);
        return array_filter($columns, function ($column) {
            return $column->getType()->getName() === 'string'
                && !in_array($column->getName(), ['id'])
                && substr($column->getName(), -3) !== '_id'
                && substr($column->getName(), -7) !== '_config';
        });
    }

    /**
     * Retrieves all rows from the source language table.
     * 
     * @param string $tableName The name of the table to query
     * @param string $langIdFrom The source language ID
     * @return array Array of source language rows
     */
    private function getSourceRows(string $tableName, string $langIdFrom): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $langIdFrom)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Retrieves all rows from the target language table and maps them by reference column.
     * 
     * @param string $tableName The name of the table to query
     * @param string $langIdTo The target language ID
     * @return array Array of target language rows mapped by reference column
     */
    private function getDestinationRows(string $tableName, string $langIdTo): array
    {
        $destRows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $langIdTo)
            ->executeQuery()
            ->fetchAllAssociative();

        $mapDestRows = [];
        $referenceColumnName = $this->getParentTableReferenceColumnName($tableName);
        foreach ($destRows as $row) {
            $mapDestRows[$row[$referenceColumnName]] = $row;
        }

        return $mapDestRows;
    }

    /**
     * Translates a single row from source to target language.
     * 
     * @param array $row The source row to translate
     * @param array $textColumns Array of text columns to translate
     * @param array $mapDestRows Map of existing destination rows
     * @param string $tableName The name of the table
     * @param string $sourceLang The source language code
     * @param string $targetLang The target language code
     * @return array Array of translated column values
     */
    private function translateRow(array $row, array $textColumns, array $mapDestRows, string $tableName, string $sourceLang, string $targetLang): array
    {
        $updates = [];
        $referenceColumnName = $this->getParentTableReferenceColumnName($tableName);

        // ---- Translate each text column
        foreach ($textColumns as $column) {
            $columnName = $column->getName();
            $originalText = $row[$columnName];
            $existingTranslation = $mapDestRows[$row[$referenceColumnName]][$columnName] ?? null;

            if ($existingTranslation) {
                CliLogger::writeln("Translation already exists for $columnName [$originalText --> $existingTranslation] >>> SKIP");
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
                    CliLogger::writeln("> {$originalText} [$sourceLang] --> {$translatedText} [$targetLang]");
                } catch (Exception $e) {
                    CliLogger::error("Translation error for $columnName: " . $e->getMessage());
                }
            }
        }

        return $updates;
    }

    /**
     * Updates an existing translation row or inserts a new one if it doesn't exist.
     * 
     * @param string $tableName The name of the table
     * @param array $row The source row
     * @param array $updates Array of translated column values
     * @param string $langIdTo The target language ID
     */
    private function updateOrInsertTranslation(string $tableName, array $row, array $updates, string $langIdTo): void
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        CliLogger::writeln("Updates: " . json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $crit = $this->buildUpdateRowCrit($tableName, $row, $langIdTo);
        
        // ---- Try to update existing row
        $numUpdates = $this->connection->update($tableName, $updates, $crit);

        if ($numUpdates === 0) {
            // ---- Insert new row if update failed
            CliLogger::writeln("Error updating row ... we insert instead");
            $new = array_merge($crit, $updates);
            $new['created_at'] = date('Y-m-d H:i:s');
            $numInserted = $this->connection->insert($tableName, $new);
            if ($numInserted === 0) {
                CliLogger::error("Error inserting row");
            }
        }
    }

    /**
     * Determines the reference column name for the parent table.
     * 
     * @param string $tableName The translation table name
     * @return string The reference column name
     */
    private function getParentTableReferenceColumnName(string $tableName): string
    {
        $parentTableName = substr($tableName, 0, -strlen(self::TABLE_SUFFIX_TRANSLATION));
        return $parentTableName . '_id';
    }

    /**
     * Builds the criteria array for updating or inserting a translation row.
     * 
     * @param string $tableName The name of the table
     * @param array $row The source row
     * @param string $langIdTo The target language ID
     * @return array Criteria array for database operations
     */
    private function buildUpdateRowCrit(string $tableName, array $row, string $langIdTo): array
    {
        $parent_table_id = $this->getParentTableReferenceColumnName($tableName);

        return [
            'language_id'    => $langIdTo,
            $parent_table_id => $row[$parent_table_id],
        ];
    }
}