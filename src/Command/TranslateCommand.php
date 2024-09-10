<?php

namespace Topdata\MachineTranslations\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Doctrine\DBAL\Connection;
use DeepL\Translator;

class TranslateCommand extends Command
{
    protected static $defaultName = 'topdata:translate';

    private Connection $connection;
    private Translator $translator;
    private string $germanLanguageId;
    private string $czechLanguageId;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
        $this->translator = new Translator(getenv('DEEPL_FREE_API_KEY'));
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Translate content from German to Czech')
            ->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Specific table to translate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->germanLanguageId = $this->getLanguageId('de-DE');
        $this->czechLanguageId = $this->getLanguageId('cs-CZ');

        if (!$this->germanLanguageId || !$this->czechLanguageId) {
            $output->writeln("Error: Could not find language IDs for German or Czech.");
            return Command::FAILURE;
        }

        $specificTable = $input->getOption('table');

        $tables = $specificTable
            ? [$specificTable]
            : $this->connection->createSchemaManager()->listTableNames();

        foreach ($tables as $tableName) {
            if (substr($tableName, -11) === '_translation') {
                $output->writeln("Processing table: $tableName");

                $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);
                $textColumns = array_filter($columns, function($column) {
                    return !in_array($column->getName(), ['id', 'language_id']);
                });

                $germanRows = $this->connection->createQueryBuilder()
                    ->select('*')
                    ->from($tableName)
                    ->where('language_id = :languageId')
                    ->setParameter('languageId', $this->germanLanguageId)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($germanRows as $row) {
                    $updates = [];
                    foreach ($textColumns as $column) {
                        $columnName = $column->getName();
                        if (!empty($row[$columnName])) {
                            try {
                                $translatedText = $this->translator->translateText($row[$columnName], 'de', 'cs');
                                $updates[$columnName] = $translatedText->text;
                            } catch (\Exception $e) {
                                $output->writeln("Translation error for $columnName: " . $e->getMessage());
                            }
                        }
                    }

                    if (!empty($updates)) {
                        $this->connection->createQueryBuilder()
                            ->update($tableName)
                            ->where('id = :id')
                            ->andWhere('language_id = :languageId')
                            ->setParameter('id', $row['id'])
                            ->setParameter('languageId', $this->czechLanguageId)
                            ->setParameters($updates)
                            ->executeStatement();
                    }
                }
            }
        }

        $output->writeln("Translation completed.");
        return Command::SUCCESS;
    }

    private function getLanguageId(string $locale): ?string
    {
        $result = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('language')
            ->where('LOWER(translation_code_id) = LOWER(:locale)')
            ->setParameter('locale', $locale)
            ->executeQuery()
            ->fetchOne();

        return $result ?: null;
    }
}
