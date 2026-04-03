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

        $business_id = request()->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($business_id, false, true);
        $contacts = Contact::suppliersDropdown($business_id, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades', 'calculation'))
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
            'seller_name' => 'nullable|string|max:255',
            'seller_phone' => 'nullable|string|max:30',
            'payout_type' => 'required|in:cash,store_credit',
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
        ];

        if ($requireFinal) {
            $rules['final_offer_cash'] = 'required|numeric|min:0';
            $rules['final_offer_credit'] = 'required|numeric|min:0';
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
        $offer->seller_name = $request->input('seller_name');
        $offer->seller_phone = $request->input('seller_phone');
        $offer->status = $status;
        $offer->payout_type = $request->input('payout_type', 'cash');
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

