<?php

namespace App\Console\Commands;

use App\Services\DistributorInvoiceIntake;
use Illuminate\Console\Command;

// Automatic distributor invoice intake: AMS portal + Alliance e-invoice
// emails -> ERP purchases. See App\Services\DistributorInvoiceIntake.
class PullDistributorInvoices extends Command
{
    protected $signature = 'distributor-invoices:pull {--days=60 : how far back to look} {--only= : ams or alliance} {--dry : read and report, import nothing}';

    protected $description = 'Pull new AMS and Alliance invoices and log them as Ordered purchases';

    public function handle()
    {
        $only = $this->option('only') ?: null;
        $report = (new DistributorInvoiceIntake())->run((int) $this->option('days'), $only, (bool) $this->option('dry'));
        if (isset($report['error'])) {
            $this->warn($report['error']);
            return 1;
        }
        foreach ($report as $r) {
            $this->line(sprintf('[%s] %s %s: %s', $r['status'] ?? '', strtoupper($r['distributor'] ?? ''), $r['invoice'] ?? '', $r['msg'] ?? ''));
        }
        $this->info(count($report) . ' entr' . (count($report) === 1 ? 'y' : 'ies') . '.');
        return 0;
    }
}
