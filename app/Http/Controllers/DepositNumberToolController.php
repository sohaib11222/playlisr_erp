<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Set the NEXT deposit number per store. Needed when physical deposits
 * happened before the cash_deposits log existed (e.g. Jacob's #1 and
 * Clyde's #2 were never recorded), so the counter should continue from a
 * higher number instead of restarting at #1.
 *
 * The next number is always "highest logged deposit_seq + 1", so to make
 * the next one #N we insert a single zero-amount "carryforward" marker row
 * with deposit_seq = N-1. nextDepositSeq()/recordDeposit() then naturally
 * hand out #N. Carryforward rows are hidden from the Cash Deposits report.
 * Idempotent-ish: it refuses to LOWER a counter (that would need deleting
 * real rows) and no-ops if the target is already the next number.
 */
class DepositNumberToolController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function guard()
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'Admin only.');
        }
    }

    /** Current highest logged seq per location (carryforward markers count). */
    private function maxSeqByLocation($business_id): array
    {
        if (!Schema::hasTable('cash_deposits')) {
            return [];
        }
        return DB::table('cash_deposits')
            ->where('business_id', $business_id)
            ->groupBy('location_id')
            ->select('location_id', DB::raw('MAX(deposit_seq) as max_seq'))
            ->pluck('max_seq', 'location_id')
            ->toArray();
    }

    public function index(Request $request)
    {
        $this->guard();

        $business_id = $request->session()->get('user.business_id');
        $locations   = BusinessLocation::forDropdown($business_id);
        $maxByLoc    = $this->maxSeqByLocation($business_id);

        $rows = [];
        foreach ($locations as $loc_id => $loc_name) {
            $max = (int) ($maxByLoc[$loc_id] ?? 0);
            $rows[] = [
                'id'   => $loc_id,
                'name' => $loc_name,
                'next' => $max + 1,
            ];
        }

        return view('admin.deposit_number_tool', [
            'has_table' => Schema::hasTable('cash_deposits'),
            'rows'      => $rows,
        ]);
    }

    public function run(Request $request)
    {
        $this->guard();

        $business_id = $request->session()->get('user.business_id');
        $location_id = (int) $request->input('location_id');
        $next_number = (int) $request->input('next_number');

        if (!Schema::hasTable('cash_deposits')) {
            return back()->with('status', ['success' => 0, 'msg' => 'cash_deposits table is not installed yet.']);
        }
        if ($location_id <= 0) {
            return back()->with('status', ['success' => 0, 'msg' => 'Pick a store.']);
        }
        if ($next_number < 1) {
            return back()->with('status', ['success' => 0, 'msg' => 'Next number must be 1 or higher.']);
        }

        $loc_name = (string) (BusinessLocation::where('id', $location_id)->value('name') ?: ('Store #' . $location_id));

        $currentMax = (int) DB::table('cash_deposits')
            ->where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->max('deposit_seq');
        $currentNext = $currentMax + 1;

        if ($next_number === $currentNext) {
            return back()->with('status', ['success' => 1, 'msg' => "{$loc_name} is already set to start at #{$next_number}."]);
        }
        if ($next_number < $currentNext) {
            return back()->with('status', [
                'success' => 0,
                'msg' => "Can't lower {$loc_name} to #{$next_number} — it already has deposits up to #{$currentMax}. The lowest you can set is #{$currentNext}.",
            ]);
        }

        // Insert one carryforward marker so MAX(deposit_seq) becomes
        // next_number - 1; the next real deposit then lands on next_number.
        $now = \Carbon::now()->format('Y-m-d H:i:s');
        try {
            DB::table('cash_deposits')->insert([
                'business_id'      => $business_id,
                'location_id'      => $location_id,
                'cash_register_id' => null,
                'user_id'          => auth()->user()->id,
                'cashier_name'     => 'Carried forward (un-logged deposits)',
                'deposit_seq'      => $next_number - 1,
                'amount'           => 0,
                'phase'            => 'carryforward',
                'deposited_at'     => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        } catch (\Throwable $e) {
            return back()->with('status', ['success' => 0, 'msg' => 'Failed: ' . $e->getMessage()]);
        }

        return back()->with('status', [
            'success' => 1,
            'msg' => "Done — the next deposit at {$loc_name} will be #{$next_number}.",
        ]);
    }
}
