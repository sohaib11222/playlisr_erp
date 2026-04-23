<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class NivessaBackendImportController extends Controller
{
    const COMMANDS = [
        'Store Credit' => 'nivessa:import-store-credit',
        'Customer Asks' => 'nivessa:import-customer-asks',
        'Historical Sales' => 'nivessa:import-historical-sales',
    ];

    public function index()
    {
        return view('admin.nivessa_backend_import');
    }

    public function chunk(Request $request)
    {
        $sessionId = $this->safeSession($request->input('session_id'));
        $index = (int) $request->input('index', 0);

        $dir = $this->chunkDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . $sessionId . '.xlsx';

        if ($index === 0) {
            @unlink($target);
        }

        $raw = $request->getContent();
        if ($raw === '' || $raw === false) {
            return response()->json(['ok' => false, 'error' => 'empty chunk'], 400);
        }
        $bytes = file_put_contents($target, $raw, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            return response()->json(['ok' => false, 'error' => 'write failed'], 500);
        }

        return response()->json([
            'ok' => true,
            'session_id' => $sessionId,
            'index' => $index,
            'size' => @filesize($target) ?: 0,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        $request->validate(['session_id' => 'required|string']);

        $sessionId = $this->safeSession($request->input('session_id'));
        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);

        $filePath = $this->chunkDir() . '/' . $sessionId . '.xlsx';
        if (!is_file($filePath)) {
            return response('No uploaded file found for session ' . $sessionId . ". Upload the xlsx first.\n", 400)
                ->header('Content-Type', 'text/plain');
        }

        $phpPath = (new PhpExecutableFinder())->find(false) ?: 'php';

        return response()->stream(function () use ($phpPath, $filePath, $commit) {
            echo '[xlsx: ' . $filePath . ' · ' . number_format(filesize($filePath)) . " bytes]\n";
            echo ($commit ? '[MODE: --commit — writing to DB]' : '[MODE: dry-run — no writes]') . "\n\n";
            @ob_flush(); @flush();

            try {
                foreach (self::COMMANDS as $label => $cmd) {
                    echo "════════════════════════════════════════════════════════════\n";
                    echo " " . $label . " (" . $cmd . ")\n";
                    echo "════════════════════════════════════════════════════════════\n";
                    @ob_flush(); @flush();

                    // -d memory_limit=2048M: PhpSpreadsheet on a 23MB xlsx can need 1-2GB
                    $args = [$phpPath, '-d', 'memory_limit=2048M', base_path('artisan'), $cmd, $filePath];
                    if ($commit) {
                        $args[] = '--commit';
                    }

                    $process = new Process($args, base_path());
                    $process->setTimeout(null);
                    $process->setIdleTimeout(null);
                    $process->start();

                    // Poll with heartbeats so nginx (default 60s proxy_read_timeout) doesn't
                    // kill the stream while PhpSpreadsheet is silently parsing the xlsx.
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
                        usleep(500000);
                    }
                    $tail = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
                    if ($tail !== '') {
                        echo $tail;
                    }
                    echo "\n[" . $label . " exit code: " . $process->getExitCode() . "]\n\n";
                    @ob_flush(); @flush();
                }
                echo "════════════════════════════════════════════════════════════\n";
                echo " all done\n";
                echo "════════════════════════════════════════════════════════════\n";
            } catch (\Throwable $e) {
                echo "\n[error: " . $e->getMessage() . "]\n";
            } finally {
                @unlink($filePath);
            }
            @ob_flush(); @flush();
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function chunkDir(): string
    {
        return storage_path('app/nivessa_backend');
    }

    private function safeSession($id): string
    {
        $id = (string) $id;
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $id)) {
            abort(400, 'invalid session_id');
        }
        return $id;
    }
}
