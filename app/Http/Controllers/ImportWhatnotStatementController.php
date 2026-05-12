<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\ExpenseCategory;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Manual import of a Whatnot monthly Financial Statement (PDF).
// Whatnot doesn't expose an API, so Sabina types in 5 numbers per month
// and we record:
//   - one sell transaction with is_whatnot=1 for the total revenue
//     (so the existing /reports/whatnot picks it up)
//   - one expense per fee line (commission, payment processing, shipping)
//     in a "Whatnot Fees" category so they roll into /reports/expense-report
// Snapshot of inserted IDs goes to admin-snapshots for one-click undo.
class ImportWhatnotStatementController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = session('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('whatnot.import_statement', compact('business_locations'));
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $data = $request->validate([
            'statement_month'         => 'required|date_format:Y-m',
            'location_id'             => 'required|integer',
            'sales'                   => 'required|numeric|min:0',
            'tips'                    => 'nullable|numeric|min:0',
            'commission_fees'         => 'nullable|numeric|min:0',
            'payment_processing_fees' => 'nullable|numeric|min:0',
            'shipping_fees'           => 'nullable|numeric|min:0',
            'statement_number'        => 'nullable|string|max:50',
        ]);

        $business_id = (int) session('user.business_id');
        $user_id = (int) session('user.id');
        $location_id = (int) $data['location_id'];

        // Confirm location belongs to this business.
        $loc_ok = BusinessLocation::where('id', $location_id)
            ->where('business_id', $business_id)->exists();
        if (!$loc_ok) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Invalid location.'
            ]);
        }

        $period_end = Carbon::createFromFormat('Y-m', $data['statement_month'])->endOfMonth();
        $period_label = $period_end->format('M Y');
        $statement_no = $data['statement_number'] ?: '';
        $ref_tag = 'WNS-' . $period_end->format('Ym') . ($statement_no !== '' ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $statement_no) : '');

        $revenue = (float) $data['sales'] + (float) ($data['tips'] ?? 0);
        $commission   = (float) ($data['commission_fees'] ?? 0);
        $processing   = (float) ($data['payment_processing_fees'] ?? 0);
        $shipping     = (float) ($data['shipping_fees'] ?? 0);

        // Idempotency: if we already imported this period, refuse so we don't
        // double-count. Sarah can undo the prior one from /admin/admin-action-history
        // and re-run if she's fixing a typo.
        $exists = DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('ref_no', $ref_tag . '-REVENUE')
            ->exists();
        if ($exists) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => "A Whatnot statement for {$period_label} is already imported (ref {$ref_tag}). Undo it at /admin/admin-action-history before re-importing.",
            ]);
        }

        // Find or create the "Whatnot Fees" expense category.
        $fee_category_id = DB::table('expense_categories')
            ->where('business_id', $business_id)
            ->whereNull('parent_id')
            ->whereRaw('LOWER(name) = ?', ['whatnot fees'])
            ->value('id');
        if (!$fee_category_id) {
            $fee_category_id = DB::table('expense_categories')->insertGetId([
                'business_id' => $business_id,
                'name' => 'Whatnot Fees',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $now = now();
        $created_ids = [];

        DB::beginTransaction();
        try {
            // Revenue: one sell transaction, no line items (monthly aggregate).
            // is_whatnot=1 makes it show in /reports/whatnot.
            if ($revenue > 0) {
                $rev_id = DB::table('transactions')->insertGetId([
                    'business_id'      => $business_id,
                    'location_id'      => $location_id,
                    'type'             => 'sell',
                    'status'           => 'final',
                    'payment_status'   => 'paid',
                    'is_whatnot'       => 1,
                    'channel'          => 'whatnot',
                    'transaction_date' => $period_end->copy()->setTime(23, 59, 59),
                    'final_total'      => $revenue,
                    'total_before_tax' => $revenue,
                    'tax_amount'       => 0,
                    'additional_notes' => "Whatnot monthly statement {$period_label}" . ($statement_no !== '' ? " (#{$statement_no})" : ''),
                    'ref_no'           => $ref_tag . '-REVENUE',
                    'created_by'       => $user_id,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                $created_ids[] = ['table' => 'transactions', 'id' => $rev_id];
            }

            // Fees: one expense row per fee type, so they categorize cleanly
            // on the expense report.
            $fee_lines = [
                'Whatnot commission'        => $commission,
                'Whatnot payment processing' => $processing,
                'Whatnot shipping'          => $shipping,
            ];
            foreach ($fee_lines as $label => $amount) {
                if ($amount <= 0) continue;
                $exp_id = DB::table('transactions')->insertGetId([
                    'business_id'         => $business_id,
                    'location_id'         => $location_id,
                    'type'                => 'expense',
                    'status'              => 'final',
                    'payment_status'      => 'paid',
                    'transaction_date'    => $period_end->copy()->setTime(23, 59, 58),
                    'final_total'         => $amount,
                    'total_before_tax'    => $amount,
                    'tax_amount'          => 0,
                    'expense_category_id' => $fee_category_id,
                    'additional_notes'    => "{$label} — {$period_label}" . ($statement_no !== '' ? " (statement #{$statement_no})" : ''),
                    'ref_no'              => $ref_tag . '-' . strtoupper(preg_replace('/\s+/', '_', $label)),
                    'created_by'          => $user_id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $created_ids[] = ['table' => 'transactions', 'id' => $exp_id];
            }

            // Snapshot for one-click undo.
            $snapshotKey = 'whatnot-statement-import-' . $period_end->format('Ym') . '-' . $now->format('His');
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'action'    => 'whatnot-statement-import',
                    'timestamp' => $now->toDateTimeString(),
                    'period'    => $period_label,
                    'statement_number' => $statement_no,
                    'ref_tag'   => $ref_tag,
                    'rows'      => $created_ids,
                ], JSON_PRETTY_PRINT)
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Whatnot statement import failed: ' . $e->getMessage());
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Import failed: ' . $e->getMessage()
            ]);
        }

        $msg = "Imported Whatnot {$period_label}: revenue \$" . number_format($revenue, 2)
            . ", fees \$" . number_format($commission + $processing + $shipping, 2)
            . " (" . count($created_ids) . " transactions). Undo at /admin/admin-action-history if needed.";

        return redirect('reports/whatnot?start_date=' . $period_end->copy()->startOfMonth()->format('Y-m-d')
            . '&end_date=' . $period_end->format('Y-m-d'))
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
