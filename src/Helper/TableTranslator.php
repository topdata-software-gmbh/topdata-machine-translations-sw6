<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 09/2024 created
 */
class TableTranslator
{
    private const TABLE_SUFFIX_TRANSLATION = '_translation';

    private Connection $connection;
    private DeeplTranslator $deeplTranslator;
    private SymfonyStyle $cliStyle;

    public function __construct(Connection $connection, DeeplTranslator $deeplTranslator, SymfonyStyle $cliStyle)
    {
        $this->connection = $connection;
        $this->deeplTranslator = $deeplTranslator;
        $this->cliStyle = $cliStyle;
    }

    public function translateTable(string $tableName, string $langIdFrom, string $langIdTo, string $sourceLang, string $targetLang): void
    {
        $this->cliStyle->info("Processing table: $tableName");

        $textColumns = $this->getTextColumnNames($tableName);
        $sourceRows = $this->getSourceRows($tableName, $langIdFrom);
        $mapDestRows = $this->getDestinationRows($tableName, $langIdTo);

        foreach ($sourceRows as $row) {
            $updates = $this->translateRow($row, $textColumns, $mapDestRows, $tableName, $sourceLang, $targetLang);

            if (empty($updates)) {
                $this->cliStyle->writeln("No updates for row --> SKIP");
                continue;
            }

            $this->updateOrInsertTranslation($tableName, $row, $updates, $langIdTo);
        }
    }

    private function getTextColumnNames(string $tableName): array
    {
        $columns = $this->connection->getSchemaManager()->listTableColumns($tableName);
        return array_filter($columns, function ($column) {
            return $column->getType()->getName() === 'string'
                && !in_array($column->getName(), ['id'])
                && substr($column->getName(), -3) !== '_id'
                && substr($column->getName(), -7) !== '_config';
        });
    }

    private function getSourceRows(string $tableName, string $langIdFrom): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $langIdFrom)
            ->execute()
            ->fetchAllAssociative();
    }

    private function getDestinationRows(string $tableName, string $langIdTo): array
    {
        $destRows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($tableName)
            ->where('language_id = :languageId')
            ->setParameter('languageId', $langIdTo)
            ->execute()
            ->fetchAllAssociative();

        $mapDestRows = [];
        $referenceColumnName = $this->getParentTableReferenceColumnName($tableName);
        foreach ($destRows as $row) {
            $mapDestRows[$row[$referenceColumnName]] = $row;
        }

        return $mapDestRows;
    }

    private function translateRow(array $row, array $textColumns, array $mapDestRows, string $tableName, string $sourceLang, string $targetLang): array
    {
        $updates = [];
        $referenceColumnName = $this->getParentTableReferenceColumnName($tableName);

        foreach ($textColumns as $column) {
            $columnName = $column->getName();
            $originalText = $row[$columnName];
            $existingTranslation = $mapDestRows[$row[$referenceColumnName]][$columnName] ?? null;

            if ($existingTranslation) {
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
                    $this->cliStyle->writeln("> {$originalText} [$sourceLang] --> {$translatedText} [$targetLang]");
                } catch (\Exception $e) {
                    $this->cliStyle->error("Translation error for $columnName: " . $e->getMessage());
                }
            }
        }

        return $updates;
    }

    private function updateOrInsertTranslation(string $tableName, array $row, array $updates, string $langIdTo): void
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        $this->cliStyle->text("Updates: " . json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $crit = $this->buildUpdateRowCrit($tableName, $row, $langIdTo);
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
    }

    private function getParentTableReferenceColumnName(string $tableName): string
    {
        $parentTableName = substr($tableName, 0, -strlen(self::TABLE_SUFFIX_TRANSLATION));
        return $parentTableName . '_id';
    }

    private function buildUpdateRowCrit(string $tableName, array $row, string $langIdTo): array
    {
        $parent_table_id = $this->getParentTableReferenceColumnName($tableName);

        return [
            'language_id'    => $langIdTo,
            $parent_table_id => $row[$parent_table_id],
        ];
    }
}