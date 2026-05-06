<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\BuyCustomerOffer;
use App\Contact;
use App\Services\BuyOfferCalculatorService;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyFromCustomerController extends Controller
{
    /**
     * @var BuyOfferCalculatorService
     */
    protected $calculator;

    public function __construct(BuyOfferCalculatorService $calculator)
    {
        $this->calculator = $calculator;
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
        DB::transaction(function () use ($request, $offerId) {
            $offer = $this->saveOffer($request, 'accepted', $offerId);
            $purchase = $this->createPurchaseFromOffer($offer, $offer->payout_type);
            $offer->accepted_purchase_id = $purchase->id;
            $offer->save();
        });

        return redirect()->route('buy-from-customer.history')
            ->with('status', ['success' => 1, 'msg' => 'Offer accepted and purchase record created.']);
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

        return view('buy_from_customer.history', compact('offers', 'diagnostics'));
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
            // Sarah 2026-05-06: starting / 2nd / final offers are now read-only
            // outputs of the calculator (50% / 75% / 95% of the calculated
            // total), so the cashier can no longer "override" them from the UI.
            // We still compare submitted final vs. the calculator's auto-final
            // and only require an override reason if they actually diverge —
            // which today only happens if someone hand-tampers the form. Keeps
            // the existing override-reason field meaningful without forcing it
            // on every accept.
            $calc = $this->calculator->calculate($request->input('lines', []), $request->all());
            $pm = $request->input('payment_method');
            $autoFinal = $pm === 'store_credit' ? (float) $calc['final_offer_credit'] : (float) $calc['final_offer_cash'];
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
        $business_id = $offer->business_id;
        $location_id = $offer->location_id ?: BusinessLocation::where('business_id', $business_id)->value('id');

        $finalAmount = $payoutType === 'store_credit' ? $offer->final_offer_credit : $offer->final_offer_cash;
        $finalAmount = (float) $finalAmount;

        $purchase = new Transaction();
        $purchase->business_id = $business_id;
        $purchase->location_id = $location_id;
        $purchase->type = 'purchase';
        $purchase->status = 'draft';
        $purchase->payment_status = 'due';
        $purchase->contact_id = $offer->contact_id ?: Contact::where('business_id', $business_id)->whereIn('type', ['supplier', 'both'])->value('id');
        $purchase->transaction_date = now();
        $purchase->total_before_tax = $finalAmount;
        $purchase->tax_amount = 0;
        $purchase->discount_amount = 0;
        $purchase->shipping_charges = 0;
        $purchase->final_total = $finalAmount;
        $purchase->created_by = $offer->created_by;
        $pmLabel = $offer->payment_method ?: $payoutType;
        $purchase->additional_notes = sprintf(
            'Buy from customer %s | payout: %s | payment: %s | record: %s',
            $offer->id,
            $payoutType,
            $pmLabel,
            $offer->buy_record_number
        );
        $purchase->save();

        return $purchase;
    }
}

