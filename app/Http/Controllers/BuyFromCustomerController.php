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
        $paymentMethods = $this->paymentMethodOptions();
        $idTypes = $this->idTypeOptions();

        return view('buy_from_customer.create', compact(
            'locations', 'contacts', 'itemTypes', 'grades',
            'paymentMethods', 'idTypes'
        ));
    }

    /**
     * Cashier-facing labels for payment methods. DB stores the key (e.g.
     * "zelle_jon"), UI shows the value. Keep in sync with
     * BuyCustomerOffer::PAYMENT_METHODS.
     */
    public function paymentMethodOptions()
    {
        return [
            'cash'         => 'Cash',
            'store_credit' => 'Store Credit',
            'zelle_jon'    => 'Zelle (from Jon)',
            'venmo_jon'    => 'Venmo (from Jon)',
        ];
    }

    public function idTypeOptions()
    {
        return [
            'drivers_license' => "Driver's License",
            'passport'        => 'Passport',
            'state_id'        => 'State ID',
            'other'           => 'Other',
        ];
    }

    public function calculate(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, false);
        $lines = $request->input('lines', []);
        $calculation = $this->calculator->calculate($lines, $request->all());

        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id, false, true);
        $contacts = Contact::suppliersDropdown($business_id, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();
        $paymentMethods = $this->paymentMethodOptions();
        $idTypes = $this->idTypeOptions();

        return view('buy_from_customer.create', compact(
                'locations', 'contacts', 'itemTypes', 'grades',
                'paymentMethods', 'idTypes', 'calculation'
            ))
            ->with('input_data', $request->all());
    }

    public function store(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $this->validateRequest($request, false);
        $saved = DB::transaction(function () use ($request) {
            return $this->saveOffer($request, 'draft');
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

        DB::transaction(function () use ($request, $id) {
            $offer = $this->saveOffer($request, 'accepted', $id);
            $purchase = $this->createPurchaseFromOffer($offer, $request->input('payout_type', 'cash'));
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

        DB::transaction(function () use ($request, $id) {
            $this->saveOffer($request, 'rejected', $id);
        });

        return redirect()->route('buy-from-customer.history')
            ->with('status', ['success' => 1, 'msg' => 'Offer marked as rejected.']);
    }

    public function history()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $offers = BuyCustomerOffer::with(['contact', 'createdBy', 'acceptedPurchase'])
            ->where('business_id', $business_id)
            ->latest()
            ->paginate(30);

        return view('buy_from_customer.history', compact('offers'));
    }

    protected function validateRequest(Request $request, $requireFinal)
    {
        $rules = [
            'location_id' => 'nullable|integer',
            'seller_mode' => 'required|in:contact,phone',
            'contact_id' => 'nullable|integer',
            // Legacy single "seller_name" kept for backwards compat; new intake
            // splits into first/last + email + optional ID.
            'seller_name' => 'nullable|string|max:255',
            'seller_first_name' => 'nullable|string|max:100',
            'seller_last_name' => 'nullable|string|max:100',
            'seller_phone' => 'nullable|string|max:30',
            'seller_email' => 'nullable|email|max:255',
            'seller_id_type' => 'nullable|in:' . implode(',', BuyCustomerOffer::ID_TYPES),
            'seller_id_last4' => 'nullable|string|max:4',
            'payout_type' => 'required|in:cash,store_credit',
            'payment_method' => 'nullable|in:' . implode(',', BuyCustomerOffer::PAYMENT_METHODS),
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
            'final_price_paid' => 'nullable|numeric|min:0',
            'override_reason' => 'nullable|string|max:2000',
            // Item-count breakdown
            'items_lp_count' => 'nullable|integer|min:0|max:9999',
            'items_45_count' => 'nullable|integer|min:0|max:9999',
            'items_cd_count' => 'nullable|integer|min:0|max:9999',
            'items_cassette_count' => 'nullable|integer|min:0|max:9999',
            'items_dvd_count' => 'nullable|integer|min:0|max:9999',
            'items_bluray_count' => 'nullable|integer|min:0|max:9999',
            'items_other_count' => 'nullable|integer|min:0|max:9999',
            'condition_mint_nm_count' => 'nullable|integer|min:0|max:9999',
            'condition_vg_plus_count' => 'nullable|integer|min:0|max:9999',
            'condition_g_below_count' => 'nullable|integer|min:0|max:9999',
            // Compliance — required on accept, optional on draft
            'compliance_confirmed_ownership' => 'nullable|boolean',
            'compliance_ack_final_sale' => 'nullable|boolean',
        ];

        if ($requireFinal) {
            $rules['final_offer_cash'] = 'required|numeric|min:0';
            $rules['final_offer_credit'] = 'required|numeric|min:0';
            // On accept, both compliance checkboxes must be checked — this is
            // the whole point of the intake redesign (legal cover).
            $rules['compliance_confirmed_ownership'] = 'required|accepted';
            $rules['compliance_ack_final_sale'] = 'required|accepted';
            $rules['payment_method'] = 'required|in:' . implode(',', BuyCustomerOffer::PAYMENT_METHODS);
        }

        $request->validate($rules);
    }

    protected function saveOffer(Request $request, $status = 'draft', $offerId = null)
    {
        $business_id = request()->session()->get('user.business_id');
        $user_id = request()->session()->get('user.id');
        $contact = $this->resolveSellerContact($request, $business_id, $user_id);
        $calculation = $this->calculator->calculate($request->input('lines', []), $request->all());

        $offer = !empty($offerId) ? BuyCustomerOffer::where('business_id', $business_id)->findOrFail($offerId) : new BuyCustomerOffer();
        $offer->business_id = $business_id;
        $offer->location_id = $request->input('location_id') ?: null;
        $offer->created_by = $user_id;
        $offer->contact_id = optional($contact)->id;
        $offer->seller_mode = $request->input('seller_mode', 'phone');
        // Keep legacy seller_name populated by concatenating first + last if
        // both provided — older reports/exports still read seller_name.
        $firstName = trim((string) $request->input('seller_first_name', ''));
        $lastName = trim((string) $request->input('seller_last_name', ''));
        $combinedName = trim($firstName . ' ' . $lastName);
        $offer->seller_name = $combinedName !== '' ? $combinedName : $request->input('seller_name');
        $offer->seller_first_name = $firstName !== '' ? $firstName : null;
        $offer->seller_last_name = $lastName !== '' ? $lastName : null;
        $offer->seller_phone = $request->input('seller_phone');
        $offer->seller_email = $request->input('seller_email');
        $offer->seller_id_type = $request->input('seller_id_type');
        $offer->seller_id_last4 = $request->input('seller_id_last4');
        $offer->status = $status;
        $offer->payout_type = $request->input('payout_type', 'cash');
        $offer->payment_method = $request->input('payment_method');

        // Item + condition counts
        foreach (['items_lp_count', 'items_45_count', 'items_cd_count',
                  'items_cassette_count', 'items_dvd_count', 'items_bluray_count',
                  'items_other_count',
                  'condition_mint_nm_count', 'condition_vg_plus_count', 'condition_g_below_count'] as $countField) {
            $offer->{$countField} = (int) $request->input($countField, 0);
        }

        $offer->final_price_paid = $request->input('final_price_paid');
        $offer->override_reason = $request->input('override_reason');
        $offer->compliance_confirmed_ownership = (bool) $request->input('compliance_confirmed_ownership');
        $offer->compliance_ack_final_sale = (bool) $request->input('compliance_ack_final_sale');
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
        $mode = $request->input('seller_mode');
        if ($mode === 'contact' && !empty($request->input('contact_id'))) {
            return Contact::where('business_id', $business_id)->find($request->input('contact_id'));
        }

        $phone = trim((string) $request->input('seller_phone', ''));
        if (empty($phone)) {
            return null;
        }

        $existing = Contact::where('business_id', $business_id)->where('mobile', $phone)->first();
        if ($existing) {
            return $existing;
        }

        return Contact::create([
            'business_id' => $business_id,
            'type' => 'supplier',
            'name' => $request->input('seller_name') ?: ('Walk-in Seller ' . $phone),
            'mobile' => $phone,
            'created_by' => $user_id,
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
        $purchase->additional_notes = 'Buy from customer offer #' . $offer->id . ' payout: ' . $payoutType;
        $purchase->save();

        return $purchase;
    }
}

