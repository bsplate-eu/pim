<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupService
{

    private string $database;
    private string $username;
    private string $password;

    private string $host;

    private string $port;

    public function __construct()
    {
        $path = $this->getPath();
        if (!File::exists($path)) {
            File::makeDirectory($path, 0777, true, true);
        }
        $this->host = config('database.connections.mysql.host');
        $this->port = config('database.connections.mysql.port');
        $this->database = config('database.connections.mysql.database');
        $this->username = config('database.connections.mysql.username');
        $this->password = config('database.connections.mysql.password');
    }

    private function getPath($filename = null)
    {
        return storage_path("app/backup/$filename");
    }


    public function backup()
    {

        $filename = "backup-" . now()->format('Y-m-d_H-i-s') . ".gz";
        $path = $this->getPath($filename);

        // Check if the path is valid
        if (!$path) {
            throw new \Exception("The file path is invalid.");
        }

        // Create the mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --quick --no-tablespaces %s | gzip > %s',
            escapeshellarg($this->host),
            escapeshellarg($this->port),
            escapeshellarg($this->username),
            escapeshellarg($this->password),
            escapeshellarg($this->database),
            escapeshellarg($path)
        );

        $returnVar = NULL;
        $output = NULL;

        // Execute the command and capture the output and return code
        exec($command, $output, $returnVar);


        // Check if the command was successful
        if ($returnVar !== 0) {
            throw new \Exception("Backup failed. Return code: $returnVar. Output: " . implode("\n", $output));

        }

    }

    public function cleanBakcups()
    {
        $files = File::files(storage_path("app/backup"));
        $backup_max_age = now()->subDays(30)->timestamp;

        foreach ($files as $file) {
            if ($file->getMTime() <= $backup_max_age) {
                File::delete($file->getRealPath());
            }
        }
    }

    public function restore($filename = null)
    {
        if (is_null($filename) || !is_file($this->getPath($filename))) {
            $filename = $this->getLastBackup();
        }

        $path = $this->getPath($filename);

        // Check if the path is valid
        if (!$path || !is_file($path)) {
            throw new \Exception("The backup file path is invalid or the file does not exist.");
        }

        // Create the restore command
        $command = sprintf(
            'gunzip < %s | mysql -u%s -p%s %s',
            escapeshellarg($path),
            escapeshellarg($this->username),
            escapeshellarg($this->password),
            escapeshellarg($this->database)
        );


        $returnVar = NULL;
        $output = NULL;

        // Execute the command and capture the output and return code
        exec($command, $output, $returnVar);

        // Check if the command was successful
        if ($returnVar !== 0) {
            throw new \Exception("Restore failed. Return code: $returnVar. Output: " . implode("\n", $output));
        }
    }

    public function getAllBackups()
    {
        $path = $this->getPath();
        return array_filter(scandir($path, SCANDIR_SORT_DESCENDING), fn($i) => str_contains($i, '.gz'));
    }

    public function getLastBackup()
    {
        $files = $this->getAllBackups();
        throw_if(count($files) == 0, new \Exception('No backup found'));
        return $files[0];
    }
}
