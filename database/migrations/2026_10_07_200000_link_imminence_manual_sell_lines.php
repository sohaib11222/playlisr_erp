<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-off data fix for the Imminence signing at Hollywood on 2026-10-06.
// Cashiers rang 11 Axis Mundi records as hand-typed (manual) lines because the
// products carried the catalog number instead of the UPC, so those sales never
// reached StreetPulse and never came off stock. This links each line to the real
// product, takes the units off Hollywood stock, and moves Jon's 6 sales (rung on
// a device set to Pico, paid on the Hollywood Clover) to Hollywood.
// Old values are written to storage/app/backups/ and down() restores them.
class LinkImminenceManualSellLines extends Migration
{
    // invoice_no => variation to link (148973 = Indie Exclusive 2LP #149020,
    // 148971 = Red/Black Smoke 2LP #149018). Lines rung without a format are
    // counted as Indie Exclusive; only "Imminence red" ($37.98) names the red 2LP.
    private $map = [
        '32124' => 148973, '32125' => 148973, '32126' => 148971, '32127' => 148973,
        '32128' => 148973, '32129' => 148973, '32130' => 148973, '32134' => 148973,
        '32135' => 148973, '32136' => 148973, '32149' => 148973,
    ];
    private $moveToHollywood = ['32124', '32125', '32126', '32127', '32128', '32129'];
    private $hollywood = 2;
    private $backup = 'backups/imminence-manual-lines-2026-10-07.json';

    public function up()
    {
        $txs = DB::table('transactions')
            ->where('type', 'sell')
            ->whereDate('transaction_date', '2026-10-06')
            ->whereIn('invoice_no', array_keys($this->map))
            ->get(['id', 'invoice_no', 'location_id']);

        $snapshot = ['transactions' => [], 'lines' => [], 'stock' => []];
        DB::beginTransaction();
        try {
            foreach ($txs as $tx) {
                $variationId = $this->map[$tx->invoice_no];
                $variation = DB::table('variations')->where('id', $variationId)->first(['id', 'product_id', 'product_variation_id']);
                $lines = DB::table('transaction_sell_lines')
                    ->where('transaction_id', $tx->id)
                    ->where(function ($q) { $q->whereNull('product_id')->orWhere('product_id', 0); })
                    ->where(function ($q) {
                        $q->where('product_name', 'like', '%immin%')->orWhere('product_name', 'like', '%axis mundi%');
                    })
                    ->get(['id', 'product_id', 'variation_id', 'quantity']);
                foreach ($lines as $line) {
                    $snapshot['lines'][] = (array) $line;
                    DB::table('transaction_sell_lines')->where('id', $line->id)->update([
                        'product_id' => $variation->product_id,
                        'variation_id' => $variation->id,
                    ]);
                    $vld = DB::table('variation_location_details')
                        ->where('variation_id', $variation->id)->where('location_id', $this->hollywood)->first();
                    if ($vld) {
                        $snapshot['stock'][] = ['id' => $vld->id, 'qty_available' => $vld->qty_available];
                        DB::table('variation_location_details')->where('id', $vld->id)
                            ->update(['qty_available' => DB::raw('qty_available - ' . (float) $line->quantity)]);
                    }
                }
                if (in_array($tx->invoice_no, $this->moveToHollywood) && (int) $tx->location_id !== $this->hollywood) {
                    $snapshot['transactions'][] = ['id' => $tx->id, 'location_id' => $tx->location_id];
                    DB::table('transactions')->where('id', $tx->id)->update(['location_id' => $this->hollywood]);
                }
            }
            Storage::disk('local')->put($this->backup, json_encode($snapshot, JSON_PRETTY_PRINT));
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function down()
    {
        if (!Storage::disk('local')->exists($this->backup)) {
            return;
        }
        $snap = json_decode(Storage::disk('local')->get($this->backup), true);
        DB::transaction(function () use ($snap) {
            foreach ($snap['lines'] as $l) {
                DB::table('transaction_sell_lines')->where('id', $l['id'])
                    ->update(['product_id' => $l['product_id'], 'variation_id' => $l['variation_id']]);
            }
            // Restore stock to the pre-fix value only once per row (first snapshot wins).
            $seen = [];
            foreach ($snap['stock'] as $s) {
                if (isset($seen[$s['id']])) continue;
                $seen[$s['id']] = true;
                DB::table('variation_location_details')->where('id', $s['id'])->update(['qty_available' => $s['qty_available']]);
            }
            foreach ($snap['transactions'] as $t) {
                DB::table('transactions')->where('id', $t['id'])->update(['location_id' => $t['location_id']]);
            }
        });
    }
}
