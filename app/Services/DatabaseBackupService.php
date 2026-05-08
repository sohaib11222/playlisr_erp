<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
     * @return array{
     *   success:bool,
     *   message?:string,
     *   filename?:string,
     *   size?:int,
     *   path?:string,
     *   uploaded_to_drive?:bool,
     *   drive_url?:string,
     *   upload_message?:string
     * }
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
            // Ignore my.cnf / .my.cnf options that can break dumps
            // (e.g. unknown variable 'database=...').
            '--no-defaults',
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

        $upload = $this->uploadToGoogleDriveIfConfigured($businessId, $fullPath, $filename);

        return [
            'success' => true,
            'filename' => $filename,
            'size' => (int) filesize($fullPath),
            'path' => $fullPath,
            'uploaded_to_drive' => !empty($upload['success']),
            'drive_url' => $upload['url'] ?? null,
            'upload_message' => $upload['message'] ?? null,
        ];
    }

    /**
     * @return bool
     */
    public function isGoogleDriveUploadConfigured()
    {
        return !empty(config('nivessa.backup_google_drive.enabled'))
            && trim((string) config('nivessa.backup_google_drive.webhook_url', '')) !== '';
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
     * Upload backup to Google Drive via a webhook endpoint (optional).
     * Safe-by-default: local backup remains primary even if upload fails.
     *
     * @param int $businessId
     * @param string $localPath
     * @param string $filename
     * @return array{success:bool,message?:string,url?:string}
     */
    protected function uploadToGoogleDriveIfConfigured($businessId, $localPath, $filename)
    {
        if (!$this->isGoogleDriveUploadConfigured()) {
            return ['success' => false, 'message' => 'Google Drive upload is not configured.'];
        }

        if (!is_file($localPath)) {
            return ['success' => false, 'message' => 'Backup file not found for upload.'];
        }

        $webhookUrl = trim((string) config('nivessa.backup_google_drive.webhook_url', ''));
        $token = trim((string) config('nivessa.backup_google_drive.token', ''));
        $folderId = trim((string) config('nivessa.backup_google_drive.folder_id', ''));
        $timeout = (int) config('nivessa.backup_google_drive.timeout_seconds', 90);
        $timeout = max(10, min(300, $timeout));

        // Web app uploads (Apps Script, etc.) have payload limits; send gzipped dump.
        $uploadPath = $localPath;
        $uploadFilename = $filename;
        $cleanupUploadPath = null;
        if (preg_match('/\.sql$/i', $filename)) {
            $gzFilename = preg_replace('/\.sql$/i', '.sql.gz', $filename);
            $gzPath = dirname($localPath) . DIRECTORY_SEPARATOR . $gzFilename;
            $gzOk = $this->createGzipCopy($localPath, $gzPath);
            if ($gzOk && is_file($gzPath)) {
                $uploadPath = $gzPath;
                $uploadFilename = $gzFilename;
                $cleanupUploadPath = $gzPath;
            } else {
                Log::warning('Database backup gzip compression failed before drive upload', [
                    'business_id' => $businessId,
                    'filename' => $filename,
                ]);
            }
        }

        try {
            $ch = curl_init();
            $payload = [
                'business_id' => (string) $businessId,
                'filename' => $uploadFilename,
                'token' => $token,
                'folder_id' => $folderId,
                'file' => new \CURLFile($uploadPath, 'application/gzip', $uploadFilename),
            ];

            curl_setopt_array($ch, [
                CURLOPT_URL => $webhookUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'NivessaERP-Backup/1.0',
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = (string) curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                Log::warning('Database backup drive upload failed', [
                    'business_id' => $businessId,
                    'filename' => $uploadFilename,
                    'reason' => 'curl_error',
                    'error' => $curlError,
                ]);
                if (!empty($cleanupUploadPath) && is_file($cleanupUploadPath)) {
                    @unlink($cleanupUploadPath);
                }
                return ['success' => false, 'message' => 'Drive upload cURL error: ' . $this->truncateMessage($curlError, 220)];
            }

            $decoded = null;
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $detail = '';
                if (is_array($decoded) && !empty($decoded['message'])) {
                    $detail = (string) $decoded['message'];
                } elseif (is_string($body) && $body !== '') {
                    $detail = $this->truncateMessage($body, 220);
                }

                Log::warning('Database backup drive upload failed', [
                    'business_id' => $businessId,
                    'filename' => $uploadFilename,
                    'reason' => 'http_error',
                    'http_code' => $httpCode,
                    'detail' => $detail,
                ]);
                if (!empty($cleanupUploadPath) && is_file($cleanupUploadPath)) {
                    @unlink($cleanupUploadPath);
                }
                return ['success' => false, 'message' => 'Drive upload failed (HTTP ' . $httpCode . ')' . ($detail !== '' ? ': ' . $detail : '')];
            }

            if (is_array($decoded) && (isset($decoded['success']) && !$decoded['success'])) {
                Log::warning('Database backup drive upload failed', [
                    'business_id' => $businessId,
                    'filename' => $uploadFilename,
                    'reason' => 'webhook_rejected',
                    'detail' => (string) ($decoded['message'] ?? 'Unknown error'),
                ]);
                if (!empty($cleanupUploadPath) && is_file($cleanupUploadPath)) {
                    @unlink($cleanupUploadPath);
                }
                return ['success' => false, 'message' => 'Drive upload rejected: ' . (string) ($decoded['message'] ?? 'Unknown error')];
            }

            $url = null;
            if (is_array($decoded)) {
                $url = $decoded['url'] ?? $decoded['webViewLink'] ?? $decoded['file_url'] ?? null;
            }

            Log::info('Database backup drive upload succeeded', [
                'business_id' => $businessId,
                'filename' => $uploadFilename,
                'drive_url' => $url,
            ]);
            if (!empty($cleanupUploadPath) && is_file($cleanupUploadPath)) {
                @unlink($cleanupUploadPath);
            }
            return [
                'success' => true,
                'message' => $url ? 'Backup uploaded to Google Drive (compressed .sql.gz).' : 'Backup uploaded (compressed .sql.gz via webhook).',
                'url' => $url,
            ];
        } catch (\Throwable $e) {
            Log::warning('Database backup drive upload failed', [
                'business_id' => $businessId,
                'message' => $e->getMessage(),
            ]);
            if (!empty($cleanupUploadPath) && is_file($cleanupUploadPath)) {
                @unlink($cleanupUploadPath);
            }

            return ['success' => false, 'message' => 'Drive upload exception: ' . $this->truncateMessage($e->getMessage(), 220)];
        }
    }

    /**
     * Stream-compress SQL dump to gzip without loading full file in memory.
     *
     * @param string $sourcePath
     * @param string $targetGzPath
     * @return bool
     */
    protected function createGzipCopy($sourcePath, $targetGzPath)
    {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            return false;
        }

        $out = @gzopen($targetGzPath, 'wb6');
        if ($out === false) {
            @fclose($in);
            return false;
        }

        $ok = true;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                $ok = false;
                break;
            }
        }

        @fclose($in);
        @gzclose($out);

        if (!$ok) {
            @unlink($targetGzPath);
            return false;
        }

        return is_file($targetGzPath) && filesize($targetGzPath) > 0;
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
