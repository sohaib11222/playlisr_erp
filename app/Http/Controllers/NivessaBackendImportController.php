<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class NivessaBackendImportController extends Controller
{
    const COMMANDS = [
        'sales' => 'nivessa:import-historical-sales',
        'store_credit' => 'nivessa:import-store-credit',
        'customer_asks' => 'nivessa:import-customer-asks',
    ];

    public function index()
    {
        return view('admin.nivessa_backend_import');
    }

    public function chunk(Request $request)
    {
        $sessionId = $this->safeSession($request->input('session_id'));
        $index = (int) $request->input('index', 0);
        $final = $request->boolean('final');

        $dir = $this->chunkDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . $sessionId . '.xlsx';

        // First chunk (index=0): truncate any stale file so re-uploads don't append.
        if ($index === 0) {
            @unlink($target);
        }

        // Raw request body is the bytes for this chunk.
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
            'final' => $final,
            'size' => @filesize($target) ?: 0,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        $request->validate([
            'session_id' => 'required|string',
            'import_type' => 'required|in:sales,store_credit,customer_asks',
        ]);

        $sessionId = $this->safeSession($request->input('session_id'));
        $type = $request->input('import_type');
        $commit = $request->boolean('commit');
        $onlySheet = trim((string) $request->input('only_sheet', ''));
        $taxRate = trim((string) $request->input('tax_rate', ''));

        $filePath = $this->chunkDir() . '/' . $sessionId . '.xlsx';
        if (!is_file($filePath)) {
            return response('No uploaded file found for session ' . $sessionId . ". Upload the xlsx first.\n", 400)
                ->header('Content-Type', 'text/plain');
        }

        $phpPath = (new PhpExecutableFinder())->find(false) ?: 'php';
        $args = [$phpPath, base_path('artisan'), self::COMMANDS[$type], $filePath];
        if ($commit) {
            $args[] = '--commit';
        }
        if ($type === 'sales' && $onlySheet !== '') {
            $args[] = '--only-sheet=' . $onlySheet;
        }
        if ($type === 'sales' && $taxRate !== '') {
            $args[] = '--tax-rate=' . $taxRate;
        }

        $process = new Process($args, base_path());
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        return response()->stream(function () use ($process, $args, $filePath) {
            echo '$ ' . implode(' ', array_map(function ($a) {
                return strpos($a, ' ') !== false ? '"' . $a . '"' : $a;
            }, $args)) . "\n";
            echo '[xlsx: ' . $filePath . ' · ' . number_format(filesize($filePath)) . " bytes]\n\n";
            @ob_flush();
            @flush();

            try {
                $process->start();
                foreach ($process as $_ => $data) {
                    echo $data;
                    @ob_flush();
                    @flush();
                }
                echo "\n[exit code: " . $process->getExitCode() . "]\n";
            } catch (\Throwable $e) {
                echo "\n[error: " . $e->getMessage() . "]\n";
            } finally {
                @unlink($filePath);
            }
            @ob_flush();
            @flush();
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
