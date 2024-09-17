<?php

namespace Topdata\TopdataMachineTranslationsSW6\Helper;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *
 */
class TableBackuper
{

    private $db_host;
    private $db_user;
    private $db_password;
    private $db_name;

    public function __construct(
        string $databaseUrl,
        SymfonyStyle $cliStyle
    )
    {
        $this->cliStyle = $cliStyle;
        $this->_parseDatabaseUrl($databaseUrl);
    }


    // use mysqldump to back up the table
    public function backupTable(string $tableName): string
    {
        $backupFile = $this->_getBackupFilename($tableName);
        $this->cliStyle->info("Backing up table: $tableName to $backupFile");

        $command = sprintf(
            'mysqldump --hex-blob --single-transaction -h%s -u%s -p%s %s %s > %s',
            $this->db_host,
            $this->db_user,
            $this->db_password,
            $this->db_name,
            $tableName,
            $backupFile
        );

        $this->cliStyle->writeln("Executing command: $command");
        exec($command);
        assert(file_exists($backupFile), 'Backup file was not created');

        return $backupFile;
    }

    private function _getBackupFilename(string $tableName): string
    {
        $backupDir = '/tmp/database-backups'; // fixme: make it configurable
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }

        return $backupDir . '/' . $tableName . '_' . date('Ymd_His') . '.sql';
    }

    private function _parseDatabaseUrl(string $databaseUrl): void
    {
        $urlParts = parse_url($databaseUrl);
        $this->db_host = $urlParts['host'];
        $this->db_user = $urlParts['user'];
        $this->db_password = $urlParts['pass'];
        $this->db_name = ltrim($urlParts['path'], '/');
        $this->cliStyle->table(['Host', 'User', 'Password', 'Database'], [[$this->db_host, $this->db_user, $this->db_password, $this->db_name]]);
    }
}