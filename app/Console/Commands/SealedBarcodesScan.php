<?php

namespace App\Console\Commands;

use App\Services\SealedBarcodeBackfillService;
use Illuminate\Console\Command;

/** Looks up barcodes for sealed items on Discogs (no product writes). Apply is a button at /products/sealed-barcodes. */
class SealedBarcodesScan extends Command
{
    protected $signature = 'sealed-barcodes:scan {--business=1} {--limit=300}';
    protected $description = 'Find Discogs barcodes for sealed products without one (read-only, saves proposals)';

    public function handle()
    {
        @set_time_limit(0);
        $r = SealedBarcodeBackfillService::scan((int) $this->option('business'), (int) $this->option('limit'));
        $this->info(json_encode($r));
        return 0;
    }
}
