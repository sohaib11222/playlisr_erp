<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        $request->validate([
            'xlsx' => 'required|file|mimes:xlsx,xls|max:102400',
            'import_type' => 'required|in:sales,store_credit,customer_asks',
        ]);

        $type = $request->input('import_type');
        $commit = $request->boolean('commit');
        $onlySheet = trim((string) $request->input('only_sheet', ''));
        $taxRate = trim((string) $request->input('tax_rate', ''));

        $dir = storage_path('app/nivessa_backend');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $filename = 'upload_' . time() . '_' . bin2hex(random_bytes(4)) . '.xlsx';
        $filePath = $dir . '/' . $filename;
        $request->file('xlsx')->move($dir, $filename);

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

        $cleanup = function () use ($filePath) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        };

        return response()->stream(function () use ($process, $args, $cleanup) {
            echo '$ ' . implode(' ', array_map(function ($a) {
                return strpos($a, ' ') !== false ? '"' . $a . '"' : $a;
            }, $args)) . "\n\n";
            @ob_flush();
            @flush();

            try {
                $process->start();
                foreach ($process as $type => $data) {
                    echo $data;
                    @ob_flush();
                    @flush();
                }
                echo "\n[exit code: " . $process->getExitCode() . "]\n";
            } catch (\Throwable $e) {
                echo "\n[error: " . $e->getMessage() . "]\n";
            } finally {
                $cleanup();
            }
            @ob_flush();
            @flush();
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
