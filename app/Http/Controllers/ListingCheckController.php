<?php

namespace App\Http\Controllers;

use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Did you mean to edit the one we already have?" undo, offered by the popup
 * after a purchase is saved (see App\Services\ListingCheck).
 *
 * Undo takes the new product back out of the purchase (line removed, stock
 * taken back off, purchase total reduced), retires the product the same way
 * a merge does (is_inactive + not_for_selling, never deleted) and sends the
 * person to the existing product's edit page to change the price there.
 * Refused once the new copy has been sold, transferred or adjusted, or when
 * someone else made it. A JSON snapshot of every row touched is kept in
 * storage/app/listing-undo.
 */
class ListingCheckController extends Controller
{
    const MAX_AGE_HOURS = 48;

    protected $productUtil;
    protected $transactionUtil;

    public function __construct(ProductUtil $productUtil, TransactionUtil $transactionUtil)
    {
        $this->productUtil = $productUtil;
        $this->transactionUtil = $transactionUtil;
    }

    public function undo(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $newId = (int) $request->input('new_product_id');
        $oldId = (int) $request->input('old_product_id');
        $purchaseId = (int) $request->input('purchase_id');
        $back = redirect('purchases');

        $error = $this->undoBlocker($business_id, $newId, $oldId, $purchaseId);
        if ($error) {
            return $back->with('status', ['success' => 0, 'msg' => $error]);
        }

        try {
            DB::beginTransaction();
            $t = DB::table('transactions')->where('id', $purchaseId)->lockForUpdate()->first();
            $lines = DB::table('purchase_lines')->where('transaction_id', $purchaseId)->where('product_id', $newId)->get();
            $product = DB::table('products')->where('id', $newId)->first();

            \Storage::disk('local')->put('listing-undo/' . now()->format('Ymd_His') . "_p{$newId}.json", json_encode([
                'by' => auth()->id(), 'old_product_id' => $oldId, 'transaction' => $t, 'purchase_lines' => $lines,
                'product' => $product,
                'stock' => DB::table('variation_location_details')->where('product_id', $newId)->get(),
            ], JSON_PRETTY_PRINT));

            $lineTotal = 0;
            foreach ($lines as $l) {
                $lineTotal += (float) $l->quantity * (float) $l->purchase_price_inc_tax;
                if ($t->status === 'received') {
                    $this->productUtil->decreaseProductQuantity($l->product_id, $l->variation_id, $t->location_id, (float) $l->quantity);
                }
            }
            DB::table('purchase_lines')->whereIn('id', $lines->pluck('id'))->delete();
            DB::table('transactions')->where('id', $purchaseId)->update([
                'total_before_tax' => max(0, round((float) $t->total_before_tax - $lineTotal, 4)),
                'final_total' => max(0, round((float) $t->final_total - $lineTotal, 4)),
                'updated_at' => now(),
            ]);
            $this->transactionUtil->updatePaymentStatus($purchaseId);

            DB::table('products')->where('id', $newId)->update(['is_inactive' => 1, 'not_for_selling' => 1, 'updated_at' => now()]);

            $note = 'Undid new product #' . $newId . ' (' . $product->name . ') from purchase '
                . ($t->ref_no ?: '#' . $purchaseId) . ': meant to edit #' . $oldId;
            $log = activity()->performedOn(\App\Product::find($newId))->causedBy(auth()->user())
                ->withProperties(['update_note' => $note])->log('listing_undone');
            $log->business_id = $business_id;
            $log->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::emergency('Listing undo failed: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            return $back->with('status', ['success' => 0, 'msg' => __('messages.something_went_wrong')]);
        }

        // The retired copy drops off nivessa.com right away.
        try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([$newId]); } catch (\Throwable $e) {}

        return redirect(action('ProductController@edit', [$oldId]))->with('status', [
            'success' => 1,
            'msg' => 'Took the new one back out. Change the price here and save.',
        ]);
    }

    /** Why this undo isn't allowed, or null when it is. */
    private function undoBlocker($business_id, int $newId, int $oldId, int $purchaseId): ?string
    {
        $user = auth()->user();
        $product = DB::table('products')->where('business_id', $business_id)->where('id', $newId)->first();
        $old = DB::table('products')->where('business_id', $business_id)->where('id', $oldId)->first();
        $t = DB::table('transactions')->where('business_id', $business_id)->where('id', $purchaseId)->where('type', 'purchase')->first();
        if (!$product || !$old || !$t || $newId === $oldId) return 'Could not find that product. Nothing was changed.';
        if ($product->is_inactive) return 'That product was already taken out.';

        $isAdmin = $user->can('superadmin') || $user->hasRole('Admin#' . $business_id);
        if ((int) $product->created_by !== (int) $user->id && !$isAdmin) return 'Only the person who added it can undo it.';
        if (\Carbon::parse($product->created_at)->lt(now()->subHours(self::MAX_AGE_HOURS))) return 'That was added more than 2 days ago, so it can\'t be undone here. Ask a manager to merge the two.';

        $lines = DB::table('purchase_lines')->where('transaction_id', $purchaseId)->where('product_id', $newId)->get();
        if ($lines->isEmpty()) return 'That product is not on this purchase anymore.';
        foreach ($lines as $l) {
            if ((float) $l->quantity_sold + (float) $l->quantity_adjusted + (float) $l->quantity_returned + (float) $l->mfg_quantity_used > 0) {
                return 'The new copy was already sold or adjusted, so it can\'t be undone. Ask a manager to merge the two.';
            }
        }
        // Any other history (sales, transfers, other purchases) means it's a real listing now.
        $otherLines = DB::table('purchase_lines')->where('product_id', $newId)->where('transaction_id', '!=', $purchaseId)->exists()
            || DB::table('transaction_sell_lines')->where('product_id', $newId)->exists();
        if ($otherLines) return 'The new copy already has other sales or purchases, so it can\'t be undone. Ask a manager to merge the two.';

        if ($t->status === 'received') {
            $qty = (float) DB::table('variation_location_details')->where('product_id', $newId)->where('location_id', $t->location_id)->sum('qty_available');
            if ($qty < (float) $lines->sum('quantity')) return 'The new copy\'s stock already changed, so it can\'t be undone. Ask a manager to merge the two.';
        }
        return null;
    }
}
