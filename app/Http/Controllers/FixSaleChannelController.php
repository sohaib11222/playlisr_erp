<?php

namespace App\Http\Controllers;

use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Flip the `channel` on an ERP sale (e.g. whatnot → discogs) with a
 * BEFORE-snapshot to admin-snapshots/ so the change is undoable from
 * /admin/admin-action-history.
 *
 * Built after Manolo tagged a Discogs-catalogue pickup as 'whatnot' at
 * the register (2026-05-15) — whatnot is excluded from Clover↔ERP
 * matching, so his ring orphaned the $73.15 Clover swipe even though
 * he'd rung the sale correctly otherwise. Same shape will help for
 * future cashier channel mistakes.
 *
 *  GET  /admin/fix-channel?invoice=NNN  → preview the current ring
 *  POST /admin/fix-channel             → snapshot + apply
 */
class FixSaleChannelController extends Controller
{
    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $invoice = trim((string) $request->get('invoice', ''));
        $amount = trim((string) $request->get('amount', ''));
        $date = trim((string) $request->get('date', ''));

        $tx = null;
        $candidates = collect();

        if ($invoice !== '') {
            $tx = Transaction::where('business_id', $business_id)
                ->where('invoice_no', $invoice)
                ->select('id', 'invoice_no', 'transaction_date', 'final_total', 'channel', 'location_id', 'created_by')
                ->first();
        } elseif ($amount !== '' || $date !== '') {
            // Amount/date lookup — find candidates that match either field.
            // Defaults to today when no date given.
            $q = Transaction::where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->select('id', 'invoice_no', 'transaction_date', 'final_total', 'channel', 'location_id', 'created_by');
            $day = $date !== '' ? $date : \Carbon\Carbon::now()->format('Y-m-d');
            $q->whereDate('transaction_date', $day);
            if ($amount !== '') {
                $a = (float) str_replace(['$', ','], '', $amount);
                // Match within ±$0.50 to absorb tax/rounding variation.
                $q->whereBetween('final_total', [$a - 0.50, $a + 0.50]);
            }
            $candidates = $q->orderBy('transaction_date', 'desc')->limit(25)->get();
        }

        // Helpful location lookup so the table can show 'Hollywood' instead of '7'.
        $locationNames = [];
        $locIds = $candidates->pluck('location_id')->filter()->unique()
            ->merge($tx ? [$tx->location_id] : [])->filter()->unique()->all();
        if (!empty($locIds)) {
            $locationNames = \App\BusinessLocation::whereIn('id', $locIds)
                ->pluck('name', 'id')->all();
        }

        return view('admin.fix_sale_channel', [
            'tx' => $tx,
            'invoice' => $invoice,
            'amount' => $amount,
            'date' => $date,
            'candidates' => $candidates,
            'location_names' => $locationNames,
            'allowed_channels' => ['in_store', 'discogs', 'whatnot', 'ebay'],
            'mode' => 'preview',
            'snapshot_key' => null,
        ]);
    }

    public function apply(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $invoice = trim((string) $request->input('invoice', ''));
        $newChannel = strtolower((string) $request->input('channel', ''));

        $allowed = ['in_store', 'discogs', 'whatnot', 'ebay'];
        if (!in_array($newChannel, $allowed, true)) {
            return redirect('/admin/fix-channel?invoice=' . urlencode($invoice))
                ->with('status', 'Unsupported channel: ' . $newChannel);
        }

        $tx = Transaction::where('business_id', $business_id)
            ->where('invoice_no', $invoice)
            ->select('id', 'invoice_no', 'channel', 'final_total', 'location_id', 'created_by')
            ->first();
        if (!$tx) {
            return redirect('/admin/fix-channel')
                ->with('status', 'No ERP sale found with invoice ' . $invoice);
        }

        if (strtolower((string) $tx->channel) === $newChannel) {
            return redirect('/admin/fix-channel?invoice=' . urlencode($invoice))
                ->with('status', '✓ Channel already ' . $newChannel . ' — nothing to change.');
        }

        $now = \Carbon\Carbon::now();
        $snapshotKey = 'fix-channel-' . $now->format('Y-m-d_His') . '-tx' . $tx->id;
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => $now->toDateTimeString(),
                'action' => 'fix-sale-channel',
                'business_id' => $business_id,
                'rows' => [[
                    'transaction_id' => $tx->id,
                    'invoice_no' => $tx->invoice_no,
                    'old_channel' => $tx->channel,
                    'new_channel' => $newChannel,
                    'final_total' => (float) $tx->final_total,
                    'location_id' => (int) $tx->location_id,
                    'created_by' => (int) $tx->created_by,
                ]],
            ], JSON_PRETTY_PRINT)
        );

        Transaction::where('id', $tx->id)->update([
            'channel' => $newChannel,
            'updated_at' => $now,
        ]);

        return view('admin.fix_sale_channel', [
            'tx' => Transaction::where('id', $tx->id)->first(),
            'invoice' => $invoice,
            'allowed_channels' => $allowed,
            'mode' => 'commit',
            'snapshot_key' => $snapshotKey,
            'old_channel' => $tx->channel,
            'new_channel' => $newChannel,
        ]);
    }
}
