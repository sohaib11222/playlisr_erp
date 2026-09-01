<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\BuyCustomerOffer;
use App\Contact;
use App\Product;
use App\PurchaseLine;
use App\Services\BuyOfferCalculatorService;
use App\Services\InventoryCheckService;
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

    /**
     * @var InventoryCheckService
     */
    protected $inventoryCheckService;

    public function __construct(BuyOfferCalculatorService $calculator, ProductUtil $productUtil, InventoryCheckService $inventoryCheckService)
    {
        $this->calculator = $calculator;
        $this->productUtil = $productUtil;
        $this->inventoryCheckService = $inventoryCheckService;
    }

    /**
     * This week's purchasing budget for the Used-bar shown on the buy form.
     * Buy-from-customer is used inventory, so the cashier sees how much of the
     * weekly Used sub-budget (35% cap) is left before quoting. Reuses the same
     * figures as the ICA banner. Guarded so the form never breaks if it fails.
     */
    private function usedBudgetBar()
    {
        try {
            $business_id = request()->session()->get('user.business_id');
            $permitted = auth()->user()->permitted_locations();
            return $this->inventoryCheckService->currentPurchaseBudget($business_id, $permitted);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BFC used-budget bar failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    public function create()
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        // Plain [id => name] list. Passing receipt_printer_type_attribute=true
        // makes forDropdown return ['locations'=>…, 'attributes'=>…]; this form
        // fed that array straight into Form::select, which rendered "locations"/
        // "attributes" optgroup garbage in the Store Location picker (and broke
        // page-wide select2 init). This screen doesn't use the printer/price-group
        // data-attributes, so ask for the flat list. (Sarah 2026-07-20)
        $locations = BusinessLocation::forDropdown($business_id, false);
        // Sellers can be customers as well as suppliers (a customer can sell us
        // their collection), so load all contacts (excludes leads), not just suppliers.
        $contacts = Contact::contactDropdown($business_id, false, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();
        $purchaseBudget = $this->usedBudgetBar();

        // Default the Store location picker to wherever this employee is
        // actually logged in, same session field the POS/purchase screens use
        // to know an employee's working location. Left blank otherwise, the
        // select just falls back to whatever location sorts first, so a
        // cashier working one store can end up quietly attributing purchases
        // to another. Still just a default — the dropdown stays editable.
        $defaultLocationId = (int) request()->session()->get('user.business_location_id', 0) ?: null;

        // Prefill the seller when we arrive from a "Collection purchase with
        // credit" store-credit attempt (ContactController redirects here with
        // ?contact_id=…). $input_data drives the form's initial values, so
        // seed it with the picked contact in "existing account" mode.
        $input_data = ['location_id' => $defaultLocationId];
        $prefillContactId = (int) request()->query('contact_id', 0);
        if ($prefillContactId > 0 && Contact::where('business_id', $business_id)->where('id', $prefillContactId)->exists()) {
            $input_data = array_merge($input_data, [
                'seller_mode' => 'contact',
                'contact_id' => $prefillContactId,
                'payment_method' => 'store_credit',
            ]);
        }

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades', 'purchaseBudget', 'input_data'));
    }

    /**
     * Reopen a saved draft in the create form so the cashier can keep
     * negotiating instead of starting over. The whole create view is driven by
     * an $input_data array shaped like the form request, so we rehydrate that
     * from the stored offer + its lines and hand back the offer id via
     * saved_offer_id — which fills the hidden offer_id field, so subsequent
     * Calculate / Save / Accept clicks UPDATE this same BFC rather than spawning
     * a duplicate (the "one BFC per quote" rule the save path already enforces).
     * Only drafts can be reopened; accepted/rejected offers are finalized.
     */
    public function edit($id)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $offer = BuyCustomerOffer::with('lines')
            ->where('business_id', $business_id)
            ->findOrFail($id);

        if ($offer->status !== 'draft') {
            return redirect()->route('buy-from-customer.history')
                ->with('status', ['success' => 0, 'msg' => 'Only draft offers can be continued — this one is already ' . $offer->status . '.']);
        }

        $lines = $offer->lines
            ->sortBy('line_order')
            ->values()
            ->map(function ($line) {
                return [
                    'item_type' => $line->item_type,
                    'title' => $line->title,
                    'discogs_link' => $line->discogs_link,
                    'condition_grade' => $line->condition_grade,
                    'quantity' => $line->quantity,
                    'discogs_median_price' => $line->discogs_median_price,
                    'standard_multiplier' => $line->standard_multiplier,
                    'disposition' => $line->disposition,
                ];
            })->all();

        $input_data = [
            'location_id' => $offer->location_id,
            'seller_mode' => $offer->seller_mode ?: 'phone',
            'contact_id' => $offer->contact_id,
            'payment_method' => $offer->payment_method ?: ($offer->payout_type === 'store_credit' ? 'store_credit' : 'cash_in_store'),
            'seller_first_name' => $offer->seller_first_name,
            'seller_last_name' => $offer->seller_last_name,
            'seller_name' => $offer->seller_name,
            'seller_phone' => $offer->seller_phone,
            'seller_email' => $offer->seller_email,
            'seller_id_type' => $offer->seller_id_type,
            'seller_id_last_four' => $offer->seller_id_last_four,
            'notes' => $offer->notes,
            'lines' => $lines,
        ];

        // Recompute the calculator so the offer ladder / totals panel shows the
        // draft's numbers on load. Best-effort — a calc failure must never block
        // reopening a draft, so fall back to the bare form.
        $calculation = null;
        try {
            $calculation = $this->calculator->calculate($lines, $input_data);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('BFC draft reopen calc failed', ['offer_id' => $offer->id, 'err' => $e->getMessage()]);
        }

        // Plain [id => name] list. Passing receipt_printer_type_attribute=true
        // makes forDropdown return ['locations'=>…, 'attributes'=>…]; this form
        // fed that array straight into Form::select, which rendered "locations"/
        // "attributes" optgroup garbage in the Store Location picker (and broke
        // page-wide select2 init). This screen doesn't use the printer/price-group
        // data-attributes, so ask for the flat list. (Sarah 2026-07-20)
        $locations = BusinessLocation::forDropdown($business_id, false);
        $contacts = Contact::contactDropdown($business_id, false, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();
        $purchaseBudget = $this->usedBudgetBar();

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades', 'purchaseBudget'))
            ->with('input_data', $input_data)
            ->with('calculation', $calculation)
            ->with('saved_offer_id', $offer->id);
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
        // Plain [id => name] list. Passing receipt_printer_type_attribute=true
        // makes forDropdown return ['locations'=>…, 'attributes'=>…]; this form
        // fed that array straight into Form::select, which rendered "locations"/
        // "attributes" optgroup garbage in the Store Location picker (and broke
        // page-wide select2 init). This screen doesn't use the printer/price-group
        // data-attributes, so ask for the flat list. (Sarah 2026-07-20)
        $locations = BusinessLocation::forDropdown($business_id, false);
        // Sellers can be customers as well as suppliers (a customer can sell us
        // their collection), so load all contacts (excludes leads), not just suppliers.
        $contacts = Contact::contactDropdown($business_id, false, true, true);
        $itemTypes = $this->calculator->getItemTypesForDropdown();
        $grades = $this->calculator->getGradesForDropdown();
        $purchaseBudget = $this->usedBudgetBar();

        return view('buy_from_customer.create', compact('locations', 'contacts', 'itemTypes', 'grades', 'calculation', 'purchaseBudget'))
            ->with('input_data', $request->all())
            ->with('saved_offer_id', $saved->id);
    }

    /**
     * Background autosave. Fired debounced from the form (and on tab-close via
     * sendBeacon) so the seller's name / phone / email and whatever items have
     * been entered persist to a Draft even if the cashier never clicks Save &
     * continue — "the contact is the asset." Best-effort: skips strict
     * validation, never creates Contact records (a half-typed phone would spawn
     * junk — that happens on the real Save), and swallows errors so a failed
     * autosave never disrupts the counter. Reuses offer_id so it's one draft per
     * quote, not one per keystroke. Returns the id so the form keeps updating it.
     */
    public function autosave(Request $request)
    {
        if (!auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (!$this->autosaveHasContent($request)) {
            return response()->json(['saved' => false, 'offer_id' => $request->input('offer_id') ?: null]);
        }

        $offerId = $request->input('offer_id') ?: null;
        try {
            $saved = DB::transaction(function () use ($request, $offerId) {
                return $this->saveOffer($request, 'draft', $offerId, false);
            });
        } catch (\Throwable $e) {
            \Log::warning('BFC autosave failed: ' . $e->getMessage());
            return response()->json(['saved' => false], 200);
        }

        return response()->json(['saved' => true, 'offer_id' => $saved->id]);
    }

    /**
     * Only autosave once something worth keeping has been entered — a seller
     * field, or a line the cashier actually filled in. Keeps the 7 blank default
     * rows from creating empty draft records on page load.
     */
    protected function autosaveHasContent(Request $request)
    {
        // Sarah 2026-07-19: a returning seller is identified by their existing
        // account alone, so picking one (even before any items are entered) is
        // enough to persist the draft — mirrors the walk-in path, which saves as
        // soon as a name / phone / email is typed. Guarded on seller_mode so a
        // leftover contact_id from a prior selection can't trigger it once the
        // cashier has switched to walk-in.
        if ($request->input('seller_mode') === 'contact' && trim((string) $request->input('contact_id', '')) !== '') {
            return true;
        }
        foreach (['seller_first_name', 'seller_last_name', 'seller_name', 'seller_phone', 'seller_email'] as $f) {
            if (trim((string) $request->input($f, '')) !== '') {
                return true;
            }
        }
        foreach ((array) $request->input('lines', []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (trim((string) ($line['title'] ?? '')) !== '') return true;
            if (trim((string) ($line['discogs_link'] ?? '')) !== '') return true;
            if (trim((string) ($line['discogs_median_price'] ?? '')) !== '') return true;
        }
        return false;
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
            // A store-credit payout actually owes the seller money — add it to
            // their contacts.balance (the store-credit pool the profile shows
            // and checkout spends from). Without this the purchase recorded the
            // payout but the credit was invisible and unusable. Mirror
            // ContactController::updateStoreCredit (balance + audit + storefront
            // sync). Inside the same transaction so a failure rolls back the
            // whole acceptance rather than leaving credit without a purchase.
            if ($offer->payout_type === 'store_credit' && !$offer->is_donated) {
                $this->creditStoreCreditPayout($offer, $created['purchase']);
            }
            $created['offer_id'] = $offer->id;
            return $created;
        });

        $msg = sprintf(
            'Offer accepted. Created %d draft purchase line(s)%s. Price each item at /products before finalizing the purchase.',
            $result['materialized'],
            $result['skipped_no_title'] > 0
                ? sprintf(' (skipped %d untitled line(s) — not added to inventory)', $result['skipped_no_title'])
                : ''
        );

        // Straight to the intake sheet — that's the printout Sarah wants in
        // hand right after the box changes ownership, not buried in History.
        return redirect()->route('buy-from-customer.intake-sheet', $result['offer_id'])
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

    /**
     * Printable intake sheet for an accepted purchase: who it was bought
     * from/by, the record #, where the box is being stored, what was paid,
     * and its contents. Any logged-in employee can view/print this (not
     * gated on purchase.create) — the whole point is that whoever finds the
     * box later can look it up, not just the person who bought it.
     */
    public function intakeSheet($id)
    {
        $this->ensureCollectionStorageAndDispositionColumns();

        $business_id = request()->session()->get('user.business_id');
        $offer = BuyCustomerOffer::with(['lines', 'contact', 'createdBy', 'location'])
            ->where('business_id', $business_id)
            ->findOrFail($id);
        $itemTypes = $this->calculator->getItemTypesForDropdown();

        return view('buy_from_customer.intake_sheet', compact('offer', 'itemTypes'));
    }

    /**
     * Shared, store-wide list of where every purchased collection physically
     * ended up. Visible/editable to any logged-in employee (not gated on
     * purchase.create) so whoever finds a box — not just whoever bought it —
     * can look up or correct its location.
     */
    public function storageLocations()
    {
        $this->ensureCollectionStorageAndDispositionColumns();

        $business_id = request()->session()->get('user.business_id');
        $search = trim((string) request()->input('q', ''));
        $locationId = request()->input('location_id');

        $query = BuyCustomerOffer::with(['contact', 'createdBy', 'lines', 'location', 'processingStatusUpdatedBy'])
            ->where('business_id', $business_id)
            ->where('status', 'accepted');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('storage_location', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('seller_first_name', 'like', "%{$search}%")
                    ->orWhere('seller_last_name', 'like', "%{$search}%");
            });
        }

        if ($locationId !== null && $locationId !== '') {
            $query->where('location_id', $locationId);
        }

        $offers = $query->latest('accepted_at')->paginate(50)->appends(request()->only('q', 'location_id'));
        $locations = BusinessLocation::forDropdown($business_id, true);

        return view('buy_from_customer.storage_locations', compact('offers', 'search', 'locations', 'locationId'));
    }

    public function updateStorageLocation(Request $request, $id)
    {
        $this->ensureCollectionStorageAndDispositionColumns();

        $business_id = request()->session()->get('user.business_id');
        $offer = BuyCustomerOffer::where('business_id', $business_id)->findOrFail($id);

        $request->validate([
            'storage_location' => 'nullable|string|max:255',
        ]);

        $offer->storage_location = trim((string) $request->input('storage_location', '')) ?: null;
        $offer->storage_location_updated_by = request()->session()->get('user.id');
        $offer->storage_location_updated_at = now();
        $offer->save();

        return response()->json([
            'success' => true,
            'storage_location' => $offer->storage_location,
            'updated_by' => optional(auth()->user())->user_full_name ?? optional(auth()->user())->username,
            'updated_at' => optional($offer->storage_location_updated_at)->format('M j, Y g:ia'),
        ]);
    }

    /**
     * Where a purchased collection is in being sorted/priced/shelved. Same
     * shared, store-wide editability as storage_location above — whoever is
     * working a box updates it, so the status always reflects who touched
     * it last.
     */
    public function updateProcessingStatus(Request $request, $id)
    {
        $this->ensureCollectionStorageAndDispositionColumns();

        $business_id = request()->session()->get('user.business_id');
        $offer = BuyCustomerOffer::where('business_id', $business_id)->findOrFail($id);

        $request->validate([
            'processing_status' => 'required|string|in:not_started,in_progress,complete',
        ]);

        $currentName = trim((string) (optional(auth()->user())->user_full_name ?? optional(auth()->user())->username ?? ''));

        $contributors = json_decode($offer->processing_status_contributors ?: '[]', true) ?: [];
        if ($currentName !== '' && !in_array($currentName, $contributors, true)) {
            $contributors[] = $currentName;
        }

        $offer->processing_status = $request->input('processing_status');
        $offer->processing_status_updated_by = request()->session()->get('user.id');
        $offer->processing_status_updated_at = now();
        $offer->processing_status_contributors = json_encode($contributors);
        $offer->save();

        return response()->json([
            'success' => true,
            'processing_status' => $offer->processing_status,
            'updated_by' => $currentName,
            'updated_at' => optional($offer->processing_status_updated_at)->format('M j, Y g:ia'),
            'meta' => $offer->processing_status_meta,
        ]);
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
            // Sarah 2026-07-19: the seller must be identified before a quote can
            // be produced ("the contact is the asset"). Returning seller → an
            // existing account must be picked; new / walk-in → at minimum a name
            // and phone so the buy is tied to a real, reachable person. These
            // run on calculate (quote), store, and accept, so the gate holds at
            // every step, not just the first Calculate.
            'contact_id' => 'nullable|integer|required_if:seller_mode,contact',
            'seller_name' => 'nullable|string|max:255',
            'seller_first_name' => 'nullable|string|max:120|required_if:seller_mode,phone',
            'seller_last_name' => 'nullable|string|max:120',
            'seller_phone' => 'nullable|string|max:30|required_if:seller_mode,phone',
            'seller_email' => 'nullable|email|max:191',
            'seller_id_type' => 'nullable|string|max:60',
            'seller_id_last_four' => 'nullable|regex:/^[0-9]{1,4}$/',
            'payment_method' => 'required|in:cash_in_store,store_credit,zelle_venmo',
            'lines' => 'required|array|min:1',
            'lines.*.item_type' => 'required|string|max:60',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.discogs_median_price' => 'nullable|numeric|min:0',
            'lines.*.condition_grade' => 'nullable|string|max:30',
            'lines.*.disposition' => 'nullable|string|in:store,discogs,ebay,hollywood,trash,clearance_bin',
            'starting_offer_cash' => 'nullable|numeric|min:0',
            'starting_offer_credit' => 'nullable|numeric|min:0',
            'second_offer_cash' => 'nullable|numeric|min:0',
            'second_offer_credit' => 'nullable|numeric|min:0',
            'final_offer_cash' => 'nullable|numeric|min:0',
            'final_offer_credit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'price_override_reason' => 'nullable|string|max:500',
            // Sarah 2026-07-09: the accept step captures the actual amount paid
            // in one field, tagged with the payment method chosen there.
            'final_amount_paid' => 'nullable|numeric|min:0',
        ];

        if ($requireFinal) {
            $rules['final_offer_cash'] = 'required|numeric|min:0';
            $rules['final_offer_credit'] = 'required|numeric|min:0';
        }

        $request->validate($rules, [
            'contact_id.required_if' => 'Select the seller\'s existing account before getting a quote.',
            'seller_first_name.required_if' => 'Enter the seller\'s first name before getting a quote.',
            'seller_phone.required_if' => 'Enter the seller\'s phone number before getting a quote.',
        ]);

        if ($requireFinal && !filter_var($request->input('is_donated'), FILTER_VALIDATE_BOOLEAN)) {
            // Sarah 2026-05-19: starting / 2nd / final offers are editable again,
            // so the calculator now respects user-typed offer values. To still
            // detect when the cashier actually overrode the suggested final, we
            // recompute the calculator's pure auto-final by passing an empty
            // offerInputs array (which forces the 50% / 75% / 95% defaults), and
            // compare that to whatever the cashier submitted. If they diverge,
            // require an override reason. Skipped entirely for a donated
            // collection — dropping to $0 is the whole point of "Donated" and
            // doesn't need a manager-approval-style reason typed in.
            $autoCalc = $this->calculator->calculate($request->input('lines', []), []);
            $pm = $request->input('payment_method');
            $autoFinal = $pm === 'store_credit' ? (float) $autoCalc['final_offer_credit'] : (float) $autoCalc['final_offer_cash'];
            // Prefer the amount the cashier typed on the accept step; fall back
            // to the negotiated final from the offer table otherwise.
            $finalAmountPaid = $request->input('final_amount_paid');
            $final = is_numeric($finalAmountPaid)
                ? (float) $finalAmountPaid
                : ($pm === 'store_credit' ? (float) $request->input('final_offer_credit') : (float) $request->input('final_offer_cash'));
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

    protected function saveOffer(Request $request, $status = 'draft', $offerId = null, $createContact = true)
    {
        $this->ensureCollectionStorageAndDispositionColumns();

        $business_id = request()->session()->get('user.business_id');
        $user_id = request()->session()->get('user.id');
        $contact = $this->resolveSellerContact($request, $business_id, $user_id, $createContact);
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
        // Sarah 2026-05-20: cashier can edit the final CASH offer on the form
        // after Calculate (negotiated price ≠ calculator print-out). Honor the
        // submitted cash value when present; fall back to calc otherwise. The
        // Calculate auto-save path doesn't POST these fields (the editable
        // inputs are unnamed), so it always falls back — preserving prior
        // behavior. Override divergence is validated against
        // price_override_reason in validateRequest.
        //
        // Credit is NEVER taken from the submitted final_offer_credit field —
        // $calculation['final_offer_credit'] (BuyOfferCalculatorService) always
        // derives it as credit_bonus_multiplier × the cash figure above, so the
        // store-credit offer can't drift off its 1.5x-of-cash ratio the way it
        // used to when a negotiated cash figure left credit at its stale value.
        $submittedFinalCash = $request->input('final_offer_cash');
        $offer->final_offer_cash = is_numeric($submittedFinalCash)
            ? (float) $submittedFinalCash
            : $calculation['final_offer_cash'];
        $offer->final_offer_credit = $calculation['final_offer_credit'];
        // Sarah 2026-07-09: on the accept step the cashier types the single
        // amount actually handed over into "Final amount paid", tagged with the
        // payment method chosen there. That entered amount is authoritative — it
        // overrides the negotiated final for whichever payout type applies, so
        // History, the created purchase, and any store-credit added all record
        // what was really paid.
        $finalAmountPaid = $request->input('final_amount_paid');
        if (is_numeric($finalAmountPaid)) {
            if ($payment['payout_type'] === 'store_credit') {
                $offer->final_offer_credit = (float) $finalAmountPaid;
            } else {
                $offer->final_offer_cash = (float) $finalAmountPaid;
            }
        }
        $offer->rejection_reason = $request->input('rejection_reason');
        $notes = $request->input('notes');
        $isDonated = filter_var($request->input('is_donated'), FILTER_VALIDATE_BOOLEAN);
        $offer->is_donated = $isDonated;
        if ($isDonated) {
            // A donated collection is never negotiated — force the whole
            // payout ladder to $0 regardless of what the calculator or a
            // stray submitted value said, so History/the intake sheet never
            // show a "final offer" for a box nobody paid for.
            $offer->starting_offer_cash = 0;
            $offer->starting_offer_credit = 0;
            $offer->second_offer_cash = 0;
            $offer->second_offer_credit = 0;
            $offer->final_offer_cash = 0;
            $offer->final_offer_credit = 0;
            if (strpos((string) $notes, '[DONATED]') === false) {
                $notes = trim('[DONATED] ' . (string) $notes);
            }
        }
        $offer->notes = $notes;
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

    protected function resolveSellerContact(Request $request, $business_id, $user_id, $createIfMissing = true)
    {
        // Direct picker — existing contact was chosen.
        $mode = $request->input('seller_mode');
        if ($mode === 'contact' && !empty($request->input('contact_id'))) {
            $picked = Contact::where('business_id', $business_id)->find($request->input('contact_id'));
            // A supplier-only contact picked here is being bought from AND must
            // stay findable in POS customer search (which filters type IN
            // customer, both). Apply the same upgrade the walk-in match path
            // does below — otherwise picking an existing supplier leaves them
            // invisible on /pos/create. Only on the real save/accept path
            // ($createIfMissing), never on background autosave keystrokes.
            if ($picked && $createIfMissing && in_array($picked->type, ['customer', 'supplier'], true)) {
                $picked->type = 'both';
                $picked->save();
            }
            return $picked;
        }

        // Background autosave passes $createIfMissing = false: a half-typed
        // walk-in must never spawn (or mutate) a Contact — otherwise every
        // keystroke of a phone number would create a fresh junk record. The raw
        // seller fields are still persisted on the offer draft itself; the real
        // Save / Accept path (createIfMissing = true) creates the contact once.
        if (!$createIfMissing) {
            return null;
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
            // Make sure a matched seller is type 'both', not just 'customer' or
            // 'supplier'. Otherwise a customer-only contact stays usable as a
            // purchase supplier but, more importantly, a supplier-only contact
            // (e.g. one we created on a previous buy) is invisible to every
            // customer search — which filters type IN (customer, both) — while
            // still tripping the "mobile already registered" check (which has no
            // type filter). That mismatch is exactly the "can't find them but
            // says they're registered" bug.
            if (in_array($existing->type, ['customer', 'supplier'], true)) {
                $existing->type = 'both';
                $dirty = true;
            }
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
        // type 'both': we bought from them (supplier side) AND they should be
        // findable in customer search + able to hold store credit (customer
        // side). Creating them as 'supplier' only made them invisible to every
        // customer lookup (which filters type IN (customer, both)) while still
        // tripping the no-type-filter "mobile already registered" check.
        return Contact::create([
            'business_id'    => $business_id,
            'type'           => 'both',
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
        $this->ensureCollectionStorageAndDispositionColumns();

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
            '%sBuy from customer %s | payout: %s | payment: %s | record: %s | total payout: %.2f',
            $offer->is_donated ? '[DONATED] ' : '',
            $offer->id,
            $payoutType,
            $pmLabel,
            $offer->buy_record_number,
            $finalAmount
        );
        // Generate a purchase reference number the same way PurchaseController
        // does. Without this the transaction saves with ref_no = null, which
        // later crashes the /purchases/{id} detail view (the C128 barcode can't
        // encode an empty string) and leaves the buy with no human identifier.
        $ref_count = $this->productUtil->setAndGetReferenceCount('purchase');
        $purchase->ref_no = $this->productUtil->generateReferenceNumber('purchase', $ref_count);
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

        // Flat-rate fallback (Sarah 2026-06-23): on a quick buy the cashier may
        // enter items + a negotiated final cash WITHOUT per-line Discogs/unit
        // prices, so every line_cash_total is 0 and calculated_total is 0. The
        // payoutRatio then falls back to 1.0 and each unitPaid comes out $0 —
        // which left the materialized purchase with final_total = 0, so the buy
        // never showed cost basis OR pulled into the weekly purchase budget
        // (the budget skips $0 purchases). When the calculator didn't price the
        // lines, split the negotiated payout evenly across the units we are
        // actually inventorying so the purchase total = what was paid.
        $totalTitledQty = 0.0;
        if ($calculatedTotal <= 0 && $finalTotal > 0) {
            foreach ($offer->lines as $l) {
                $lq = (float) ($l->quantity ?: 0);
                if ($lq > 0 && trim((string) ($l->title ?: '')) !== '') {
                    $totalTitledQty += $lq;
                }
            }
        }
        $flatUnitPaid = ($totalTitledQty > 0) ? round($finalTotal / $totalTitledQty, 4) : 0.0;

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
            // When the calculator never priced the lines (calculatedTotal 0),
            // fall back to an even split of the negotiated payout so the buy
            // still carries a real cost basis and lands in the budget.
            $lineCalculated = (float) ($isCredit ? $line->line_credit_total : $line->line_cash_total);
            if ($calculatedTotal > 0) {
                $unitPaid = $qty > 0 ? round(($lineCalculated * $payoutRatio) / $qty, 4) : 0;
            } else {
                $unitPaid = $flatUnitPaid;
            }

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
                'disposition' => $line->disposition,
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

    // Credits the seller's store-credit balance for an accepted store-credit
    // payout. Mirrors ContactController::updateStoreCredit so the credit shows
    // up in the profile's "Store Credit" row + "Credit history", and syncs to
    // the Nivessa storefront so the customer can spend it online too.
    protected function creditStoreCreditPayout(BuyCustomerOffer $offer, Transaction $purchase)
    {
        $amount = (float) $offer->final_offer_credit;
        if ($amount <= 0) {
            return;
        }

        // Seller is resolved/created in saveOffer, so contact_id is normally
        // set; fall back to the purchase's contact just in case.
        $contactId = $offer->contact_id ?: $purchase->contact_id;
        if (empty($contactId)) {
            return;
        }
        $contact = Contact::where('business_id', $offer->business_id)->find($contactId);
        if (empty($contact)) {
            return;
        }

        $newBalance = (float) $contact->balance + $amount;
        $contact->balance = $newBalance;

        if (Schema::hasColumn('contacts', 'balance_notes')) {
            $stamp = now()->format('Y-m-d H:i');
            $who = auth()->user()->first_name ?? 'unknown';
            $line = sprintf(
                '[%s] store-credit +$%s by %s → new balance $%s. Reason: buy-from-customer payout (offer %s, record %s).',
                $stamp, number_format($amount, 2),
                $who, number_format($newBalance, 2),
                $offer->id, $offer->buy_record_number
            );
            $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
        }
        $contact->save();

        // Structured ledger row — this credit IS backed by a purchase form, so
        // record the linking offer id. Best-effort; balance_notes is the fallback.
        try {
            if (Schema::hasTable('store_credit_logs')) {
                \App\StoreCreditLog::create([
                    'business_id' => (int) $contact->business_id,
                    'contact_id' => (int) $contact->id,
                    'user_id' => auth()->id(),
                    'source' => 'buy_from_customer',
                    'amount' => (float) $amount,
                    'balance_after' => (float) $newBalance,
                    'reason' => 'buy-from-customer payout (offer ' . $offer->id . ', record ' . $offer->buy_record_number . ')',
                    'buy_customer_offer_id' => (int) $offer->id,
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('store_credit_logs write failed: ' . $e->getMessage());
        }

        // Push the delta to the storefront so the online balance matches the ERP.
        if (in_array($contact->type, ['customer', 'both']) && !empty($contact->email)) {
            app(\App\Services\NivessaBackendCreditSyncService::class)->syncDeltaByEmail(
                (string) $contact->email,
                $amount,
                'buy_from_customer_payout',
                ['contact_id' => (int) $contact->id, 'action' => 'add', 'offer_id' => (int) $offer->id]
            );
        }
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

    /**
     * Self-heal the storage-location / disposition columns the same way
     * ensureOfferLineProductRefColumns() does — deploys don't auto-run
     * `php artisan migrate`, so code that depends on these columns must be
     * able to add them itself the first time it runs.
     */
    protected function ensureCollectionStorageAndDispositionColumns()
    {
        if (Schema::hasTable('buy_customer_offers') && !Schema::hasColumn('buy_customer_offers', 'storage_location')) {
            Schema::table('buy_customer_offers', function (Blueprint $table) {
                $table->string('storage_location', 255)->nullable();
                $table->unsignedInteger('storage_location_updated_by')->nullable();
                $table->timestamp('storage_location_updated_at')->nullable();
            });
        }

        if (Schema::hasTable('buy_customer_offer_lines') && !Schema::hasColumn('buy_customer_offer_lines', 'disposition')) {
            Schema::table('buy_customer_offer_lines', function (Blueprint $table) {
                $table->string('disposition', 20)->nullable();
            });
        }

        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'disposition')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('disposition', 20)->nullable();
            });
        }

        if (Schema::hasTable('buy_customer_offers') && !Schema::hasColumn('buy_customer_offers', 'processing_status')) {
            Schema::table('buy_customer_offers', function (Blueprint $table) {
                $table->string('processing_status', 20)->default('not_started');
                $table->unsignedInteger('processing_status_updated_by')->nullable();
                $table->timestamp('processing_status_updated_at')->nullable();
            });
        }

        if (Schema::hasTable('buy_customer_offers') && !Schema::hasColumn('buy_customer_offers', 'processing_status_contributors')) {
            Schema::table('buy_customer_offers', function (Blueprint $table) {
                $table->text('processing_status_contributors')->nullable();
            });
        }

        if (Schema::hasTable('buy_customer_offers') && !Schema::hasColumn('buy_customer_offers', 'is_donated')) {
            Schema::table('buy_customer_offers', function (Blueprint $table) {
                $table->boolean('is_donated')->default(false);
            });
        }
    }
}

