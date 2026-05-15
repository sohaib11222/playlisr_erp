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
        } elseif ($amount !== '' || $date !== '' || $request->get('channel_filter')) {
            // Amount/date/channel lookup. Looser by default — ±$5 amount,
            // include draft sales, +/- 1 day around the chosen date to
            // absorb timezone edges. The right ring is almost never an
            // exact $0.50 match because cashiers ring pre-tax stickers,
            // tax adds a few bucks, etc.
            $q = Transaction::where('business_id', $business_id)
                ->where('type', 'sell')
                ->whereIn('status', ['final', 'draft'])
                ->select('id', 'invoice_no', 'transaction_date', 'final_total', 'channel', 'location_id', 'created_by');

            if ($date !== '') {
                $d = \Carbon\Carbon::parse($date);
                $q->whereBetween('transaction_date', [
                    $d->copy()->subDay()->startOfDay(),
                    $d->copy()->addDay()->endOfDay(),
                ]);
            } else {
                // Default: anything in the last 3 days when no date given,
                // so an 11:37am sale near a UTC/LA boundary is still found.
                $q->where('transaction_date', '>=', \Carbon\Carbon::now()->subDays(3));
            }

            if ($amount !== '') {
                $a = (float) str_replace(['$', ','], '', $amount);
                $q->whereBetween('final_total', [$a - 5.00, $a + 5.00]);
            }

            $cf = $request->get('channel_filter');
            if (is_string($cf) && $cf !== '') {
                // Legacy rows may have channel=NULL with is_whatnot=1
                // (pre-2026-04-22 migration); match those under 'whatnot'
                // too. Similarly NULL channel + is_whatnot=0 = 'in_store'.
                if ($cf === 'whatnot') {
                    $q->where(function ($w) {
                        $w->where('channel', 'whatnot')
                          ->orWhere(function ($w2) {
                              $w2->whereNull('channel')->where('is_whatnot', 1);
                          });
                    });
                } elseif ($cf === 'in_store') {
                    $q->where(function ($w) {
                        $w->where('channel', 'in_store')
                          ->orWhere(function ($w2) {
                              $w2->whereNull('channel')->where(function ($w3) {
                                  $w3->whereNull('is_whatnot')->orWhere('is_whatnot', 0);
                              });
                          });
                    });
                } else {
                    $q->where('channel', $cf);
                }
            }

            $candidates = $q->orderBy('transaction_date', 'desc')->limit(50)->get();
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
