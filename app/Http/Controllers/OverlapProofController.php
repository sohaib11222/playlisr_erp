<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only PROOF of whether the pre-existing (live POS) register sales overlap
// the imported sheet. For each of the 4 baseline months it matches every
// register sale's pre-tax price against the sheet's prices as a MULTISET: a
// register sale counts as "also in the sheet" only if the sheet still has an
// unconsumed row at that exact price. High overlap => those sales are
// double-counted (trim them); low overlap => they're separate (keep). Pure
// arithmetic on real rows — nothing written, nothing inferred.
class OverlapProofController extends Controller
{
    // location needle, YYYY-MM, label, parked sheet json (pre-tax prices)
    const CELLS = [
        ['hollywood', '2024-11', 'Hollywood - Nov 2024', 'parked_nivessa_backend_sales_hw_november_24_sales.json'],
        ['hollywood', '2025-01', 'Hollywood - Jan 2025', 'parked_nivessa_backend_sales_hw_sales_jan_25.json'],
        ['pico',      '2025-04', 'Pico - Apr 2025',      'parked_nivessa_backend_sales_pico_april_25.json'],
        ['pico',      '2025-05', 'Pico - May 2025',      'parked_nivessa_backend_sales_pico_may_2025.json'],
    ];

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $cells = [];

        foreach (self::CELLS as [$needle, $month, $label, $file]) {
            $loc = DB::table('business_locations')->where('business_id', $businessId)
                ->where('name', 'like', '%' . $needle . '%')->orderBy('id')->first(['id']);
            if (!$loc) { continue; }

            // Sheet prices (pre-tax), as a multiset keyed by cents.
            $sheet = [];
            $path = app_path('Services/data/parked/' . $file);
            if (is_file($path)) {
                foreach ((json_decode((string) file_get_contents($path), true) ?: []) as $it) {
                    $c = (int) round(((float) $it['price']) * 100);
                    if ($c > 0) { $sheet[$c] = ($sheet[$c] ?? 0) + 1; }
                }
            }

            // Live-POS register sales (no import_source), pre-tax price each.
            $pos = DB::table('transactions')
                ->where('business_id', $businessId)->where('location_id', $loc->id)
                ->where('type', 'sell')->where('status', 'final')->whereNull('import_source')
                ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
                ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$month])
                ->get(['total_before_tax', 'final_total']);

            $matchCnt = 0; $matchVal = 0.0; $uniqCnt = 0; $uniqVal = 0.0;
            foreach ($pos as $t) {
                $pre = $t->total_before_tax !== null ? (float) $t->total_before_tax : ((float) $t->final_total) / 1.0975;
                $c = (int) round($pre * 100);
                if (($sheet[$c] ?? 0) > 0) {
                    $sheet[$c]--; $matchCnt++; $matchVal += (float) $t->final_total;
                } else {
                    $uniqCnt++; $uniqVal += (float) $t->final_total;
                }
            }

            $cells[] = [
                'label' => $label,
                'posCnt' => $pos->count(),
                'posVal' => (float) $pos->sum('final_total'),
                'matchCnt' => $matchCnt, 'matchVal' => $matchVal,
                'uniqCnt' => $uniqCnt, 'uniqVal' => $uniqVal,
            ];
        }

        return view('admin.overlap_proof', ['cells' => $cells]);
    }
}
