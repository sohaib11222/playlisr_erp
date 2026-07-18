<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Category;
use App\ChartPick;
use App\ChartPickImport;
use App\Contact;
use App\CustomerWant;
use App\InventoryCheckNote;
use App\InventoryCheckSession;
use App\Services\ChartPickParser;
use App\Services\InventoryCheckService;
use App\Services\TabularChartParser;
use App\Services\UniversalChartParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class InventoryCheckController extends Controller
{
    /** @var InventoryCheckService */
    protected $inventoryCheckService;

    /** @var ChartPickParser */
    protected $chartPickParser;

    public function __construct(InventoryCheckService $inventoryCheckService, ChartPickParser $chartPickParser)
    {
        $this->inventoryCheckService = $inventoryCheckService;
        $this->chartPickParser = $chartPickParser;
    }

    public function index()
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $categories = Category::forDropdown($business_id, 'product');
        $suppliers = Contact::suppliersDropdown($business_id, false);

        $presets = config('inventory_check.presets', []);
        $presetOptions = ['' => __('lang_v1.none')];
        foreach ($presets as $key => $meta) {
            $presetOptions[$key] = $meta['label'] ?? $key;
        }

        $presetMeta = [];
        foreach (array_keys($presets) as $key) {
            $presetMeta[$key] = $this->inventoryCheckService->resolvePreset($business_id, $key);
        }

        $copyFormat = config('inventory_check.copy_line_format');
        $amsColumns = config('inventory_check.ams_export_columns', []);

        // 2026-05-27 Sarah: surface the known supplier list to JS so the
        // fast-OOS table can render a dedicated price column per distributor
        // (AMS, Secretly, Beggars, Redeye, VP, plus any future ones added
        // to config/inventory_check.php).
        $knownSuppliers = [];
        foreach ($this->inventoryCheckService->knownSuppliers() as $key => $meta) {
            $knownSuppliers[] = [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
            ];
        }

        // Freshness check for the pasted charts — surface "last imported" dates.
        // Guarded: tables may not exist if migrations haven't run yet on this deploy.
        $chartFreshness = [];
        $migrationsMissing = false;
        if (Schema::hasTable('chart_pick_imports')) {
            $chartFreshness = ChartPickImport::where('business_id', $business_id)
                ->selectRaw('source, MAX(week_of) as week_of, MAX(created_at) as imported_at')
                ->groupBy('source')
                ->get()
                ->keyBy('source')
                ->toArray();
        } else {
            $migrationsMissing = true;
        }

        // Current week's purchase budget vs actual spend — shown as a banner
        // at the top of the page so Sarah doesn't run the order blind.
        $permitted = auth()->user()->permitted_locations();
        $purchaseBudget = null;
        try {
            $purchaseBudget = $this->inventoryCheckService->currentPurchaseBudget($business_id, $permitted);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA budget banner failed', ['err' => $e->getMessage()]);
        }

        return view('report.inventory_check_assistant')->with(compact(
            'business_locations',
            'categories',
            'suppliers',
            'presetOptions',
            'presetMeta',
            'copyFormat',
            'amsColumns',
            'chartFreshness',
            'migrationsMissing',
            'purchaseBudget',
            'knownSuppliers'
        ));
    }

    /**
     * Bucketed candidate data — the "Order for this week" view.
     */
    public function buckets(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $input = $request->only([
                'location_id', 'category_id', 'category_ids', 'preset',
            ]);

            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }

            if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
                $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
            }

            $permitted = auth()->user()->permitted_locations();
            $result = $this->inventoryCheckService->buildBuckets($business_id, $input, $permitted);

            return response()->json($result);
        } catch (\Throwable $e) {
            // Don't let a buildBuckets exception become a Laravel HTML
            // error page — it'd render as the misleading "Server returned
            // no buckets" empty state. Instead surface the exact reason
            // back to the JS so the page can show it.
            \Illuminate\Support\Facades\Log::error('ICA buckets build failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'buckets' => [],
                'meta' => [
                    'error' => 'build_failed',
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ],
            ], 200);
        }
    }

    /**
     * Lazy-loaded events bucket. Pulled separately from the main buckets
     * call because it hits two external feeds (server.nivessa.com +
     * ticketmaster-feed) and on a cold cache that took 15-30s — blocking
     * the whole page. JS calls this after the main render returns.
     */
    public function eventsBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            // Release the session lock immediately so the other lazy AJAX
            // requests can run in parallel server-side. Without this each
            // request held the session file lock end-to-end and they ran
            // serially — Sarah saw all lazy buckets stuck on "Loading…"
            // 2026-05-20 because secondaryBuckets blocked events + others.
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);

            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }

            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json([
                    'bucket' => [
                        'label' => 'Upcoming events — stock up',
                        'why' => 'Pick a store first.',
                        'items' => [],
                        'count' => 0,
                    ],
                ]);
            }

            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketEventsUpcomingPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA events bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'bucket' => [
                    'label' => 'Upcoming events — stock up',
                    'why' => 'Events feed failed to load: ' . $e->getMessage(),
                    'items' => [],
                    'count' => 0,
                    'empty_reason' => 'fetch_error',
                ],
            ]);
        }
    }

    /**
     * Lazy-loaded ABC-class-A restock bucket. The ABC map computation
     * scans the whole product catalog (cached 15min) — pulling it out of
     * the main buckets() call kept the initial "Building…" spinner under
     * 5s (Sarah 2026-05-20).
     */
    public function abcRestockBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            // Release the session lock immediately so the other lazy AJAX
            // requests can run in parallel server-side. Without this each
            // request held the session file lock end-to-end and they ran
            // serially — Sarah saw all lazy buckets stuck on "Loading…"
            // 2026-05-20 because secondaryBuckets blocked events + others.
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'A-class items — restock priority',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketAbcARestockPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA abc bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'A-class items — restock priority',
                'why' => 'ABC analysis failed to load: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    /**
     * Lazy-loaded seasonal-restock bucket. Surfaces low/OOS titles for the
     * season(s) currently in their ordering window so they get reordered
     * ahead of time. Same lazy pattern as ABC / frozen.
     */
    public function seasonalBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'Seasonal — stock up ahead of the season',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketSeasonalPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA seasonal bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'Seasonal — stock up ahead of the season',
                'why' => 'Seasonal list failed to load: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    /**
     * Lazy-loaded accessories-restock bucket. Surfaces low/OOS items in the
     * Accessories category (cleaning kits, sleeves, brushes) so they get
     * reordered during the weekly check. Same lazy pattern as seasonal.
     */
    public function accessoriesBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'Accessories — restock cleaning kits',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketAccessoriesLowPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA accessories bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'Accessories — restock cleaning kits',
                'why' => 'Accessories list failed to load: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    /**
     * Lazy-loaded frozen-inventory bucket. The last-sold scan crosses the
     * full transaction history (70k+ rows imported 2026-04-23) so it gets
     * its own request, same pattern as events.
     */
    public function frozenInventoryBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            // Release the session lock immediately so the other lazy AJAX
            // requests can run in parallel server-side. Without this each
            // request held the session file lock end-to-end and they ran
            // serially — Sarah saw all lazy buckets stuck on "Loading…"
            // 2026-05-20 because secondaryBuckets blocked events + others.
            $request->session()->save();
            $input = $request->only(['location_id', 'preset', 'days']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'Frozen inventory — DO NOT reorder',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            // Optional override of the "frozen days" threshold from the UI.
            // Clamp to a sane range so a bad value can't OOM the query.
            $daysOverride = null;
            if ($request->has('days') && is_numeric($request->input('days'))) {
                $daysOverride = max(7, min(3650, (int) $request->input('days')));
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketFrozenInventoryPublic($business_id, $locationId, $permitted, $daysOverride);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA frozen bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'Frozen inventory — DO NOT reorder',
                'why' => 'Frozen-inventory scan failed: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    /**
     * Lazy-loaded manager-picks bucket. Picks live in
     * storage/app/ica-manager-picks-{business_id}.json — managers (Sarah,
     * Jon, Fatteen, Lashyn) flag a category to stock up on and the ICA
     * surfaces low-stock candidates matching that category.
     */
    public function umeSpotlightsBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'UMe Update — release spotlights',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketUmeSpotlightsPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA UMe spotlights bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'UMe Update — release spotlights',
                'why' => 'UMe spotlights failed to load: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    public function managerPicksBucket(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            // Release the session lock immediately so the other lazy AJAX
            // requests can run in parallel server-side. Without this each
            // request held the session file lock end-to-end and they ran
            // serially — Sarah saw all lazy buckets stuck on "Loading…"
            // 2026-05-20 because secondaryBuckets blocked events + others.
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['bucket' => [
                    'label' => 'Manager picks',
                    'why' => 'Pick a store first.',
                    'items' => [], 'count' => 0,
                ]]);
            }
            $permitted = auth()->user()->permitted_locations();
            $bucket = $this->inventoryCheckService->bucketManagerPicksPublic($business_id, $locationId, $permitted);
            return response()->json(['bucket' => $bucket]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA manager-picks bucket failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['bucket' => [
                'label' => 'Manager picks',
                'why' => 'Manager picks failed to load: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'fetch_error',
            ]]);
        }
    }

    public function listManagerPicks(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $picks = $this->inventoryCheckService->loadManagerPicks($business_id);
        return response()->json(['picks' => $picks]);
    }

    public function addManagerPick(Request $request)
    {
        $request->validate([
            'note' => 'required|string|max:500',
            'category_pattern' => 'nullable|string|max:191',
            'suggested_by' => 'nullable|string|max:64',
        ]);
        $business_id = (int) $request->session()->get('user.business_id');
        $picks = $this->inventoryCheckService->loadManagerPicks($business_id);

        $by = trim((string) $request->input('suggested_by', ''));
        if ($by === '') {
            $by = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
            if ($by === '') $by = 'Manager';
        }

        $picks[] = [
            'id' => $this->inventoryCheckService->newPickId(),
            'note' => trim((string) $request->input('note')),
            'category_pattern' => trim((string) $request->input('category_pattern', '')),
            'suggested_by' => $by,
            'created_at' => Carbon::now()->toIso8601String(),
            'dismissed' => false,
            'dismissed_at' => null,
            'dismissed_by' => null,
        ];
        $this->inventoryCheckService->saveManagerPicks($business_id, $picks);
        return response()->json(['success' => true, 'picks' => $picks]);
    }

    public function dismissManagerPick(Request $request, string $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $picks = $this->inventoryCheckService->loadManagerPicks($business_id);
        $dismissedBy = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
        $found = false;
        foreach ($picks as &$p) {
            if (($p['id'] ?? null) === $id) {
                $p['dismissed'] = true;
                $p['dismissed_at'] = Carbon::now()->toIso8601String();
                $p['dismissed_by'] = $dismissedBy;
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) {
            return response()->json(['success' => false, 'error' => 'not_found'], 404);
        }
        $this->inventoryCheckService->saveManagerPicks($business_id, $picks);
        return response()->json(['success' => true, 'picks' => $picks]);
    }

    /**
     * Wizard step progress — shared per-store checklist for the step-by-step
     * "Order for this week" flow. Resets weekly (ISO week key). GET returns
     * this week's done/skipped map for one store.
     */
    public function wizardProgress(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $store = trim((string) $request->input('store', ''));
        $steps = $store !== ''
            ? $this->inventoryCheckService->getWizardWeekSteps($business_id, $store)
            : [];
        return response()->json([
            'week' => $this->inventoryCheckService->wizardWeekKey(),
            'store' => $store,
            'steps' => (object) $steps,
        ]);
    }

    /**
     * Update a wizard step for a store this week — set/clear the done|skipped
     * state and/or save a shared note. State and note are independent so one
     * never clobbers the other.
     */
    public function setWizardProgress(Request $request)
    {
        $request->validate([
            'store' => 'required|string|max:64',
            'step' => 'required|string|max:64',
            'state' => 'nullable|in:done,skipped,reset',
            'note' => 'nullable|string|max:1000',
        ]);
        $business_id = (int) $request->session()->get('user.business_id');
        $store = (string) $request->input('store');
        $step = (string) $request->input('step');
        $by = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
        if ($by === '') $by = 'Staff';

        $steps = $this->inventoryCheckService->getWizardWeekSteps($business_id, $store);
        // Note is saved when the field is present (blank string clears it).
        if ($request->has('note')) {
            $steps = $this->inventoryCheckService->setWizardNote($business_id, $store, $step, (string) $request->input('note'), $by);
        }
        if ($request->filled('state')) {
            $steps = $this->inventoryCheckService->setWizardStep($business_id, $store, $step, (string) $request->input('state'), $by);
        }
        return response()->json([
            'success' => true,
            'week' => $this->inventoryCheckService->wizardWeekKey(),
            'steps' => (object) $steps,
        ]);
    }

    /**
     * Single endpoint returning the slow secondary buckets (chart picks,
     * long-OOS, hot-used, top-artist new releases). Page renders the
     * primary fast_oos + customer_wants first, then JS fires this to
     * fill in the rest so "Building…" doesn't hang on the 365-day
     * long-OOS scan + 4-way top-artists join (Sarah 2026-05-20).
     */
    public function secondaryBuckets(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            // Release the session lock immediately so the other lazy AJAX
            // requests can run in parallel server-side. Without this each
            // request held the session file lock end-to-end and they ran
            // serially — Sarah saw all lazy buckets stuck on "Loading…"
            // 2026-05-20 because secondaryBuckets blocked events + others.
            $request->session()->save();
            $input = $request->only(['location_id', 'preset']);
            if (!empty($input['preset'])) {
                $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
                $input = array_merge($resolved, $input);
            }
            $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
            if (!$locationId) {
                return response()->json(['buckets' => []]);
            }
            $permitted = auth()->user()->permitted_locations();
            $buckets = $this->inventoryCheckService->buildSecondaryBuckets($business_id, $locationId, $permitted);
            return response()->json(['buckets' => $buckets]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA secondary buckets failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json(['buckets' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Inline stock correction from the Frozen bucket. Sarah 2026-05-20:
     * she needs to fix items that show as "frozen on shelf" when they're
     * actually gone (Discogs sales, shrinkage, miscounts).
     *
     * Updates variation_location_details.qty_available directly + logs a
     * before/after audit entry to storage/app/ica-frozen-corrections.json.
     * No new migration. The JSON log is what surfaces "last updated by
     * who, when" back on the Frozen bucket rows.
     */
    public function frozenStockUpdate(Request $request)
    {
        $request->validate([
            'variation_id' => 'required|integer',
            'location_id' => 'required|integer',
            'new_qty' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);
        $business_id = (int) $request->session()->get('user.business_id');
        $vid = (int) $request->input('variation_id');
        $lid = (int) $request->input('location_id');
        $newQty = (float) $request->input('new_qty');

        // Snapshot current qty before writing — required by the
        // feedback_no_destructive_writes rule (any admin mutation must
        // record a reversible before-state).
        $vld = \Illuminate\Support\Facades\DB::table('variation_location_details')
            ->where('variation_id', $vid)
            ->where('location_id', $lid)
            ->first();
        if (!$vld) {
            return response()->json(['success' => false, 'error' => 'vld_not_found'], 404);
        }
        $before = (float) ($vld->qty_available ?? 0);

        \Illuminate\Support\Facades\DB::table('variation_location_details')
            ->where('id', $vld->id)
            ->update([
                'qty_available' => $newQty,
                'updated_at' => Carbon::now(),
            ]);

        // Mirror into product_stock_cache so the next bucket build sees
        // the corrected stock without waiting for the PSC refresh job.
        \Illuminate\Support\Facades\DB::table('product_stock_cache')
            ->where('business_id', $business_id)
            ->where('variation_id', $vid)
            ->where('location_id', $lid)
            ->update(['stock' => $newQty, 'updated_at' => Carbon::now()]);

        $user = auth()->user();
        $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ('user#' . ($user->id ?? 0));
        $entry = [
            'variation_id' => $vid,
            'location_id' => $lid,
            'before' => $before,
            'after' => $newQty,
            'user_id' => (int) ($user->id ?? 0),
            'user_name' => $userName,
            'note' => (string) $request->input('note', ''),
            'when' => Carbon::now()->toIso8601String(),
        ];

        $path = storage_path('app/ica-frozen-corrections-' . $business_id . '.json');
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $log = [];
        if (is_file($path)) {
            try { $log = json_decode((string) file_get_contents($path), true) ?: []; } catch (\Throwable $e) { $log = []; }
        }
        $log[] = $entry;
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);

        // Bust the fast_oos cache so a corrected stock surfaces on the
        // very next page build instead of waiting up to 5 min.
        \Illuminate\Support\Facades\Cache::forget('ica_fast_oos_' . $business_id . '_' . $lid);

        return response()->json(['success' => true, 'entry' => $entry]);
    }

    /**
     * Supplier price-feed upload. Sarah ships catalog files weekly from
     * each distributor (AMS / Alliance / Secretly / Beggars / Red Eye /
     * VP). Each file is parsed via parseSupplierFeedFile() and saved as
     * storage/app/supplier-prices-{biz}-{supplier_key}.json. Subsequent
     * bucket builds look up the cheapest match per row.
     */
    /**
     * Accepts THREE input modes per Sarah 2026-05-20 ("no i do not have
     * excels for the supplier price feeds"):
     *   1. file upload (xlsx / csv / tsv)
     *   2. body paste — CSV/TSV text from a supplier portal
     *   3. quick single-row entry: artist + title + cost (+ optional format)
     * All three end up as merged rows in the same per-supplier JSON.
     */
    public function uploadSupplierFeed(Request $request)
    {
        $supplierKey = strtolower(trim((string) $request->input('supplier_key', '')));
        $known = $this->inventoryCheckService->knownSuppliers();
        if (!isset($known[$supplierKey])) {
            return response()->json(['success' => false, 'message' => 'Unknown supplier: ' . $supplierKey], 422);
        }
        $business_id = (int) $request->session()->get('user.business_id');

        $mode = (string) $request->input('mode', 'file');
        $parsedRows = [];
        $sourceLabel = '';

        try {
            if ($mode === 'single') {
                $artist = trim((string) $request->input('artist', ''));
                $title = trim((string) $request->input('title', ''));
                $cost = $request->input('cost');
                $format = trim((string) $request->input('format', ''));
                if ($artist === '' && $title === '') {
                    return response()->json(['success' => false, 'message' => 'Need at least artist or title.'], 422);
                }
                $clean = is_numeric($cost) ? (float) $cost : null;
                if ($clean === null || $clean <= 0) {
                    return response()->json(['success' => false, 'message' => 'Cost must be a number > 0.'], 422);
                }
                $parsedRows = [[
                    'artist' => $artist !== '' ? $artist : null,
                    'title' => $title !== '' ? $title : null,
                    'format' => $format !== '' ? $format : null,
                    'cost' => $clean,
                    'upc' => null,
                ]];
                $sourceLabel = 'quick-add ' . trim($artist . ' / ' . $title, ' /');
            } elseif ($mode === 'paste') {
                $body = (string) $request->input('body', '');
                if (trim($body) === '') {
                    return response()->json(['success' => false, 'message' => 'Paste at least one row.'], 422);
                }
                $tmp = tempnam(sys_get_temp_dir(), 'supplier_paste');
                file_put_contents($tmp, $body);
                try {
                    $parsedRows = $this->inventoryCheckService->parseSupplierFeedFile($tmp, 'paste.csv')['rows'] ?? [];
                } finally {
                    @unlink($tmp);
                }
                if (empty($parsedRows)) {
                    return response()->json(['success' => false, 'message' => 'Couldn\'t find Artist + Title + Cost columns. Paste a header row like "Artist, Title, Cost" first.'], 422);
                }
                $sourceLabel = 'pasted ' . count($parsedRows) . ' rows';
            } else {
                // file mode
                $file = $request->file('feed_file');
                if (!$file) {
                    return response()->json(['success' => false, 'message' => 'No file uploaded.'], 422);
                }
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, ['xlsx', 'xls', 'csv', 'tsv', 'txt'], true)) {
                    return response()->json(['success' => false, 'message' => 'Use xlsx, csv, tsv, or txt.'], 422);
                }
                $parsedRows = $this->inventoryCheckService->parseSupplierFeedFile($file->getRealPath(), $file->getClientOriginalName())['rows'] ?? [];
                $sourceLabel = $file->getClientOriginalName();
                if (empty($parsedRows)) {
                    return response()->json(['success' => false, 'message' => 'Couldn\'t find Artist + Title + Cost in the file.'], 422);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA supplier upload failed', ['err' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
        }

        // Merge with any prior rows for this supplier — quick-add entries
        // accumulate so Sarah can build the catalog up one title at a
        // time. Dedupe by (artist|title|format) keeping the latest cost.
        $existing = $this->inventoryCheckService->loadSupplierFeed($business_id, $supplierKey);
        $existingRows = is_array($existing['rows'] ?? null) ? $existing['rows'] : [];
        $byKey = [];
        foreach ($existingRows as $r) {
            $k = mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''));
            $byKey[$k] = $r;
        }
        foreach ($parsedRows as $r) {
            $k = mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''));
            $byKey[$k] = $r;
        }
        $mergedRows = array_values($byKey);

        $payload = [
            'business_id' => $business_id,
            'supplier_key' => $supplierKey,
            'supplier_label' => $known[$supplierKey]['label'] ?? $supplierKey,
            'source_file' => $sourceLabel,
            'imported_at' => Carbon::now()->toIso8601String(),
            'imported_by' => trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? '')) ?: 'staff',
            'rows' => $mergedRows,
        ];
        $this->inventoryCheckService->saveSupplierFeed($business_id, $supplierKey, $payload);

        return response()->json([
            'success' => true,
            'supplier_key' => $supplierKey,
            'supplier_label' => $payload['supplier_label'],
            'row_count' => count($mergedRows),
            'added_rows' => count($parsedRows),
            'imported_at' => $payload['imported_at'],
            'source_file' => $sourceLabel,
        ]);
    }

    /**
     * Trigger the auto-fetch for one supplier from the browser. Returns
     * JSON { success, exit_code, output } — the shape the ICA JS parses.
     *
     * 2026-07-17 Sarah: the buttons "never fetched" — even AMS. Root cause
     * was NOT the transport: it was that a portal walk ran essentially
     * unbounded (AmsFetcher paged the whole catalog + hundreds of barcode
     * lookups) for minutes inside the web request, so nginx/PHP-FPM closed
     * the request long before it finished and the browser fetch() just span.
     *
     * Fix is two-part: (1) every fetcher is now bounded by a wall-clock
     * budget (AMS_/REDEYE_FETCH_BUDGET_SEC, ~45s) so a single run always
     * returns quickly with whatever it pulled — repeat runs + the Monday
     * cron advance into the rest of the catalog via the "skip already
     * priced" logic; (2) this endpoint stays a plain synchronous JSON call
     * so it works with the JS already cached in the browser (a streamed
     * variant needed a JS update that the asset cache wouldn't pick up).
     */
    public function runSupplierAutoFetch(Request $request)
    {
        $request->validate(['supplier_key' => 'required|string|max:32']);
        $supplierKey = strtolower(trim((string) $request->input('supplier_key')));
        // Validate against the known set — the key becomes an artisan arg.
        $known = $this->inventoryCheckService->knownSuppliers();
        if (!isset($known[$supplierKey])) {
            return response()->json(['success' => false, 'output' => 'Unknown supplier: ' . $supplierKey], 422);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        // Release the session lock so other lazy AJAX keeps working while
        // this (bounded) HTTP-out call is in flight.
        $request->session()->save();
        // Budgets keep the fetch well under a minute, but give PHP headroom
        // over its 30s default so a slow login can't kill it mid-run.
        @set_time_limit(150);

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call('supplier-prices:fetch', [
                'supplier' => $supplierKey,
                '--business-id' => $business_id,
            ]);
            $output = \Illuminate\Support\Facades\Artisan::output();
            return response()->json([
                'success' => $exit === 0,
                'exit_code' => $exit,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA supplier auto-fetch failed', ['err' => $e->getMessage()]);
            return response()->json(['success' => false, 'output' => $e->getMessage()], 500);
        }
    }

    /**
     * On-page diagnostic for "prices won't fetch". Runs entirely on the
     * server (same network + same encrypted creds the fetchers use) and
     * returns a plain-text report so the exact blocker is visible without
     * SSH: (1) is PHP cURL present, (2) can the server actually reach each
     * portal (outbound egress — the #1 suspect for a months-long, every-
     * portal failure), (3) are credentials saved, (4) what did the last
     * auto-fetch actually say. Never prints or returns any secret value.
     */
    public function supplierDiagnostics(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $request->session()->save();

        $probeUrls = [
            'ams'      => 'https://www.allmediasupply.com/Account/LogOn',
            'alliance' => 'https://webami.aent.com/',
            'secretly' => 'https://b2b.secretlydistribution.com/login',
            'beggars'  => 'https://beggars.com/',
            'redeye'   => 'https://b2b.redeyeworldwide.com/login',
            'vp'       => 'https://vprecords.com/',
        ];
        $known = $this->inventoryCheckService->knownSuppliers();

        // Last-run status sidecar the fetch command writes.
        $statusPath = storage_path('app/supplier-fetch-status-' . $business_id . '.json');
        $status = is_file($statusPath) ? (json_decode((string) file_get_contents($statusPath), true) ?: []) : [];

        $L = [];
        $L[] = 'SUPPLIER FETCH DIAGNOSTICS — ' . Carbon::now()->toDayDateTimeString();
        $L[] = 'business_id: ' . $business_id;
        $L[] = 'PHP cURL extension: ' . (function_exists('curl_init') ? 'available' : 'MISSING (fetch cannot work at all)');
        $L[] = '';
        $L[] = 'OUTBOUND INTERNET (server → distributor portal):';
        $anyOk = false; $anyBlocked = false;
        foreach ($probeUrls as $key => $url) {
            $p = $this->egressProbe($url);
            $host = parse_url($url, PHP_URL_HOST);
            if ($p['code'] >= 200 && $p['code'] < 500 && $p['err'] === '') {
                $verdict = 'OK'; $anyOk = true;
            } else {
                $verdict = 'BLOCKED / UNREACHABLE'; $anyBlocked = true;
            }
            $L[] = sprintf('  %-9s %-28s HTTP %-3d %5.1fs  [%s]%s',
                strtoupper($key), $host, $p['code'], $p['time'], $verdict,
                $p['err'] !== '' ? '  ' . $p['err'] : '');
        }
        $L[] = '';
        $L[] = 'CREDENTIALS SAVED (encrypted on server — values never shown):';
        foreach ($known as $key => $meta) {
            $st = $this->inventoryCheckService->supplierCredentialsStatus($business_id, $key);
            $keys = array_keys(array_filter($st['configured_keys'] ?? []));
            $L[] = sprintf('  %-9s %s%s',
                strtoupper($key),
                $st['configured'] ? ('YES (' . implode(', ', $keys) . ')') : 'NO — no portal login saved',
                !empty($st['updated_at']) ? ('  · saved ' . substr($st['updated_at'], 0, 10)) : '');
        }
        $L[] = '';
        $L[] = 'LAST AUTO-FETCH RESULT (from the status log):';
        if (empty($status)) {
            $L[] = '  (no run recorded yet)';
        } else {
            foreach ($status as $key => $s) {
                $L[] = sprintf('  %-9s %s — %s  · %s',
                    strtoupper($key),
                    ($s['ok'] ?? false) ? 'OK' : 'FAIL',
                    (string) ($s['message'] ?? ''),
                    substr((string) ($s['at'] ?? ''), 0, 19));
            }
        }
        $L[] = '';
        $L[] = 'READING:';
        if (!$anyOk && $anyBlocked) {
            $L[] = '  Every portal is unreachable from the server → this is an OUTBOUND FIREWALL / egress block on';
            $L[] = '  the ERP box. No scraper can work until the server is allowed to reach these hosts. This is an';
            $L[] = '  infra change on the server (or run the pull from a host that has internet and push results in).';
        } elseif ($anyOk) {
            $L[] = '  The server CAN reach the portals, so egress is fine. If a supplier still returns 0 rows, the';
            $L[] = '  cause is per-supplier: credentials not saved (see above), a bounced login, or a changed page.';
        }

        // Return plain text so this GET URL can be opened directly in the
        // browser and read/screenshotted — no JS, no asset cache to fight.
        // (?json=1 still returns JSON for the in-page button.)
        if (filter_var($request->input('json'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['report' => implode("\n", $L)]);
        }
        return response(implode("\n", $L), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /** Short outbound HTTP probe used only by supplierDiagnostics(). */
    protected function egressProbe(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $err = (string) curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'time' => $time, 'err' => $err];
    }

    /**
     * Save supplier portal credentials from the UI form. Sarah 2026-05-
     * 21 lost SSH access so .env can't be hand-edited anymore — the
     * encrypted creds file (storage/app/supplier-creds-{biz}-{key}.enc)
     * is the new source of truth.
     *
     * Accepts any subset of {portal_user, portal_pass, portal_account,
     * portal_url, prices_url} — sparse updates preserve existing values.
     * Never returns the saved values back.
     */
    public function saveSupplierCredentials(Request $request)
    {
        $request->validate(['supplier_key' => 'required|string|max:32']);
        $supplierKey = strtolower(trim((string) $request->input('supplier_key')));
        $known = $this->inventoryCheckService->knownSuppliers();
        if (!isset($known[$supplierKey])) {
            return response()->json(['success' => false, 'message' => 'Unknown supplier'], 422);
        }
        $business_id = (int) $request->session()->get('user.business_id');

        // Map form field names → credential file keys (which are the same
        // names AbstractHttpFetcher::requireEnv strips the supplier prefix
        // down to, e.g. AMS_PORTAL_PASS → PORTAL_PASS).
        $payload = [];
        foreach ([
            'portal_user'    => 'PORTAL_USER',
            'portal_pass'    => 'PORTAL_PASS',
            'portal_account' => 'PORTAL_ACCOUNT',
            'portal_url'     => 'PORTAL_URL',
            'prices_url'     => 'PRICES_URL',
        ] as $form => $stored) {
            $v = $request->input($form);
            if ($v !== null && $v !== '') {
                $payload[$stored] = (string) $v;
            }
        }
        if (empty($payload)) {
            return response()->json(['success' => false, 'message' => 'Nothing to save.'], 422);
        }

        $this->inventoryCheckService->saveSupplierCredentials($business_id, $supplierKey, $payload);
        return response()->json([
            'success' => true,
            'status' => $this->inventoryCheckService->supplierCredentialsStatus($business_id, $supplierKey),
        ]);
    }

    public function getSupplierCredentialsStatus(Request $request, string $supplierKey)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        return response()->json([
            'status' => $this->inventoryCheckService->supplierCredentialsStatus($business_id, $supplierKey),
        ]);
    }

    public function listSupplierFeeds(Request $request)
    {
        try {
            $business_id = (int) $request->session()->get('user.business_id');
            $request->session()->save();
            return response()->json([
                'feeds' => $this->inventoryCheckService->supplierFeedSummary($business_id),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ICA listSupplierFeeds failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            // Always return JSON so the JS .then((r) => r.json()) doesn't
            // throw on an HTML error page — surface the message so the
            // UI can show it instead of staying on "Loading…" forever.
            return response()->json([
                'feeds' => [],
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * Log a purchase against the weekly budget without going through the
     * formal /buy-from-customer or /purchases/create flow. Sarah's case
     * 2026-05-20: Jon spent $2k on a collection on Sunday and just needs
     * the budget bar to reflect it. Stored in
     * storage/app/ica-manual-budget-entries-{biz}.json; pulled into
     * currentPurchaseBudget()'s spent total alongside the formal
     * transactions sum. JSON-on-disk, no migration.
     */
    public function addManualBudgetEntry(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'note' => 'nullable|string|max:500',
            'source' => 'nullable|string|max:191',
            'kind' => 'nullable|string|in:used,new',
        ]);
        $business_id = (int) $request->session()->get('user.business_id');
        $entries = $this->inventoryCheckService->loadManualBudgetEntries($business_id);
        $user = auth()->user();
        $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ('user#' . ($user->id ?? 0));
        $kind = strtolower((string) $request->input('kind', 'new'));
        if (!in_array($kind, ['used', 'new'], true)) $kind = 'new';
        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'amount' => (float) $request->input('amount'),
            'date' => substr((string) $request->input('date'), 0, 10),
            'source' => trim((string) $request->input('source', '')),
            'note' => trim((string) $request->input('note', '')),
            'kind' => $kind,
            'user_id' => (int) ($user->id ?? 0),
            'user_name' => $userName,
            'when' => Carbon::now()->toIso8601String(),
        ];
        $entries[] = $entry;
        $this->inventoryCheckService->saveManualBudgetEntries($business_id, $entries);

        // Return the refreshed budget so the bar can re-render without
        // a full page reload.
        $permitted = auth()->user()->permitted_locations();
        $budget = $this->inventoryCheckService->currentPurchaseBudget($business_id, $permitted);
        return response()->json(['success' => true, 'entry' => $entry, 'budget' => $budget]);
    }

    public function deleteManualBudgetEntry(Request $request, string $id)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $entries = $this->inventoryCheckService->loadManualBudgetEntries($business_id);
        $filtered = array_values(array_filter($entries, fn ($e) => ($e['id'] ?? null) !== $id));
        if (count($filtered) === count($entries)) {
            return response()->json(['success' => false, 'error' => 'not_found'], 404);
        }
        $this->inventoryCheckService->saveManualBudgetEntries($business_id, $filtered);
        $permitted = auth()->user()->permitted_locations();
        $budget = $this->inventoryCheckService->currentPurchaseBudget($business_id, $permitted);
        return response()->json(['success' => true, 'budget' => $budget]);
    }

    public function export(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $input = $request->only([
            'location_id', 'category_id', 'category_ids', 'preset',
        ]);

        if (!empty($input['preset'])) {
            $resolved = $this->inventoryCheckService->resolvePreset($business_id, $input['preset']);
            $input = array_merge($resolved, $input);
        }

        if (!empty($input['category_ids']) && is_string($input['category_ids'])) {
            $input['category_ids'] = array_filter(array_map('intval', explode(',', $input['category_ids'])));
        }

        $permitted = auth()->user()->permitted_locations();
        $result = $this->inventoryCheckService->buildBuckets($business_id, $input, $permitted);

        $columns = config('inventory_check.ams_export_columns', []);
        $self = $this;

        $callback = function () use ($result, $columns, $self) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($result['buckets'] as $key => $bucket) {
                foreach ($bucket['items'] as $r) {
                    $r['bucket'] = $key;
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $self->exportColumnValue($col, $r);
                    }
                    fputcsv($out, $line);
                }
            }
            fclose($out);
        };

        $filename = 'order_for_this_week_' . Carbon::now()->format('Y-m-d_His') . '.csv';

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected function exportColumnValue(string $col, array $r): string
    {
        switch ($col) {
            case 'bucket': return (string) ($r['bucket'] ?? '');
            case 'sku': return (string) ($r['sku'] ?? '');
            case 'product': return (string) ($r['product'] ?? '');
            case 'artist': return (string) ($r['artist'] ?? '');
            case 'format': return (string) ($r['format'] ?? '');
            case 'location': return (string) ($r['location_name'] ?? '');
            case 'category': return (string) ($r['category_name'] ?? '');
            case 'current_stock': return (string) ($r['stock'] ?? '');
            case 'sold_qty_window': return (string) ($r['sold_qty_window'] ?? '');
            case 'avg_sell_days': return isset($r['avg_sell_days']) ? (string) $r['avg_sell_days'] : '';
            case 'suggested_qty': return (string) ($r['suggested_qty'] ?? '');
            case 'source_tags': return isset($r['tags']) ? implode('|', $r['tags']) : '';
            case 'reason': return (string) ($r['reason'] ?? '');
            case 'variation': return (string) ($r['variation_label'] ?? '');
            default: return '';
        }
    }

    // ── Chart paste imports (Street Pulse / Universal Top) ────────────

    public function importChart(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        // Accept EITHER a pasted body OR an uploaded chart file (xlsx, csv, tsv).
        // Sarah's actual sources don't fit a single textarea: Universal sends
        // an xlsx attachment; Luminate / Street Pulse exports as a tabular
        // chart. Validate one-or-the-other manually so the failure path is
        // a clean JSON 422 (Laravel's validate() helper sometimes redirects
        // with HTML on this older version, which the JS choked on with
        // "Unexpected token '<'").
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'source' => 'required|in:street_pulse,universal_top',
            'body' => 'nullable|string|max:500000',
            'week_of' => 'nullable|date',
            'chart_file' => 'nullable|file|max:20480',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'validation_failed',
                'message' => implode(' ', $validator->errors()->all()),
            ], 422);
        }
        if (!$request->filled('body') && !$request->hasFile('chart_file')) {
            return response()->json([
                'success' => false,
                'error' => 'no_input',
                'message' => 'Paste the chart body or upload an .xlsx / .csv file.',
            ], 422);
        }
        // Reject images cleanly (OCR is supposed to happen client-side)
        if ($request->hasFile('chart_file')) {
            $ext = strtolower($request->file('chart_file')->getClientOriginalExtension());
            $allowed = ['xlsx', 'xls', 'csv', 'tsv', 'txt'];
            if (!in_array($ext, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'error' => 'unsupported_file_type',
                    'message' => 'For image files (.png/.jpg), the browser OCR fills the paste box automatically — wait for "✓ Extracted N rows" before clicking Import. For other files use ' . implode(', ', $allowed) . '.',
                ], 422);
            }
        }

        if (!Schema::hasTable('chart_pick_imports') || !Schema::hasTable('chart_picks')) {
            return response()->json([
                'success' => false,
                'error' => 'migrations_missing',
                'message' => 'chart_picks tables not yet created on this server. Run "php artisan migrate" and try again.',
            ], 503);
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $source = $request->input('source');
        $weekOf = $request->input('week_of') ?: Carbon::now()->format('Y-m-d');
        $body = (string) $request->input('body', '');

        $rows = [];
        $rawForAudit = $body;
        $diagnostic = ['mode' => null, 'filename' => null];

        if ($request->hasFile('chart_file')) {
            $file = $request->file('chart_file');
            $filename = $file->getClientOriginalName();
            $diagnostic['mode'] = 'file';
            $diagnostic['filename'] = $filename;
            $rawForAudit = '[file: ' . $filename . ']';

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Universal-Top xlsx has a known multi-sheet layout (Top 200 +
            // deliveries + Key Anniversaries) that the dedicated UMe parser
            // handles best.
            if ($source === 'universal_top' && in_array($ext, ['xlsx', 'xls'], true)) {
                $rows = $this->parseUniversalXlsx($file->getRealPath(), $business_id);
            } else {
                $rows = app(TabularChartParser::class)->parseFile($file->getRealPath(), $filename);
            }
        } else {
            $diagnostic['mode'] = 'paste';
            // Peek at the first non-blank line — if it looks like a header
            // row with Title + Artist columns (e.g. the OCR output for
            // Luminate, or a CSV paste), route through the column-aware
            // TabularChartParser. ChartPickParser positionally assumes
            // "rank, artist, title" order which would silently swap
            // artist/title for Luminate's "rank, title, artist" layout.
            $firstLine = '';
            foreach (preg_split("/\r?\n/", $body) as $l) {
                $l = trim($l);
                if ($l !== '') { $firstLine = $l; break; }
            }
            $firstLower = mb_strtolower($firstLine);
            $hasArtistHdr = mb_strpos($firstLower, 'artist') !== false || mb_strpos($firstLower, 'performer') !== false;
            $hasTitleHdr = mb_strpos($firstLower, 'title') !== false || mb_strpos($firstLower, 'album') !== false;
            $looksTabular = $hasArtistHdr && $hasTitleHdr;

            if ($looksTabular) {
                $tmp = tempnam(sys_get_temp_dir(), 'chartpaste');
                file_put_contents($tmp, $body);
                try {
                    $rows = app(TabularChartParser::class)->parseCsv($tmp);
                } finally {
                    @unlink($tmp);
                }
            } else {
                $rows = $this->chartPickParser->parse($body, $source);
                // Fallback: if line parser came up empty, try tabular parse.
                if (empty($rows)) {
                    $tmp = tempnam(sys_get_temp_dir(), 'chartpaste');
                    file_put_contents($tmp, $body);
                    try {
                        $rows = app(TabularChartParser::class)->parseCsv($tmp);
                    } finally {
                        @unlink($tmp);
                    }
                }
            }
        }

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'error' => 'no_rows_parsed',
                'message' => 'Could not find Artist + Title columns in the input. For xlsx/csv files make sure they have headers like "Artist" and "Title" (or "ARTIST NAME" / "Title"). For paste, format each line as "Artist — Title — Format".',
                'diagnostic' => $diagnostic,
            ], 422);
        }

        return DB::transaction(function () use ($business_id, $source, $weekOf, $rawForAudit, $rows, $diagnostic) {
            $import = ChartPickImport::create([
                'business_id' => $business_id,
                'source' => $source,
                'week_of' => $weekOf,
                'imported_by' => auth()->id(),
                'row_count' => count($rows),
                'raw_body' => mb_substr($rawForAudit, 0, 65535),
            ]);

            // Replace any existing picks for this source+week (idempotent re-paste / re-upload)
            ChartPick::where('business_id', $business_id)
                ->where('source', $source)
                ->whereDate('week_of', $weekOf)
                ->delete();

            foreach ($rows as $row) {
                ChartPick::create([
                    'import_id' => $import->id,
                    'business_id' => $business_id,
                    'source' => $source,
                    'week_of' => $weekOf,
                    'chart_rank' => $row['rank'] ?? null,
                    'artist' => $row['artist'] ?? null,
                    'title' => $row['title'] ?? null,
                    'format' => $row['format'] ?? null,
                    'is_new_release' => !empty($row['is_new_release']),
                ]);
            }

            return response()->json([
                'success' => true,
                'source' => $source,
                'week_of' => $weekOf,
                'parsed_rows' => count($rows),
                'import_id' => $import->id,
                'diagnostic' => $diagnostic,
            ]);
        });
    }

    /**
     * UMe Universal xlsx → flat row list. Pulls Top 200 (vinyl + CD) and
     * this-week deliveries; deliveries get is_new_release=true.
     *
     * Side effect: persists the "Key Anniversaries + Birthdays" tab to
     * storage/app/universal-anniversaries-{business_id}.json so the events
     * bucket can show artist moments alongside concerts (MJ biopic, Drake
     * tour announcement, etc.). JSON-on-disk pattern keeps this no-migration.
     */
    protected function parseUniversalXlsx(string $path, ?int $business_id = null): array
    {
        $parser = app(UniversalChartParser::class);
        $parsed = $parser->parse($path);
        $rows = [];
        foreach ($parsed['top_200_vinyl'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => false]);
        }
        foreach ($parsed['top_200_cd'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => false]);
        }
        foreach ($parsed['deliveries_vinyl'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => true]);
        }
        foreach ($parsed['deliveries_cd'] as $r) {
            $rows[] = array_merge($r, ['is_new_release' => true]);
        }

        if ($business_id && !empty($parsed['anniversaries'])) {
            $this->saveUniversalAnniversaries($business_id, $parsed['anniversaries']);
        }

        return $rows;
    }

    protected function saveUniversalAnniversaries(int $business_id, array $anniversaries): void
    {
        $path = storage_path('app/universal-anniversaries-' . $business_id . '.json');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'business_id' => $business_id,
            'updated_at' => Carbon::now()->toIso8601String(),
            'source' => 'universal_xlsx',
            'anniversaries' => array_values($anniversaries),
        ];
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public function latestChart(Request $request, string $source)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).
        if (!in_array($source, ['street_pulse', 'universal_top'], true)) {
            abort(404);
        }

        $business_id = (int) $request->session()->get('user.business_id');
        $import = ChartPickImport::where('business_id', $business_id)
            ->where('source', $source)
            ->orderByDesc('week_of')
            ->first();

        return response()->json([
            'import' => $import,
            'row_count' => $import ? ChartPick::where('import_id', $import->id)->count() : 0,
        ]);
    }

    // ── Run the email-import command from the browser ─────────────────

    public function runEmailImport(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        // Request::boolean() doesn't exist on this Laravel version — use
        // filter_var. Without this fix, the button silently 500s (this is
        // why "the apple report wont fetch" looked broken).
        $dryRun = filter_var($request->input('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $since = max(1, (int) $request->input('since', 7));
        $businessId = (int) $request->session()->get('user.business_id');

        $args = [
            '--since' => $since,
            '--business-id' => $businessId,
        ];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }

        try {
            $exit = Artisan::call('charts:import-from-email', $args);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => $exit === 0,
            'exit_code' => $exit,
            'dry_run' => $dryRun,
            'output' => $output,
        ]);
    }

    public function runAppleMusicImport(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $dryRun = filter_var($request->input('dry_run'), FILTER_VALIDATE_BOOLEAN);
        $businessId = (int) $request->session()->get('user.business_id');

        $args = ['--business-id' => $businessId];
        if ($dryRun) {
            $args['--dry-run'] = true;
        }

        try {
            $exit = Artisan::call('charts:import-apple-music', $args);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => $exit === 0,
            'exit_code' => $exit,
            'dry_run' => $dryRun,
            'output' => $output,
        ]);
    }

    // ── Customer Wants fulfillment from the ICA view ──────────────────

    public function fulfillCustomerWant(Request $request, $id)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $want = CustomerWant::where('business_id', $business_id)
            ->where('id', (int) $id)
            ->firstOrFail();

        $want->status = 'fulfilled';
        $want->fulfilled_by = auth()->id();
        $want->fulfilled_at = Carbon::now();
        $want->fulfilled_note = $request->input('note') ?: 'marked via Inventory Check Assistant';
        $want->save();

        return response()->json(['success' => true, 'customer_want' => $want]);
    }

    // ── Notes (Street Pulse annotations / one-off customer-request notes) ──

    public function listNotes(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $q = InventoryCheckNote::where('business_id', $business_id)
            ->orderByDesc('id')
            ->limit(100);

        if ($request->filled('location_id')) {
            $q->where('location_id', (int) $request->location_id);
        }
        if ($request->filled('note_type')) {
            $q->where('note_type', $request->note_type);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function storeNote(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $request->validate([
            'note_type' => 'required|in:street_pulse,customer_request',
            'body' => 'required|string|max:20000',
            'location_id' => 'nullable|integer',
            'reference_date' => 'nullable|date',
            'product_id' => 'nullable|integer',
            'variation_id' => 'nullable|integer',
        ]);

        $business_id = (int) $request->session()->get('user.business_id');
        $note = InventoryCheckNote::create([
            'business_id' => $business_id,
            'location_id' => $request->input('location_id'),
            'note_type' => $request->input('note_type'),
            'body' => $request->input('body'),
            'reference_date' => $request->input('reference_date'),
            'product_id' => $request->input('product_id'),
            'variation_id' => $request->input('variation_id'),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'note' => $note]);
    }

    public function destroyNote($id)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) request()->session()->get('user.business_id');
        $note = InventoryCheckNote::where('business_id', $business_id)->where('id', (int) $id)->firstOrFail();
        $note->delete();

        return response()->json(['success' => true]);
    }

    // ── Saved sessions ────────────────────────────────────────────────

    public function listSessions(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $sessions = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $sessions]);
    }

    public function storeSession(Request $request)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $request->validate([
            'name' => 'required|string|max:191',
            'location_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'sale_start' => 'nullable|date',
            'sale_end' => 'nullable|date',
            'preset_key' => 'nullable|string|max:64',
            'state_json' => 'nullable|string',
        ]);

        $business_id = (int) $request->session()->get('user.business_id');

        $session = InventoryCheckSession::create([
            'business_id' => $business_id,
            'user_id' => auth()->id(),
            'name' => $request->input('name'),
            'location_id' => $request->input('location_id'),
            'category_id' => $request->input('category_id'),
            'supplier_id' => $request->input('supplier_id'),
            'sale_start' => $request->input('sale_start'),
            'sale_end' => $request->input('sale_end'),
            'preset_key' => $request->input('preset_key'),
            'state_json' => $request->input('state_json'),
        ]);

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function updateSession(Request $request, $id)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) $request->session()->get('user.business_id');
        $session = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->where('id', (int) $id)
            ->firstOrFail();

        $request->validate([
            'name' => 'sometimes|string|max:191',
            'state_json' => 'nullable|string',
            'location_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'sale_start' => 'nullable|date',
            'sale_end' => 'nullable|date',
            'preset_key' => 'nullable|string|max:64',
        ]);

        $session->fill($request->only([
            'name', 'state_json', 'location_id', 'category_id', 'supplier_id',
            'sale_start', 'sale_end', 'preset_key',
        ]));
        $session->save();

        return response()->json(['success' => true, 'session' => $session]);
    }

    public function destroySession($id)
    {
        // Open to all authenticated staff — inventory check assistant is
        // operational reorder data, not aggregated sales (Sarah 2026-04-28).

        $business_id = (int) request()->session()->get('user.business_id');
        $session = InventoryCheckSession::where('business_id', $business_id)
            ->where('user_id', auth()->id())
            ->where('id', (int) $id)
            ->firstOrFail();
        $session->delete();

        return response()->json(['success' => true]);
    }
}
