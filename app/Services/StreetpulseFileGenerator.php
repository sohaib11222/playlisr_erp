<?php

namespace App\Services;

use App\TransactionSellLine;
use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StreetpulseFileGenerator
{
    /**
     * Generate SPULSE02 format file for a specific date
     *
     * @param int $businessId
     * @param string $date Date in Y-m-d format
     * @param string $acronym StreetPulse store acronym
     * @param string $checkDigitOption CHECKDIGIT or NOCHECKDIGIT
     * @param int|null $locationId Optional location ID to filter by location
     * @return array ['success' => bool, 'file_path' => string, 'record_count' => int, 'msg' => string]
     */
    public function generate($businessId, $date, $acronym, $checkDigitOption = 'NOCHECKDIGIT', $locationId = null)
    {
        try {
            // Get sales data for the date
            $salesData = $this->getSalesDataForDate($businessId, $date, $locationId);
            
            if (empty($salesData)) {
                return [
                    'success' => false,
                    'msg' => 'No sales data found for date: ' . $date
                ];
            }

            // Create storage directory if it doesn't exist
            $storagePath = storage_path('app/streetpulse');
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            // Generate filename: {acronym}-{YYYYMMDD}.txt
            $dateFormatted = date('Ymd', strtotime($date));
            $filename = strtoupper($acronym) . '-' . $dateFormatted . '.txt';
            $filePath = $storagePath . '/' . $filename;

            // Open file for writing
            $file = fopen($filePath, 'w');
            if (!$file) {
                return [
                    'success' => false,
                    'msg' => 'Failed to create file: ' . $filePath
                ];
            }

            // Write file header
            fwrite($file, "SPULSE02\n");
            fwrite($file, "Acronym\tOptions\n");
            fwrite($file, strtoupper($acronym) . "\t" . $checkDigitOption . "\n");
            fwrite($file, "UPC\tTimestamp\tUsed\tCount\n");

            $recordCount = 0;
            foreach ($salesData as $sale) {
                $upc = $this->extractUPC($sale['upc'], $checkDigitOption);
                
                // Skip if no valid UPC
                if (empty($upc) || !is_numeric($upc)) {
                    continue;
                }

                $timestamp = $this->formatTimestamp($sale['timestamp']);
                $used = $sale['used'] ?? 0;
                $count = 1;

                // Write data line (tab-separated)
                fwrite($file, $upc . "\t" . $timestamp . "\t" . $used . "\t" . $count . "\n");
                $recordCount++;
            }

            // Write EOF line (required by StreetPulse)
            fwrite($file, "EOF\n");
            fclose($file);

            // Compress if >10,000 records
            if ($recordCount > 10000) {
                $compressedPath = $filePath . '.gz';
                $gzFile = gzopen($compressedPath, 'w9');
                if ($gzFile) {
                    $sourceFile = fopen($filePath, 'r');
                    while (!feof($sourceFile)) {
                        gzwrite($gzFile, fread($sourceFile, 8192));
                    }
                    fclose($sourceFile);
                    gzclose($gzFile);
                    unlink($filePath); // Remove uncompressed file
                    $filePath = $compressedPath;
                    $filename = $filename . '.gz';
                }
            }

            return [
                'success' => true,
                'file_path' => $filePath,
                'filename' => $filename,
                'record_count' => $recordCount,
                'msg' => 'File generated successfully with ' . $recordCount . ' records'
            ];
        } catch (\Exception $e) {
            Log::error('StreetPulse File Generation Error: ' . $e->getMessage());
            return [
                'success' => false,
                'msg' => 'Error generating file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get sales data for a specific date
     *
     * @param int $businessId
     * @param string $date Date in Y-m-d format
     * @param int|null $locationId Optional location ID to filter by location
     * @return array
     */
    private function getSalesDataForDate($businessId, $date, $locationId = null)
    {
        $startDate = $date . ' 00:00:00';
        $endDate = $date . ' 23:59:59';

        // Query transaction_sell_lines for the date range
        $query = TransactionSellLine::join('transactions', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
            ->leftJoin('variations', 'transaction_sell_lines.variation_id', '=', 'variations.id')
            ->leftJoin('products', 'transaction_sell_lines.product_id', '=', 'products.id')
            ->where('transactions.business_id', $businessId)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->whereNull('transaction_sell_lines.parent_sell_line_id'); // Only main line items, not modifiers
        
        // Filter by location if provided
        if ($locationId !== null) {
            $query->where('transactions.location_id', $locationId);
        }
        
        $sellLines = $query->select(
                'transaction_sell_lines.id',
                'transaction_sell_lines.product_id',
                'transaction_sell_lines.variation_id',
                'transactions.transaction_date',
                'transactions.created_at',
                'variations.sub_sku as variation_sku',
                'products.sku as product_sku'
            )
            ->get();

        $salesData = [];
        foreach ($sellLines as $line) {
            // Get UPC from variation or product
            $upc = $line->variation_sku ?? $line->product_sku ?? null;
            
            if (empty($upc)) {
                continue;
            }

            // Get timestamp - use transaction_date if it has time, otherwise use created_at
            $timestamp = $line->transaction_date;
            if (strlen($timestamp) <= 10) {
                // transaction_date is date only, use created_at for time
                $createdAt = $line->created_at;
                if ($createdAt) {
                    $timestamp = date('Y-m-d', strtotime($timestamp)) . ' ' . date('H:i:s', strtotime($createdAt));
                } else {
                    $timestamp = $timestamp . ' 00:00:00';
                }
            }

            $salesData[] = [
                'upc' => $upc,
                'timestamp' => $timestamp,
                'used' => 0 // Default to 0 (new items)
            ];
        }

        return $salesData;
    }

    /**
     * Extract and clean UPC from SKU
     *
     * @param string $sku
     * @param string $checkDigitOption CHECKDIGIT or NOCHECKDIGIT
     * @return string
     */
    private function extractUPC($sku, $checkDigitOption)
    {
        if (empty($sku)) {
            return '';
        }

        // Remove any non-numeric characters
        $upc = preg_replace('/[^0-9]/', '', $sku);

        // If NOCHECKDIGIT and UPC is 12 or 13 digits, remove last digit (check digit)
        if ($checkDigitOption === 'NOCHECKDIGIT') {
            if (strlen($upc) == 12 || strlen($upc) == 13) {
                $upc = substr($upc, 0, -1);
            }
        }

        // Ensure UPC is between 11-14 digits (StreetPulse requirement)
        if (strlen($upc) < 11 || strlen($upc) > 14) {
            return '';
        }

        return $upc;
    }

    /**
     * Format timestamp to yyyy-mm-dd HH:mm:ss
     *
     * @param string $dateTime
     * @return string
     */
    private function formatTimestamp($dateTime)
    {
        try {
            $timestamp = strtotime($dateTime);
            if ($timestamp === false) {
                return date('Y-m-d H:i:s');
            }
            return date('Y-m-d H:i:s', $timestamp);
        } catch (\Exception $e) {
            return date('Y-m-d H:i:s');
        }
    }
}
