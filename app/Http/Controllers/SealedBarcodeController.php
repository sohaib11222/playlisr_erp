<?php

namespace App\Http\Controllers;

use App\Services\SealedBarcodeBackfillService as S;
use Illuminate\Http\Request;

/** Preview + Apply for Discogs barcodes on sealed items (Sarah, 2026-10-19). */
class SealedBarcodeController extends Controller
{
    private function guard()
    {
        if (!auth()->user()->can('product.update')) abort(403, 'Unauthorized action.');
    }

    public function index(Request $request)
    {
        $this->guard();
        $biz = (int) $request->session()->get('user.business_id');
        $log = S::load();
        $counts = array_count_values(array_map(fn($r) => $r['status'] ?? '', $log));
        $remaining = S::eligible($biz)->filter(fn($p) => !isset($log[$p->id]) || ($log[$p->id]['status'] ?? '') === 'error')->count();
        $proposals = S::proposals($biz);
        return view('product.sealed_barcodes', compact('counts', 'remaining', 'proposals'));
    }

    /** Start a background Discogs check now instead of waiting for :07 / :37. */
    public function scanNow(Request $request)
    {
        $this->guard();
        if (\Cache::add('sealed-barcodes-scan-running', 1, 600)) {
            // Same launch as CloverController: PHP_BINARY is php-fpm under the web server
            $php = PHP_BINARY;
            if (!$php || preg_match('/fpm|cgi/i', basename($php))) $php = 'php';
            $cmd = 'cd ' . escapeshellarg(base_path()) . ' && ' . escapeshellcmd($php) . ' artisan sealed-barcodes:scan --limit=400';
            @shell_exec('nohup bash -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 & disown');
            $msg = 'Checking Discogs now. Refresh in a few minutes to see results.';
        } else {
            $msg = 'A check is already running. Refresh in a few minutes.';
        }
        return redirect('/products/sealed-barcodes')->with('status', ['success' => 1, 'msg' => $msg]);
    }

    public function apply(Request $request)
    {
        $this->guard();
        $u = auth()->user();
        $n = $request->input('undo') ? S::undo() : S::apply((int) $request->session()->get('user.business_id'), trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));
        return redirect('/products/sealed-barcodes')->with('status', ['success' => 1, 'msg' => ($request->input('undo') ? 'Put back the old SKU on ' : 'Added barcodes to ') . $n . ' products.']);
    }
}
