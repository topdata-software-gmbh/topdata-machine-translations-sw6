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
        $this->cliStyle->table(array_keys($langs[0]), $langs);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Translate content from German to Czech')
            ->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Specific table to translate');
    }

    /**
     * ==== MAIN ====
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cliStyle = new SymfonyStyle($input, $output);

        // ---- just for info
        $this->printAvailableLanguages();

        // ----
        $langCodeFrom = 'de-DE';
//        $langCodeTo = 'cs-CZ';
        $langCodeTo = 'en-GB';

        // ---- check if lang codes are available
        $availableLangs = $this->getLanguages();
        $langCodes = array_column($availableLangs, 'code');
        if (!in_array($langCodeFrom, $langCodes)) {
            $output->writeln("Error: From-Language Code $langCodeFrom not found. available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }
        if (!in_array($langCodeTo, $langCodes)) {
            $output->writeln("Error: To-Language Code $langCodeTo not found. available: " . implode(', ', $langCodes));
            return Command::FAILURE;
        }

        // ---- MAIN
        $this->langIdFrom = $this->getLanguageId($langCodeFrom);
        $this->langIdTo = $this->getLanguageId($langCodeTo);

        if (!$this->langIdFrom || !$this->langIdTo) {
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
                $textColumns = array_filter($columns, function ($column) {
                    return !in_array($column->getName(), ['id', 'language_id']);
                });

                $germanRows = $this->connection->createQueryBuilder()
                    ->select('*')
                    ->from($tableName)
                    ->where('language_id = :languageId')
                    ->setParameter('languageId', $this->langIdFrom)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($germanRows as $row) {
                    $updates = [];
                    foreach ($textColumns as $column) {
                        $columnName = $column->getName();
                        if (!empty($row[$columnName])) {
                            try {
                                $translatedText = $this->translator->translate($row[$columnName], 'DE', 'CS');
                                $updates[$columnName] = $translatedText;
                            } catch (\Exception $e) {
                                $output->writeln("Translation error for $columnName: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        $output->writeln("Translation completed.");

        return Command::SUCCESS;
    }

    private function getLanguageId(string $localeCode): ?string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('lan.id');
        $qb->from('language', 'lan');
        $qb->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id');
        $qb->where('LOWER(loc.code) = LOWER(:locale)');
        $qb->setParameter('locale', $localeCode);

        return $qb->executeQuery()->fetchOne() ?? null;
    }

    private function getLanguages(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('LOWER(HEX(lan.id)) AS languageId, loc.code AS code, lan.name AS name');
        $qb->from('language', 'lan');
        $qb->innerJoin('lan', 'locale', 'loc', 'lan.locale_id = loc.id');

        return $qb->fetchAllAssociative();
    }

    private function translateText(string $text, string $sourceLang, string $targetLang): string
    {
        $data = [
            'auth_key'    => $this->apiKey,
            'text'        => $text,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }

        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['translations'][0]['text'])) {
            return $result['translations'][0]['text'];
        } else {
            throw new \Exception('Translation failed: ' . $response);
        }
    }
}
