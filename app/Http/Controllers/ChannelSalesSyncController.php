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
        return view('admin.channel_sales_sync');
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
