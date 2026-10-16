<?php

namespace App\Console\Commands;

use App\Http\Controllers\BinMoveController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily, before opening: records bought at Pico and shelved in Hollywood
 * warehouse bins never got moved in the ERP, so they showed as Pico stock
 * and went to Pico pulls (61 of them, 8/15 to 9/22/26). This moves any new
 * ones to Hollywood with one stock transfer, same as the Bin Store Check
 * button (backup + Undo there). Sarah, 2026-10-16.
 */
class BinMoveToHollywood extends Command
{
    protected $signature = 'bin:move-to-hollywood';
    protected $description = 'Move Hollywood-bin records stocked at Pico to Hollywood';

    public function handle()
    {
        $business = DB::table('business')->orderBy('id')->first(['id', 'owner_id']);
        if (!$business) return 0;
        $r = app(BinMoveController::class)->runMove((int) $business->id, (int) $business->owner_id,
            'Daily bin check: records in Hollywood bins that were stocked at Pico');
        $msg = $r['error'] ?: ("Moved {$r['moved']}" . ($r['ref'] ? " ({$r['ref']})" : ''));
        $this->info($msg);
        \Log::info('bin:move-to-hollywood ' . $msg);
        return 0;
    }
}
