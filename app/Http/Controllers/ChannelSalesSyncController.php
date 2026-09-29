<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Browser-based runner for the channel sales syncs (Sarah has no SSH).
 * Streams artisan output back to the page so she can dry-run, eyeball the
 * counts, then commit.
 *
 *   /admin/channel-sales-sync          → page
 *   /admin/channel-sales-sync/web      → nivessa:sync-web-sales
 *   /admin/channel-sales-sync/discogs  → nivessa:sync-discogs-sales
 */
class ChannelSalesSyncController extends Controller
{
    public function index()
    {
        $webhook = self::alertWebhook();
        $masked = $webhook !== '' ? '…' . substr($webhook, -10) : '';
        return view('admin.channel_sales_sync', compact('masked'));
    }

    /** Where the sync-failure alerts post. Owned by this page, not by
     *  register-recon or #shift-notes — a failed sync is not a cashier note. */
    public static function alertWebhook(): string
    {
        try {
            $file = storage_path('app/channel-sales-sync/settings.json');
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true) ?: [];
                return trim((string) ($data['alert_webhook'] ?? ''));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    public function saveWebhook(Request $request)
    {
        $url = trim((string) $request->input('alert_webhook'));
        if ($url !== '' && !preg_match('~^https://hooks\.slack\.com/~i', $url)) {
            return back()->with('status', 'That does not look like a Slack webhook URL.');
        }
        $dir = storage_path('app/channel-sales-sync');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        file_put_contents($dir . '/settings.json', json_encode(['alert_webhook' => $url], JSON_PRETTY_PRINT));
        return back()->with('status', $url === '' ? 'Alert webhook cleared.' : 'Alert webhook saved.');
    }

    public function runWeb(Request $request)
    {
        return $this->stream('nivessa:sync-web-sales', $request);
    }

    public function runDiscogs(Request $request)
    {
        return $this->stream('nivessa:sync-discogs-sales', $request);
    }

    private function stream($command, Request $request)
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $days = (int) $request->input('days', 120);
        if ($days < 1) { $days = 1; }
        if ($days > 3650) { $days = 3650; }

        $phpPath = (new PhpExecutableFinder())->find(false) ?: 'php';

        return response()->stream(function () use ($phpPath, $command, $commit, $days) {
            echo ($commit ? '[MODE: --commit — writing to DB]' : '[MODE: dry-run — no writes]') . "\n";
            echo '[command: ' . $command . ' --days=' . $days . "]\n\n";
            @ob_flush(); @flush();

            try {
                $args = [$phpPath, base_path('artisan'), $command, '--days=' . $days];
                if ($commit) { $args[] = '--commit'; }

                $process = new Process($args, base_path());
                $process->setTimeout(null);
                $process->setIdleTimeout(null);
                $process->start();

                $lastHeartbeat = time();
                while ($process->isRunning()) {
                    $chunk = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
                    if ($chunk !== '') {
                        echo $chunk;
                        $lastHeartbeat = time();
                    } elseif (time() - $lastHeartbeat >= 20) {
                        echo '.';
                        $lastHeartbeat = time();
                    }
                    @ob_flush(); @flush();
                    usleep(400000);
                }
                $tail = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
                if ($tail !== '') { echo $tail; }
                echo "\n[exit code: " . $process->getExitCode() . "]\n";
            } catch (\Throwable $e) {
                echo "\n[error: " . $e->getMessage() . "]\n";
            }
            @ob_flush(); @flush();
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
