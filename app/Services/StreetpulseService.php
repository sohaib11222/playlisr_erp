<?php

namespace App\Services;

use App\Business;
use App\Utils\BusinessUtil;
use Illuminate\Support\Facades\Log;

class StreetpulseService
{
    private $businessUtil;
    private $businessId;
    private $business;
    private $fileGenerator;

    // FTP Configuration
    private $ftpHosts = [
        'primary' => 'collection.streetpulse.com',
        'backup' => 'collection2.streetpulse.com'
    ];
    private $ftpUsername = 'spulse';
    private $ftpPassword = 'meter';
    private $ftpPort = 21;

    public function __construct($businessId = null)
    {
        $this->businessUtil = new BusinessUtil();
        $this->businessId = $businessId ?? request()->session()->get('user.business_id');
        $this->business = Business::find($this->businessId);
        $this->fileGenerator = new StreetpulseFileGenerator();
    }

    /**
     * Check if Streetpulse is configured with required credentials
     *
     * @return bool
     */
    public function isConfigured()
    {
        if (!$this->business) {
            return false;
        }
        return !empty($this->business->streetpulse_acronym);
    }

    /**
     * Test FTP connection to StreetPulse servers
     *
     * @return array
     */
    public function testFtpConnection()
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'StreetPulse acronym not configured. Please configure in Business Settings > Integrations.'
            ];
        }

        // Try primary server first
        $result = $this->testFtpServer($this->ftpHosts['primary']);
        if ($result['success']) {
            return $result;
        }

        // Try backup server
        $result = $this->testFtpServer($this->ftpHosts['backup']);
        return $result;
    }

    /**
     * Test connection to a specific FTP server
     *
     * @param string $host
     * @return array
     */
    private function testFtpServer($host)
    {
        try {
            // Use cURL for FTP connection (more reliable than ftp_connect)
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "ftp://{$host}/",
                CURLOPT_USERPWD => "{$this->ftpUsername}:{$this->ftpPassword}",
                CURLOPT_FTP_USE_EPSV => false, // Passive mode OFF (active mode)
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FTPLISTONLY => true
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($error) {
                throw new \Exception('FTP Connection Error: ' . $error);
            }

            return [
                'success' => true,
                'msg' => 'FTP connection successful to ' . $host,
                'host' => $host
            ];
        } catch (\Exception $e) {
            Log::error('StreetPulse FTP Test Error (' . $host . '): ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'FTP connection failed to ' . $host . ': ' . $e->getMessage(),
                'host' => $host
            ];
        }
    }

    /**
     * Generate StreetPulse file and upload for a specific date
     *
     * @param string $date Date in Y-m-d format (defaults to yesterday)
     * @return array
     */
    public function syncDailySales($date = null)
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'msg' => 'StreetPulse acronym not configured.'
            ];
        }

        // Default to yesterday if no date provided
        if ($date === null) {
            $date = date('Y-m-d', strtotime('-1 day'));
        }

        // Check if already uploaded for this date
        if ($this->business->streetpulse_last_upload_date == $date) {
            return [
                'success' => false,
                'msg' => 'Data for ' . $date . ' has already been uploaded.'
            ];
        }

        try {
            // Get check digit option from api_settings
            $apiSettings = $this->businessUtil->getApiSettings($this->businessId);
            $checkDigitOption = $apiSettings['streetpulse']['check_digit_option'] ?? 'NOCHECKDIGIT';

            // Generate file
            $fileResult = $this->fileGenerator->generate(
                $this->businessId,
                $date,
                $this->business->streetpulse_acronym,
                $checkDigitOption
            );

            if (!$fileResult['success']) {
                return $fileResult;
            }

            // Upload file via FTP
            $uploadResult = $this->uploadToStreetPulse($fileResult['file_path'], $fileResult['filename'], $date);

            if ($uploadResult['success']) {
                // Update last upload date
                $this->business->streetpulse_last_upload_date = $date;
                $this->business->save();

                // Clean up old files (keep last 7 days)
                $this->cleanupOldFiles();

                return [
                    'success' => true,
                    'msg' => 'Successfully uploaded ' . $fileResult['record_count'] . ' records for ' . $date,
                    'record_count' => $fileResult['record_count'],
                    'filename' => $fileResult['filename']
                ];
            } else {
                // Delete generated file on failure
                if (file_exists($fileResult['file_path'])) {
                    unlink($fileResult['file_path']);
                }
                return $uploadResult;
            }
        } catch (\Exception $e) {
            Log::error('StreetPulse Sync Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Sync failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload file to StreetPulse FTP server
     *
     * @param string $filePath Local file path
     * @param string $filename Remote filename
     * @param string $date Date for logging
     * @return array
     */
    public function uploadToStreetPulse($filePath, $filename, $date)
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'msg' => 'File not found: ' . $filePath
            ];
        }

        // Try primary server first
        $result = $this->uploadToFtpServer($this->ftpHosts['primary'], $filePath, $filename);
        if ($result['success']) {
            Log::info('StreetPulse Upload Success (' . $date . '): ' . $filename . ' uploaded to ' . $this->ftpHosts['primary']);
            return $result;
        }

        // Try backup server
        $result = $this->uploadToFtpServer($this->ftpHosts['backup'], $filePath, $filename);
        if ($result['success']) {
            Log::info('StreetPulse Upload Success (' . $date . '): ' . $filename . ' uploaded to ' . $this->ftpHosts['backup']);
            return $result;
        }

        Log::error('StreetPulse Upload Failed (' . $date . '): Failed to upload to both servers');
        return [
            'success' => false,
            'msg' => 'Failed to upload to both FTP servers. Primary: ' . ($result['msg'] ?? 'Unknown error')
        ];
    }

    /**
     * Upload file to a specific FTP server
     *
     * @param string $host
     * @param string $filePath
     * @param string $filename
     * @return array
     */
    private function uploadToFtpServer($host, $filePath, $filename)
    {
        try {
            $fileHandle = fopen($filePath, 'r');
            if (!$fileHandle) {
                throw new \Exception('Failed to open file: ' . $filePath);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "ftp://{$host}/{$filename}",
                CURLOPT_USERPWD => "{$this->ftpUsername}:{$this->ftpPassword}",
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fileHandle,
                CURLOPT_INFILESIZE => filesize($filePath),
                CURLOPT_FTP_USE_EPSV => false, // Passive mode OFF (active mode)
                CURLOPT_TIMEOUT => 300, // 5 minutes timeout for large files
                CURLOPT_CONNECTTIMEOUT => 30
            ]);

            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fileHandle);

            if ($error) {
                throw new \Exception('FTP Upload Error: ' . $error);
            }

            return [
                'success' => true,
                'msg' => 'File uploaded successfully to ' . $host
            ];
        } catch (\Exception $e) {
            Log::error('StreetPulse FTP Upload Error (' . $host . '): ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Upload failed to ' . $host . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up old files (keep last 7 days)
     *
     * @return void
     */
    private function cleanupOldFiles()
    {
        try {
            $storagePath = storage_path('app/streetpulse');
            if (!file_exists($storagePath)) {
                return;
            }

            $files = glob($storagePath . '/*.{txt,txt.gz}', GLOB_BRACE);
            $cutoffTime = strtotime('-7 days');

            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
        } catch (\Exception $e) {
            Log::warning('StreetPulse File Cleanup Error: ' . $e->getMessage());
        }
    }

    /**
     * Get last upload date
     *
     * @return string|null
     */
    public function getLastUploadDate()
    {
        return $this->business ? $this->business->streetpulse_last_upload_date : null;
    }

    /**
     * Test connection (alias for testFtpConnection for backward compatibility)
     *
     * @return array
     */
    public function testConnection()
    {
        return $this->testFtpConnection();
    }

    /**
     * Sync sales (alias for syncDailySales for backward compatibility)
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function syncSales($startDate = null, $endDate = null)
    {
        // If date range provided, use start date
        if ($startDate) {
            return $this->syncDailySales($startDate);
        }
        // Default to yesterday
        return $this->syncDailySales();
    }
}
