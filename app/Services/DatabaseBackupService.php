<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    /** @var int */
    const MAX_FILES_PER_BUSINESS = 15;

    /**
     * Absolute path to directory where backups for this business are stored.
     *
     * @param int $businessId
     * @return string
     */
    public function backupDirectory($businessId)
    {
        return storage_path('app/database_backups/' . (int) $businessId);
    }

    /**
     * @param int $businessId
     * @return array<int, array{name:string,size:int,modified:string}>
     */
    public function listBackups($businessId)
    {
        $dir = $this->backupDirectory($businessId);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . 'backup_*.sql');
        if ($files === false) {
            return [];
        }

        $out = [];
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (!$this->isAllowedBackupFilename($name)) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            $out[] = [
                'name' => $name,
                'size' => (int) filesize($path),
                'mtime' => $mtime,
                'modified' => $mtime ? Carbon::createFromTimestamp($mtime)->toIso8601String() : '',
            ];
        }

        usort($out, function ($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });

        foreach ($out as &$row) {
            unset($row['mtime']);
        }
        unset($row);

        return $out;
    }

    /**
     * @param int $businessId
     * @return array{success:bool, message?:string, filename?:string, size?:int, path?:string}
     */
    public function createBackup($businessId)
    {
        $connection = config('database.default');
        $config = config('database.connections.' . $connection);

        if (empty($config['driver']) || $config['driver'] !== 'mysql') {
            return [
                'success' => false,
                'message' => 'Database backups from this screen are only supported when using MySQL.',
            ];
        }

        $dir = $this->backupDirectory($businessId);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = (string) ($config['port'] ?? '3306');
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? '';
        $password = (string) ($config['password'] ?? '');
        $socket = $config['unix_socket'] ?? '';

        if ($database === '') {
            return ['success' => false, 'message' => 'Database name is not configured.'];
        }

        $binary = env('DB_BACKUP_MYSQLDUMP_PATH', 'mysqldump');
        $filename = 'backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;

        $args = [
            $binary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--skip-lock-tables',
        ];

        if (!empty($socket)) {
            $args[] = '--socket=' . $socket;
        } else {
            $args[] = '-h' . $host;
            $args[] = '-P' . $port;
        }

        $args[] = '-u' . $username;
        if ($password !== '') {
            $args[] = '-p' . $password;
        }
        $args[] = $database;

        $process = new Process($args);
        $process->setTimeout(7200);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'success' => false,
                'message' => 'mysqldump failed: ' . $this->truncateMessage($process->getErrorOutput() ?: $process->getOutput()),
            ];
        }

        $output = $process->getOutput();
        if ($output === '' || $output === null) {
            return [
                'success' => false,
                'message' => 'mysqldump produced no output. Check that mysqldump is installed and DB credentials are correct.',
            ];
        }

        if (file_put_contents($fullPath, $output) === false) {
            return ['success' => false, 'message' => 'Could not write backup file to disk.'];
        }

        @chmod($fullPath, 0640);

        $this->pruneOldBackups($businessId);

        return [
            'success' => true,
            'filename' => $filename,
            'size' => (int) filesize($fullPath),
            'path' => $fullPath,
        ];
    }

    /**
     * Resolve a safe absolute path for download, or null if invalid.
     *
     * @param int $businessId
     * @param string $filename basename only
     * @return string|null
     */
    public function resolveDownloadPath($businessId, $filename)
    {
        $filename = basename($filename);
        if (!$this->isAllowedBackupFilename($filename)) {
            return null;
        }

        $dir = realpath($this->backupDirectory($businessId));
        if ($dir === false || !is_dir($dir)) {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return null;
        }

        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        if (strpos($real, $dir) !== 0) {
            return null;
        }

        return $real;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function isAllowedBackupFilename($name)
    {
        return (bool) preg_match('/^backup_\d{8}_\d{6}_[a-f0-9]{8}\.sql$/', $name);
    }

    /**
     * @param int $businessId
     * @return void
     */
    protected function pruneOldBackups($businessId)
    {
        $list = $this->listBackups($businessId);
        if (count($list) <= self::MAX_FILES_PER_BUSINESS) {
            return;
        }

        $toDelete = array_slice($list, self::MAX_FILES_PER_BUSINESS);
        $dir = $this->backupDirectory($businessId);
        foreach ($toDelete as $row) {
            $path = $dir . DIRECTORY_SEPARATOR . $row['name'];
            if ($this->isAllowedBackupFilename($row['name']) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param string $msg
     * @param int $max
     * @return string
     */
    protected function truncateMessage($msg, $max = 500)
    {
        $msg = trim(preg_replace('/\s+/', ' ', $msg));
        if (strlen($msg) <= $max) {
            return $msg;
        }

        return substr($msg, 0, $max) . '…';
    }
}
