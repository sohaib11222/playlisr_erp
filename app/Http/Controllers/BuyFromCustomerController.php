<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\BuyCustomerOffer;
use App\Contact;
use App\Product;
use App\PurchaseLine;
use App\Services\BuyOfferCalculatorService;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BuyFromCustomerController extends Controller
{
    /**
     * @var BuyOfferCalculatorService
     */
    protected $calculator;

    /**
     * @var ProductUtil
     */
    protected $productUtil;

    public function __construct(BuyOfferCalculatorService $calculator, ProductUtil $productUtil)
    {
        $this->calculator = $calculator;
        $this->productUtil = $productUtil;
    }

    public function create()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $locations = BusinessLocation::forDropdown($business_id, false, true);
        $contacts = Contact::suppliersDropdown($business_id, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades'));
    }

    public function calculate(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, false);
        $lines = $request->input('lines', []);
        $calculation = $this->calculator->calculate($lines, $request->all());

        // Auto-save a draft on every Calculate so history captures the
        // negotiation even if the cashier doesn't explicitly hit Save Draft.
        // If the form already carries an offer_id (a draft from a previous
        // Calculate on the SAME quote), update that draft in place instead
        // of spawning a duplicate. Sarah explicitly asked for this — one
        // BFC per quote, not one per click.
        $offerId = $request->input('offer_id') ?: null;
        $saved = DB::transaction(function () use ($request, $offerId) {
            return $this->saveOffer($request, 'draft', $offerId);
        });

        // Inject the saved id back into the request so the Save Draft / Accept /
        // Reject hidden-input forms below also carry it — otherwise clicking
        // Save Draft after auto-save would spawn a second BFC record.
        $request->merge(['offer_id' => $saved->id]);

        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id, false, true);
        $contacts = Contact::suppliersDropdown($business_id, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades', 'calculation'))
            ->with('input_data', $request->all())
            ->with('saved_offer_id', $saved->id);
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, false);
        $offerId = $request->input('offer_id') ?: null;
        $saved = DB::transaction(function () use ($request, $offerId) {
            return $this->saveOffer($request, 'draft', $offerId);
        });

        return redirect()->route('buy-from-customer.create')
            ->with('status', ['success' => 1, 'msg' => 'Draft offer saved successfully.'])
            ->with('saved_offer_id', $saved->id);
    }

    public function accept(Request $request, $id = null)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, true);
        $this->validateAcceptCompliance($request);

        $offerId = $id ?? ($request->input('offer_id') ?: null);
        $result = DB::transaction(function () use ($request, $offerId) {
            $offer = $this->saveOffer($request, 'accepted', $offerId);
            $created = $this->createPurchaseFromOffer($offer, $offer->payout_type);
            $offer->accepted_purchase_id = $created['purchase']->id;
            $offer->save();
            return $created;
        });

        $msg = sprintf(
            'Offer accepted. Created %d draft purchase line(s)%s. Price each item at /products before finalizing the purchase.',
            $result['materialized'],
            $result['skipped_no_title'] > 0
                ? sprintf(' (skipped %d untitled line(s) — not added to inventory)', $result['skipped_no_title'])
                : ''
        );

        return redirect()->route('buy-from-customer.history')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    public function reject(Request $request, $id = null)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, true);
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $offerId = $id ?? ($request->input('offer_id') ?: null);
        DB::transaction(function () use ($request, $offerId) {
            $this->saveOffer($request, 'rejected', $offerId);
        });

        return redirect()->route('buy-from-customer.history')
            ->with('status', ['success' => 1, 'msg' => 'Offer marked as rejected.']);
    }

    public function destroy($id)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        // Only admins can delete buy-from-customer history records.
        // Cashiers and other staff can view history but not destroy entries.
        if (!auth()->user()->hasRole('Admin#' . $business_id)) {
            return redirect()->route('buy-from-customer.history')
                ->with('status', ['success' => 0, 'msg' => 'Only admins can delete buy-from-customer records.']);
        }

        $offer = BuyCustomerOffer::where('business_id', $business_id)->findOrFail($id);

        // Accepted offers are tied to a real Purchase record (money paid out).
        // Refuse to delete those from the UI — they have to be voided through
        // the normal purchase flow first.
        if ($offer->status === 'accepted') {
            return redirect()->route('buy-from-customer.history')
                ->with('status', ['success' => 0, 'msg' => 'Cannot delete an accepted offer — void the linked purchase first.']);
        }

        $offer->lines()->delete();
        $offer->delete();

        return redirect()->route('buy-from-customer.history')
            ->with('status', ['success' => 1, 'msg' => 'Offer deleted.']);
    }

    public function history()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $showAll = filter_var(request()->input('show_all'), FILTER_VALIDATE_BOOLEAN);

        // Diagnostic counts so Sarah can tell at a glance whether records exist
        // and which business_id they were saved under. If the totals disagree
        // with what she expects, the ?show_all=1 toggle reveals every record.
        $diagnostics = [
            'business_id' => $business_id,
            'total_in_db' => BuyCustomerOffer::count(),
            'total_for_business' => BuyCustomerOffer::where('business_id', $business_id)->count(),
            'distinct_business_ids' => BuyCustomerOffer::select('business_id')->distinct()->pluck('business_id')->all(),
            'show_all' => $showAll,
        ];

        $query = BuyCustomerOffer::with(['contact', 'createdBy', 'acceptedPurchase', 'location']);
        if (!$showAll) {
            $query->where('business_id', $business_id);
        }
        $offers = $query->latest()->paginate(30)->appends(request()->only('show_all'));

        $is_admin = auth()->user()->hasRole('Admin#' . $business_id);

        return view('buy_from_customer.history', compact('offers', 'diagnostics', 'is_admin'));
    }

    protected function validateRequest(Request $request, $requireFinal)
    {
        if (!$request->has('payment_method') && $request->has('payout_type')) {
            $request->merge([
                'payment_method' => $request->input('payout_type') === 'store_credit' ? 'store_credit' : 'cash_in_store',
            ]);
        }

        $rules = [
            'location_id' => 'nullable|integer',
            'seller_mode' => 'required|in:contact,phone',
            'contact_id' => 'nullable|integer',
            'seller_name' => 'nullable|string|max:255',
            'seller_first_name' => 'nullable|string|max:120',
            'seller_last_name' => 'nullable|string|max:120',
            'seller_phone' => 'nullable|string|max:30',
            'seller_email' => 'nullable|email|max:191',
            'seller_id_type' => 'nullable|string|max:60',
            'seller_id_last_four' => 'nullable|regex:/^[0-9]{1,4}$/',
            'payment_method' => 'required|in:cash_in_store,store_credit,zelle_venmo',
            'lines' => 'required|array|min:1',
            'lines.*.item_type' => 'required|string|max:60',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.discogs_median_price' => 'nullable|numeric|min:0',
            'lines.*.condition_grade' => 'nullable|string|max:30',
            'starting_offer_cash' => 'nullable|numeric|min:0',
            'starting_offer_credit' => 'nullable|numeric|min:0',
            'second_offer_cash' => 'nullable|numeric|min:0',
            'second_offer_credit' => 'nullable|numeric|min:0',
            'final_offer_cash' => 'nullable|numeric|min:0',
            'final_offer_credit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'price_override_reason' => 'nullable|string|max:500',
        ];

        if ($requireFinal) {
            $rules['final_offer_cash'] = 'required|numeric|min:0';
            $rules['final_offer_credit'] = 'required|numeric|min:0';
        }

        $request->validate($rules);

        if ($requireFinal) {
            // Sarah 2026-05-19: starting / 2nd / final offers are editable again,
            // so the calculator now respects user-typed offer values. To still
            // detect when the cashier actually overrode the suggested final, we
            // recompute the calculator's pure auto-final by passing an empty
            // offerInputs array (which forces the 50% / 75% / 95% defaults), and
            // compare that to whatever the cashier submitted. If they diverge,
            // require an override reason.
            $autoCalc = $this->calculator->calculate($request->input('lines', []), []);
            $pm = $request->input('payment_method');
            $autoFinal = $pm === 'store_credit' ? (float) $autoCalc['final_offer_credit'] : (float) $autoCalc['final_offer_cash'];
            $final = $pm === 'store_credit' ? (float) $request->input('final_offer_credit') : (float) $request->input('final_offer_cash');
            if (abs($final - $autoFinal) > 0.009) {
                $request->validate([
                    'price_override_reason' => 'required|string|max:500',
                ]);
            }
        }
    }

    protected function validateAcceptCompliance(Request $request)
    {
        $request->validate([
            'seller_signature_data' => 'required|string|min:80',
            'compliance_items_owned' => 'accepted',
            'compliance_sales_final' => 'accepted',
        ]);
    }

    /**
     * @return array{payout_type: string, payment_method: string}
     */
    protected function resolvePaymentFields(Request $request)
    {
        $pm = $request->input('payment_method');
        if ($pm === 'store_credit') {
            return ['payout_type' => 'store_credit', 'payment_method' => 'store_credit'];
        }
        if ($pm === 'zelle_venmo') {
            return ['payout_type' => 'cash', 'payment_method' => 'zelle_venmo'];
        }

        return ['payout_type' => 'cash', 'payment_method' => 'cash_in_store'];
    }

    protected function saveOffer(Request $request, $status = 'draft', $offerId = null)
    {
        $business_id = request()->session()->get('user.business_id');
        $user_id = request()->session()->get('user.id');
        $contact = $this->resolveSellerContact($request, $business_id, $user_id);
        $calculation = $this->calculator->calculate($request->input('lines', []), $request->all());

        // If we were handed an existing offer id, reuse it — UNLESS that offer
        // is already finalized (accepted/rejected). Finalized offers are
        // immutable; a Calculate that comes in after acceptance must spawn a
        // fresh BFC rather than rewrite the closed record.
        $offer = null;
        if (!empty($offerId)) {
            $existing = BuyCustomerOffer::where('business_id', $business_id)->find($offerId);
            if ($existing && in_array($existing->status, ['accepted', 'rejected'], true)) {
                $offer = new BuyCustomerOffer();
            } else {
                $offer = $existing ?: new BuyCustomerOffer();
            }
        } else {
            $offer = new BuyCustomerOffer();
        }
        $payment = $this->resolvePaymentFields($request);

        $first = trim((string) $request->input('seller_first_name', ''));
        $last = trim((string) $request->input('seller_last_name', ''));
        $legacyName = trim((string) $request->input('seller_name', ''));
        $combined = trim($first . ' ' . $last);
        $sellerDisplayName = $combined !== '' ? $combined : ($legacyName !== '' ? $legacyName : null);

        $offer->business_id = $business_id;
        $offer->location_id = $request->input('location_id') ?: null;
        $offer->created_by = $user_id;
        $offer->contact_id = optional($contact)->id;
        $offer->seller_mode = $request->input('seller_mode', 'phone');
        $offer->seller_name = $sellerDisplayName;
        $offer->seller_first_name = $first ?: null;
        $offer->seller_last_name = $last ?: null;
        $offer->seller_phone = $request->input('seller_phone');
        $offer->seller_email = $request->input('seller_email') ?: null;
        $offer->seller_id_type = $request->input('seller_id_type') ?: null;
        $offer->seller_id_last_four = $request->input('seller_id_last_four') ?: null;
        $offer->status = $status;
        $offer->payout_type = $payment['payout_type'];
        $offer->payment_method = $payment['payment_method'];
        $offer->calculated_cash_total = $calculation['calculated_cash_total'];
        $offer->calculated_credit_total = $calculation['calculated_credit_total'];
        $offer->starting_offer_cash = $calculation['starting_offer_cash'];
        $offer->starting_offer_credit = $calculation['starting_offer_credit'];
        $offer->second_offer_cash = $calculation['second_offer_cash'];
        $offer->second_offer_credit = $calculation['second_offer_credit'];
        $offer->final_offer_cash = $calculation['final_offer_cash'];
        $offer->final_offer_credit = $calculation['final_offer_credit'];
        $offer->rejection_reason = $request->input('rejection_reason');
        $offer->notes = $request->input('notes');
        $offer->price_override_reason = $request->input('price_override_reason') ?: null;
        $offer->collection_summary_json = json_encode($calculation['collection_summary'] ?? []);
        if ($request->filled('seller_signature_data')) {
            $offer->seller_signature_data = $request->input('seller_signature_data');
        }
        if ($status === 'accepted') {
            $offer->compliance_items_owned = $request->has('compliance_items_owned');
            $offer->compliance_sales_final = $request->has('compliance_sales_final');
            $offer->accepted_at = now();
        }
        $offer->calculation_snapshot = json_encode($calculation['lines']);
        $offer->save();

        $offer->lines()->delete();
        foreach ($calculation['lines'] as $line) {
            $offer->lines()->create($line);
        }

        return $offer->fresh(['lines']);
    }

    protected function resolveSellerContact(Request $request, $business_id, $user_id)
    {
        // Direct picker — existing contact was chosen.
        $mode = $request->input('seller_mode');
        if ($mode === 'contact' && !empty($request->input('contact_id'))) {
            return Contact::where('business_id', $business_id)->find($request->input('contact_id'));
        }

        // Walk-in seller. Phase 1 intake sends first + last + phone + email.
        // Build the canonical name from first+last when available, fall back
        // to legacy single seller_name, fall back to a stub if both are blank.
        $phone = trim((string) $request->input('seller_phone', ''));
        $email = trim((string) $request->input('seller_email', ''));
        $first = trim((string) $request->input('seller_first_name', ''));
        $last  = trim((string) $request->input('seller_last_name', ''));
        $legacyName = trim((string) $request->input('seller_name', ''));
        $combinedName = trim($first . ' ' . $last);
        $name = $combinedName !== '' ? $combinedName : ($legacyName ?: null);

        // Create a contact as long as we have at LEAST ONE of (phone, email,
        // name). Previously this bailed out when phone was empty — which meant
        // sellers who only gave name/email never got saved at all. Sarah
        // flagged this as "put in seller info, nothing happens."
        if (empty($phone) && empty($email) && empty($name)) {
            return null;
        }

        // Match existing contacts by phone first, then by email. Keeps us
        // from creating duplicate accounts when a repeat seller comes in.
        $existing = null;
        if (!empty($phone)) {
            $existing = Contact::where('business_id', $business_id)->where('mobile', $phone)->first();
        }
        if (!$existing && !empty($email)) {
            $existing = Contact::where('business_id', $business_id)->where('email', $email)->first();
        }
        if ($existing) {
            // Fill in any blanks on the existing record — if they gave us
            // new info this time, save it. Doesn't overwrite existing data.
            $dirty = false;
            if (!empty($first) && empty($existing->first_name)) { $existing->first_name = $first; $dirty = true; }
            if (!empty($last) && empty($existing->last_name))   { $existing->last_name  = $last;  $dirty = true; }
            if (!empty($email) && empty($existing->email))      { $existing->email      = $email; $dirty = true; }
            if (!empty($phone) && empty($existing->mobile))     { $existing->mobile     = $phone; $dirty = true; }
            if ($dirty) $existing->save();
            return $existing;
        }

        // New contact — save every field we have, not just name+mobile. This
        // is what made "seller info isn't saved anywhere" true: email was
        // silently dropped.
        // contacts.mobile is NOT NULL on prod, so when the seller didn't give
        // a phone we store the literal 0 — matches the convention used in
        // ContactController::createCustomer (the API-token path).
        $fallbackName = $name ?: ('Walk-in Seller ' . ($phone ?: $email ?: uniqid('buy-')));
        return Contact::create([
            'business_id'    => $business_id,
            'type'           => 'supplier',
            'name'           => $fallbackName,
            'first_name'     => $first ?: null,
            'last_name'      => $last ?: null,
            'mobile'         => $phone ?: 0,
            'email'          => $email ?: null,
            'created_by'     => $user_id,
            'contact_status' => 'active',
        ]);
    }

    protected function createPurchaseFromOffer(BuyCustomerOffer $offer, $payoutType)
    {
        // Self-heal: add the FK columns the migration adds, in case the
        // server hasn't run `php artisan migrate` yet (Sarah doesn't SSH —
        // shipping ALTER TABLE behind a request avoids the manual step).
        // Idempotent: hasColumn() guards every add.
        $this->ensureOfferLineProductRefColumns();

        $business_id = $offer->business_id;
        $location_id = $offer->location_id ?: BusinessLocation::where('business_id', $business_id)->value('id');

        $finalAmount = $payoutType === 'store_credit' ? $offer->final_offer_credit : $offer->final_offer_cash;
        $finalAmount = (float) $finalAmount;

        $purchase = new Transaction();
        $purchase->business_id = $business_id;
        $purchase->location_id = $location_id;
        $purchase->type = 'purchase';
        // Draft (not received) so qty_available stays at 0 until staff finalize
        // the purchase from /purchases — that's when they price each item and
        // flip not_for_selling off. Status flip from draft → received in the
        // standard PurchaseController flow runs ProductUtil::updateProductStock,
        // which is the only place we want stock to actually move into POS.
        // We do NOT call updateProductQuantity directly here for the same
        // reason: it would double-count when staff later marks received.
        $purchase->status = 'draft';
        $purchase->payment_status = 'due';
        $purchase->contact_id = $offer->contact_id ?: Contact::where('business_id', $business_id)->whereIn('type', ['supplier', 'both'])->value('id');
        $purchase->transaction_date = now();
        // Totals seeded to 0 — recomputed below from materialized lines so
        // the purchase total matches its line items (some BFC lines may be
        // skipped if they had no title and weren't inventoried).
        $purchase->total_before_tax = 0;
        $purchase->tax_amount = 0;
        $purchase->discount_amount = 0;
        $purchase->shipping_charges = 0;
        $purchase->final_total = 0;
        $purchase->created_by = $offer->created_by;
        $pmLabel = $offer->payment_method ?: $payoutType;
        $purchase->additional_notes = sprintf(
            'Buy from customer %s | payout: %s | payment: %s | record: %s | total payout: %.2f',
            $offer->id,
            $payoutType,
            $pmLabel,
            $offer->buy_record_number,
            $finalAmount
        );
        $purchase->save();

        // Materialize each offer line into a real Product + Variation + PurchaseLine.
        // Each line becomes its own SKU (used vinyl is one-of-one). SKUs are flagged
        // not_for_selling=1 so they cannot ring up at $0 before staff prices them.
        // We SKIP lines without a title — those are placeholders the cashier didn't
        // bother to identify (and would just clutter inventory as "BFC … — type"
        // ghosts). They still affect the offer payout but don't materialize.
        // Cost basis = the proportional share of final_offer_cash/credit, so
        // "Unit Cost" on the purchase reflects what Sarah actually paid out.
        $offer->load('lines');
        $snapshotLines = [];
        $skippedNoTitle = 0;
        $linesTotal = 0.0;

        // Compute payout ratio so each line's cost mirrors its share of the
        // negotiated final price (not the calculator's "fair value" total).
        $isCredit = ($payoutType === 'store_credit');
        $calculatedTotal = (float) ($isCredit ? $offer->calculated_credit_total : $offer->calculated_cash_total);
        $finalTotal = (float) ($isCredit ? $offer->final_offer_credit : $offer->final_offer_cash);
        $payoutRatio = $calculatedTotal > 0 ? ($finalTotal / $calculatedTotal) : 1.0;

        foreach ($offer->lines as $line) {
            $qty = (float) ($line->quantity ?: 0);
            if ($qty <= 0) {
                continue;
            }
            $title = trim((string) ($line->title ?: ''));
            if ($title === '') {
                // No title = no inventoried SKU. Cashier didn't identify the
                // item; the offer payout still includes it but we don't spawn
                // a phantom product.
                $skippedNoTitle++;
                continue;
            }

            // Per-unit paid: line's calculated value × payout ratio ÷ qty.
            $lineCalculated = (float) ($isCredit ? $line->line_credit_total : $line->line_cash_total);
            $unitPaid = $qty > 0 ? round(($lineCalculated * $payoutRatio) / $qty, 4) : 0;

            $description = sprintf(
                'Bought from customer | offer %s | type: %s | grade: %s',
                $offer->buy_record_number,
                $line->item_type,
                $line->condition_grade ?: '—'
            );

            $product = Product::create([
                'name' => $title,
                'sku' => 111, // placeholder, replaced by generateProductSku() once we have an id
                'tax' => null,
                'tax_type' => 'exclusive',
                'alert_quantity' => 0,
                'business_id' => $business_id,
                'created_by' => $offer->created_by,
                'added_via' => 'buy_from_customer',
                'enable_stock' => 1,
                'product_description' => $description,
                'unit_id' => 1,
                'type' => 'single',
                'not_for_selling' => 1,
            ]);
            $product->sku = $this->productUtil->generateProductSku($product->id);
            $product->save();

            $product_variation = $product->product_variations()->create([
                'name' => 'DUMMY',
                'is_dummy' => 1,
            ]);
            $variation = $product_variation->variations()->create([
                'name' => 'DUMMY',
                'product_id' => $product->id,
                'sub_sku' => $product->sku,
                'default_purchase_price' => $unitPaid,
                'dpp_inc_tax' => $unitPaid,
                'profit_percent' => 0,
                'default_sell_price' => 0,
                'sell_price_inc_tax' => 0,
            ]);
            $product->product_locations()->sync([$location_id]);

            $purchase_line = new PurchaseLine();
            $purchase_line->product_id = $product->id;
            $purchase_line->variation_id = $variation->id;
            $purchase_line->item_tax = 0;
            $purchase_line->tax_id = null;
            $purchase_line->quantity = $qty;
            $purchase_line->pp_without_discount = $unitPaid;
            $purchase_line->purchase_price = $unitPaid;
            $purchase_line->purchase_price_inc_tax = $unitPaid;
            $purchase->purchase_lines()->save($purchase_line);
            $linesTotal += $unitPaid * $qty;

            // No updateProductQuantity here — purchase is draft. Stock will
            // post when staff flips status to received from /purchases edit.

            $line->product_id = $product->id;
            $line->variation_id = $variation->id;
            $line->purchase_line_id = $purchase_line->id;
            $line->save();

            $snapshotLines[] = [
                'offer_line_id' => $line->id,
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'product_variation_id' => $variation->product_variation_id,
                'purchase_line_id' => $purchase_line->id,
                'location_id' => $location_id,
                'quantity' => $qty,
                // Stock was NOT bumped on receive (purchase is draft). Undo
                // honors this and skips the VLD decrement so it doesn't go
                // negative.
                'stock_bumped' => false,
            ];
        }

        // Snapshot for /admin/admin-action-history undo. Captures everything
        // we need to walk this back: which products/variations were created,
        // which VLD rows to decrement, which transaction to flip to draft.
        // Recompute purchase totals from the lines we actually materialized.
        $purchase->total_before_tax = round($linesTotal, 2);
        $purchase->final_total = round($linesTotal, 2);
        $purchase->save();

        if (!empty($snapshotLines)) {
            $timestamp = now()->format('Y-m-d_His');
            $snapshotKey = "bfc-receive-{$offer->id}-{$timestamp}";
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp' => now()->toDateTimeString(),
                    'action' => 'bfc-receive',
                    'business_id' => $business_id,
                    'location_id' => $location_id,
                    'offer_id' => $offer->id,
                    'buy_record_number' => $offer->buy_record_number,
                    'transaction_id' => $purchase->id,
                    'rows' => $snapshotLines,
                ], JSON_PRETTY_PRINT)
            );
        }

        return [
            'purchase' => $purchase,
            'materialized' => count($snapshotLines),
            'skipped_no_title' => $skippedNoTitle,
        ];
    }

    // Adds product_id / variation_id / purchase_line_id to
    // buy_customer_offer_lines if not already present. Mirrors the migration
    // file 2026_05_07_120000_add_product_refs_to_buy_customer_offer_lines.php
    // for environments where artisan migrate hasn't been run yet.
    protected function ensureOfferLineProductRefColumns()
    {
        if (!Schema::hasTable('buy_customer_offer_lines')) {
            return;
        }
        $needsProduct = !Schema::hasColumn('buy_customer_offer_lines', 'product_id');
        $needsVariation = !Schema::hasColumn('buy_customer_offer_lines', 'variation_id');
        $needsPurchaseLine = !Schema::hasColumn('buy_customer_offer_lines', 'purchase_line_id');
        if (!$needsProduct && !$needsVariation && !$needsPurchaseLine) {
            return;
        }
        Schema::table('buy_customer_offer_lines', function (Blueprint $table) use ($needsProduct, $needsVariation, $needsPurchaseLine) {
            if ($needsProduct) {
                $table->unsignedInteger('product_id')->nullable();
            }
            if ($needsVariation) {
                $table->unsignedInteger('variation_id')->nullable();
            }
            if ($needsPurchaseLine) {
                $table->unsignedBigInteger('purchase_line_id')->nullable();
            }
        });
    }
}

