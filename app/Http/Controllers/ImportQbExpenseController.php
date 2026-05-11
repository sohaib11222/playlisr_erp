<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\ExpenseCategory;
use App\Transaction;
use DB;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Imports a QuickBooks "Transaction List by Date" export (CSV / XLSX) and
// creates ERP expense transactions. Splits negative amounts into type=expense
// and positive amounts into type=expense_refund so the existing /reports/
// expense-report rolls them up correctly.
//
// Snapshot of inserted transaction IDs is written to admin-snapshots so the
// whole import can be undone from /admin/admin-action-history.
class ImportQbExpenseController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = session('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('expense.import_qb', compact('business_locations'));
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('expense.add')) {
            abort(403, 'Unauthorized action.');
        }

        $request->validate([
            'qb_file' => 'required|file|mimes:csv,txt,xlsx,xls',
            'default_location_id' => 'required|integer',
        ]);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $business_id = (int) session('user.business_id');
        $user_id = (int) session('user.id');
        $default_location_id = (int) $request->input('default_location_id');

        // Confirm location belongs to this business — guard against URL tampering.
        $loc_ok = BusinessLocation::where('id', $default_location_id)
            ->where('business_id', $business_id)
            ->exists();
        if (!$loc_ok) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Invalid location.'
            ]);
        }

        try {
            $parsed = Excel::toArray([], $request->file('qb_file'));
        } catch (\Exception $e) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Could not parse file: ' . $e->getMessage()
            ]);
        }

        if (empty($parsed) || empty($parsed[0])) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'File is empty.'
            ]);
        }

        $rows = $parsed[0];

        // Find header row (QB sometimes prefixes report title / date range).
        // Look for a row containing both "date" and "amount" cells.
        $header_idx = null;
        $headers = null;
        foreach ($rows as $i => $row) {
            $lc = array_map(function ($v) { return strtolower(trim((string) $v)); }, $row);
            if (in_array('date', $lc, true) && in_array('amount', $lc, true)) {
                $header_idx = $i;
                $headers = $lc;
                break;
            }
        }
        if ($header_idx === null) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Could not find header row (need Date and Amount columns).'
            ]);
        }

        // Map our expected fields to column indexes. QB column names can vary
        // a bit by export; accept the common variants.
        $col = function ($candidates) use ($headers) {
            foreach ($candidates as $name) {
                $idx = array_search($name, $headers, true);
                if ($idx !== false) return $idx;
            }
            return null;
        };

        $idx = [
            'date'    => $col(['date', 'transaction date', 'trans date']),
            'type'    => $col(['transaction type', 'type']),
            'num'     => $col(['num', 'no.', 'number', 'ref no', 'ref#']),
            'posting' => $col(['posting', 'posting (y/n)', 'posted']),
            'name'    => $col(['name', 'vendor', 'payee']),
            'memo'    => $col(['memo', 'memo/description', 'description', 'notes']),
            'account' => $col(['account name', 'account']),
            'split'   => $col(['split', 'category', 'expense category']),
            'amount'  => $col(['amount', 'total', 'total amount']),
        ];
        if ($idx['date'] === null || $idx['amount'] === null) {
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Header row found but Date or Amount column missing.'
            ]);
        }

        // Preload existing categories to avoid an N+1 lookup loop.
        $category_cache = ExpenseCategory::where('business_id', $business_id)
            ->whereNull('parent_id')
            ->pluck('id', 'name')
            ->toArray();
        // Case-insensitive lookup helper.
        $find_or_create_category = function ($name) use (&$category_cache, $business_id, $user_id) {
            $name = trim((string) $name);
            if ($name === '') return null;
            foreach ($category_cache as $cname => $cid) {
                if (strcasecmp($cname, $name) === 0) return $cid;
            }
            $cat = ExpenseCategory::create([
                'business_id' => $business_id,
                'name' => $name,
            ]);
            $category_cache[$name] = $cat->id;
            return $cat->id;
        };

        $created_ids = [];
        $skipped = 0;
        $skipped_reasons = [];
        $now = now();
        $import_tag = 'QBI-' . $now->format('YmdHis');
        $seq = 0;

        DB::beginTransaction();
        try {
            $data_rows = array_slice($rows, $header_idx + 1);
            foreach ($data_rows as $r_i => $r) {
                $get = function ($k) use ($r, $idx) {
                    return $idx[$k] === null ? null : ($r[$idx[$k]] ?? null);
                };

                $date_raw = trim((string) $get('date'));
                $amount_raw = trim((string) $get('amount'));
                if ($date_raw === '' && $amount_raw === '') {
                    continue; // blank row
                }

                // Posting filter — skip non-posting rows if column exists.
                $posting = strtolower(trim((string) $get('posting')));
                if ($posting !== '' && in_array($posting, ['no', 'n', '0', 'false'], true)) {
                    $skipped++;
                    continue;
                }

                $date = $this->parseQbDate($date_raw);
                if ($date === null) {
                    $skipped++;
                    $skipped_reasons[] = "Row " . ($header_idx + 2 + $r_i) . ": unparseable date '$date_raw'";
                    continue;
                }

                $amount = $this->parseQbAmount($amount_raw);
                if ($amount === null) {
                    $skipped++;
                    $skipped_reasons[] = "Row " . ($header_idx + 2 + $r_i) . ": unparseable amount '$amount_raw'";
                    continue;
                }
                if ($amount == 0.0) {
                    $skipped++;
                    continue; // ignore zero-amount rows
                }

                // Negative = money out = expense; positive = money in = refund.
                $type = $amount < 0 ? 'expense' : 'expense_refund';
                $final_total = abs($amount);

                $category_name = trim((string) $get('split'));
                if ($category_name === '') {
                    $category_name = trim((string) $get('account'));
                }
                $category_id = $category_name === '' ? null : $find_or_create_category($category_name);

                $name = trim((string) $get('name'));
                $memo = trim((string) $get('memo'));
                $num = trim((string) $get('num'));

                // transactions.expense_for is a FK to users.id (per-employee
                // reimbursement field), NOT free-text vendor. So pack the QB
                // "Name" into additional_notes along with the memo.
                $notes_parts = [];
                if ($name !== '') $notes_parts[] = 'Vendor: ' . $name;
                if ($memo !== '') $notes_parts[] = $memo;
                $additional_notes = !empty($notes_parts) ? implode(' · ', $notes_parts) : null;

                $seq++;
                $ref_no = $num !== '' ? ($import_tag . '-' . $num) : ($import_tag . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT));

                $tx_id = DB::table('transactions')->insertGetId([
                    'business_id'         => $business_id,
                    'location_id'         => $default_location_id,
                    'type'                => $type,
                    'status'              => 'final',
                    'payment_status'      => 'paid',
                    'transaction_date'    => $date,
                    'final_total'         => $final_total,
                    'total_before_tax'    => $final_total,
                    'tax_amount'          => 0,
                    'expense_category_id' => $category_id,
                    'additional_notes'    => $additional_notes,
                    'ref_no'              => $ref_no,
                    'created_by'          => $user_id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);

                $created_ids[] = $tx_id;
            }

            // Snapshot for /admin/admin-action-history undo.
            $snapshotKey = 'qb-expense-import-' . $now->format('Ymd-His');
            $snapshot = [
                'action'    => 'qb-expense-import',
                'timestamp' => $now->toDateTimeString(),
                'import_tag' => $import_tag,
                'inserted_by' => $user_id,
                'rows'      => array_map(function ($id) { return ['id' => $id]; }, $created_ids),
            ];
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode($snapshot, JSON_PRETTY_PRINT)
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('QB expense import failed: ' . $e->getMessage());
            return redirect()->back()->with('status', [
                'success' => 0, 'msg' => 'Import failed: ' . $e->getMessage()
            ]);
        }

        $created_count = count($created_ids);
        $msg = "Imported $created_count expense rows.";
        if ($skipped > 0) {
            $msg .= " Skipped $skipped row(s) (blank, zero, non-posting, or unparseable).";
        }
        $msg .= ' Undo at /admin/admin-action-history if needed.';

        return redirect('expenses')->with('status', [
            'success' => 1,
            'msg' => $msg,
        ]);
    }

    // QB dates come in 05/01/2026, 5/1/2026, 2026-05-01, or Excel serial.
    protected function parseQbDate($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Numeric Excel serial date.
        if (is_numeric($raw) && (float) $raw > 20000) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
                return $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Try common explicit formats first to avoid US/EU ambiguity.
        foreach (['m/d/Y', 'n/j/Y', 'Y-m-d', 'm-d-Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt !== false) return $dt->format('Y-m-d H:i:s');
        }

        try {
            return (new \DateTime($raw))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // QB amounts: "1,407.90", "-735.95", "(67,032.83)" for negative, "$ 100.00".
    protected function parseQbAmount($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        $negative = false;
        if (preg_match('/^\((.+)\)$/', $raw, $m)) {
            $negative = true;
            $raw = $m[1];
        }
        $raw = str_replace([',', '$', ' '], '', $raw);
        if ($raw === '' || $raw === '-' || $raw === '.') return null;
        if (!is_numeric($raw)) return null;
        $val = (float) $raw;
        return $negative ? -$val : $val;
    }
}
