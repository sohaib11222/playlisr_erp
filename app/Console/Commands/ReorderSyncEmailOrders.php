<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\Services\EmailInboxService;
use App\Services\ReorderService;
use Illuminate\Console\Command;

/**
 * RedEye order confirmations -> "Mark as ordered" on the Weekly Reorder page
 * (Sarah 10/13). Reads the mailboxes saved on Communications > Email Sync
 * Settings (orders@ gets RedEye's "Redeye Order Confirmation #N" emails).
 */
class ReorderSyncEmailOrders extends Command
{
    protected $signature = 'reorder:sync-email-orders {--days=3}';
    protected $description = 'Record RedEye order confirmation emails as ordered on the Weekly Reorder page';

    public function handle(ReorderService $svc, EmailInboxService $mail)
    {
        $business_id = (int) \App\Business::orderBy('id')->value('id');
        if (!$mail->isConfigured()) {
            $this->info('No mailbox connected (Communications > Email Sync Settings).');
            return;
        }
        $seen = 0;
        $recorded = 0;
        foreach ($mail->mailboxes() as $account) {
            foreach ($mail->fetchRecent($account, 'INBOX', (int) $this->option('days')) as $msg) {
                if (stripos($msg['from'] ?? '', 'redeyeworldwide.com') === false) continue;
                if (!preg_match('/Order Confirmation #\s*(\d+)/i', $msg['subject'] ?? '', $m)) continue;
                $seen++;
                $body = preg_replace('/\s+/', ' ', strip_tags((string) ($msg['body'] ?? '')));
                // "5055274703541 CD-AMB-9322 2015-01-13 Aphex Twin Selected Ambient Works 3 $10.00"
                preg_match_all('/\b(\d{11,14})\s+[A-Z0-9][A-Z0-9-]*\s+\d{4}-\d{2}-\d{2}\s+(.+?)\s+(\d{1,4})\s+\$\d+\.\d{2}/', $body, $rows, PREG_SET_ORDER);
                $lines = [];
                foreach ($rows as $r) {
                    $lines[] = ['upc' => $r[1], 'qty' => (int) $r[3], 'title' => trim($r[2])];
                }
                $store = stripos($body, 'pico') !== false && stripos($body, 'hollywood') === false ? 'pico' : 'hollywood';
                $loc = BusinessLocation::where('business_id', $business_id)->where('name', 'like', '%' . $store . '%')->value('id');
                if (!$loc || !$lines) continue;
                try {
                    $at = \Carbon\Carbon::parse($msg['date'])->setTimezone('America/Los_Angeles')->toDateTimeString();
                } catch (\Throwable $e) {
                    $at = now()->toDateTimeString();
                }
                if ($at < '2026-10-13 00:00:00') continue; // earlier orders were recorded by hand
                $recorded += $svc->recordAutoOrder($business_id, (int) $loc, 'redeye:' . $m[1], $at,
                    'RedEye order #' . $m[1], $lines, 'Redeye');
            }
        }
        $this->info($seen . ' RedEye confirmations seen, ' . $recorded . ' recorded.');
    }
}
