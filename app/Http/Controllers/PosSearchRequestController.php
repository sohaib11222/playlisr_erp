<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\PosSearchMiss;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use DB;
use Schema;

/**
 * POS Requests — product searches at the register that returned nothing.
 *
 * The POS search box already tells the cashier "no products found"; until now
 * that signal evaporated. Every one of those is a customer standing at the
 * counter asking for something we don't carry, so we log it and rank it.
 */
class PosSearchRequestController extends Controller
{
    /**
     * How long a single burst of typing is assumed to last. The autocomplete
     * fires on every keystroke past 2 characters, so "rad" -> "radioh" ->
     * "radiohead" arrives as several zero-result searches; anything inside this
     * window that is a prefix of (or prefixed by) the previous miss from the
     * same cashier is folded into one row.
     */
    const TYPING_RUN_SECONDS = 90;

    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    /**
     * Called by the POS search box when a search comes back empty.
     * Best-effort: a logging failure must never disrupt a sale.
     */
    public function logEmptySearch(Request $request)
    {
        try {
            if (!Schema::hasTable('pos_search_misses')) {
                return response()->json(['ok' => false]);
            }

            $term = $this->normalizeTerm($request->input('term', ''));
            if (mb_strlen($term) < 3) {
                return response()->json(['ok' => false]);
            }

            $business_id = (int) $request->session()->get('user.business_id');
            if (empty($business_id)) {
                return response()->json(['ok' => false]);
            }

            $user_id = optional(auth()->user())->id;
            $location_id = $request->input('location_id');
            $location_id = is_numeric($location_id) ? (int) $location_id : null;
            $is_scan = (bool) preg_match('/^[0-9]+$/', $term);

            $previous = $this->lastMissInTypingRun($business_id, $user_id);

            if ($previous && $this->sameTypingRun($term, $previous->term)) {
                // Keep the fullest version of what they typed.
                if (mb_strlen($term) > mb_strlen($previous->term)) {
                    $previous->term = $term;
                    $previous->is_scan = $is_scan;
                }
                $previous->location_id = $location_id ?: $previous->location_id;
                $previous->created_at = now();
                $previous->save();

                return response()->json(['ok' => true, 'collapsed' => true]);
            }

            PosSearchMiss::create([
                'business_id' => $business_id,
                'location_id' => $location_id,
                'user_id' => $user_id,
                'term' => $term,
                'is_scan' => $is_scan,
                'created_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            \Log::warning('pos empty search log failed', ['err' => $e->getMessage()]);

            return response()->json(['ok' => false]);
        }
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $is_admin = $this->businessUtil->is_admin(auth()->user());

        $period = $request->input('period', 'last_30');
        $location_id = $request->input('location_id');
        $type = $request->input('type', 'typed');

        [$start, $end] = $this->resolveWindow($period);

        $business_locations = BusinessLocation::forDropdown($business_id);

        if (!Schema::hasTable('pos_search_misses')) {
            return view('report.pos_search_requests')->with([
                'period' => $period, 'start' => $start, 'end' => $end,
                'location_id' => $location_id, 'type' => $type,
                'business_locations' => $business_locations, 'is_admin' => $is_admin,
                'total_misses' => 0, 'unique_terms' => 0, 'unique_users' => 0, 'scan_misses' => 0,
                'top_terms' => collect(), 'by_user' => collect(),
                'by_location' => collect(), 'recent' => collect(),
                'migration_pending' => true,
            ]);
        }

        $base = DB::table('pos_search_misses')
            ->where('pos_search_misses.business_id', $business_id)
            ->whereBetween('pos_search_misses.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);

        if (is_numeric($location_id)) {
            $base->where('pos_search_misses.location_id', (int) $location_id);
        }

        // Everyone sees the store's want-list — it's what the counter staff are
        // buying against, and it carries no sales figures. Only the "who
        // searched what" breakdown is held back to admins (see $by_user below).

        // Headline numbers cover the whole window regardless of the typed/scan
        // toggle, so the scan count stays visible while typed-only is selected.
        $total_misses = (clone $base)->count();
        $unique_users = (clone $base)->whereNotNull('pos_search_misses.user_id')
            ->distinct('pos_search_misses.user_id')->count('pos_search_misses.user_id');
        $scan_misses = (clone $base)->where('pos_search_misses.is_scan', 1)->count();

        if ($type === 'typed') {
            $base->where('pos_search_misses.is_scan', 0);
        } elseif ($type === 'scan') {
            $base->where('pos_search_misses.is_scan', 1);
        }

        $unique_terms = (clone $base)->distinct('pos_search_misses.term')->count('pos_search_misses.term');

        $top_terms = (clone $base)
            ->leftJoin('business_locations', 'business_locations.id', '=', 'pos_search_misses.location_id')
            ->select(
                'pos_search_misses.term',
                DB::raw('COUNT(*) as hits'),
                DB::raw('MAX(pos_search_misses.is_scan) as is_scan'),
                DB::raw('MIN(pos_search_misses.created_at) as first_asked'),
                DB::raw('MAX(pos_search_misses.created_at) as last_asked'),
                DB::raw('COUNT(DISTINCT pos_search_misses.user_id) as staff_count'),
                DB::raw("GROUP_CONCAT(DISTINCT COALESCE(business_locations.name, '(not recorded)') ORDER BY business_locations.name SEPARATOR ', ') as locations")
            )
            ->groupBy('pos_search_misses.term')
            ->orderByDesc('hits')
            ->orderByDesc(DB::raw('MAX(pos_search_misses.created_at)'))
            ->limit(100)
            ->get();

        if ($request->input('export') === 'csv') {
            return $this->exportCsv($top_terms, $start, $end);
        }

        $by_user = collect();
        if ($is_admin) {
            $by_user = (clone $base)
                ->leftJoin('users', 'users.id', '=', 'pos_search_misses.user_id')
                ->select(
                    'pos_search_misses.user_id',
                    DB::raw("CONCAT(COALESCE(users.surname,''), ' ', COALESCE(users.first_name,''), ' ', COALESCE(users.last_name,'')) as employee"),
                    DB::raw('COUNT(*) as misses'),
                    DB::raw('COUNT(DISTINCT pos_search_misses.term) as unique_terms'),
                    DB::raw('MAX(pos_search_misses.created_at) as last_searched')
                )
                ->groupBy('pos_search_misses.user_id', 'users.surname', 'users.first_name', 'users.last_name')
                ->orderByDesc('misses')
                ->get();
        }

        $by_location = (clone $base)
            ->leftJoin('business_locations', 'business_locations.id', '=', 'pos_search_misses.location_id')
            ->select(
                'pos_search_misses.location_id',
                'business_locations.name as location_name',
                DB::raw('COUNT(*) as misses'),
                DB::raw('COUNT(DISTINCT pos_search_misses.term) as unique_terms')
            )
            ->groupBy('pos_search_misses.location_id', 'business_locations.name')
            ->orderByDesc('misses')
            ->get();

        $recent = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'pos_search_misses.user_id')
            ->leftJoin('business_locations', 'business_locations.id', '=', 'pos_search_misses.location_id')
            ->select(
                'pos_search_misses.term',
                'pos_search_misses.is_scan',
                'pos_search_misses.created_at',
                'business_locations.name as location_name',
                DB::raw("CONCAT(COALESCE(users.surname,''), ' ', COALESCE(users.first_name,''), ' ', COALESCE(users.last_name,'')) as employee")
            )
            ->orderByDesc('pos_search_misses.created_at')
            ->limit(100)
            ->get();

        return view('report.pos_search_requests')->with(compact(
            'period', 'start', 'end', 'location_id', 'type',
            'business_locations', 'is_admin',
            'total_misses', 'unique_terms', 'unique_users', 'scan_misses',
            'top_terms', 'by_user', 'by_location', 'recent'
        ));
    }

    /**
     * "Did we ever get it?" — one term at a time, on click, so the report page
     * itself never pays for a LIKE scan across every term it lists.
     */
    public function catalogCheck(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $term = $this->normalizeTerm($request->input('term', ''));

        if (mb_strlen($term) < 3) {
            return response()->json(['matches' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $matches = DB::table('products')
            ->leftJoin('variations', 'variations.product_id', '=', 'products.id')
            ->leftJoin('variation_location_details as vld', 'vld.variation_id', '=', 'variations.id')
            ->where('products.business_id', $business_id)
            ->where(function ($q) use ($like) {
                $q->where('products.name', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.artist', 'like', $like);
            })
            ->select(
                'products.id',
                'products.name',
                'products.artist',
                DB::raw('COALESCE(SUM(vld.qty_available), 0) as qty')
            )
            ->groupBy('products.id', 'products.name', 'products.artist')
            ->orderByDesc('qty')
            ->limit(6)
            ->get();

        return response()->json([
            'term' => $term,
            'matches' => $matches->take(5)->map(function ($m) {
                return [
                    'id' => $m->id,
                    'name' => trim(($m->artist ? $m->artist.' — ' : '').$m->name),
                    'qty' => (float) $m->qty,
                ];
            })->values(),
            'more' => $matches->count() > 5,
        ]);
    }

    protected function exportCsv($top_terms, $start, $end)
    {
        $filename = 'pos-requests-'.$start->format('Y-m-d').'-to-'.$end->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($top_terms) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Search term', 'Times asked', 'Type', 'Staff who searched', 'Store(s)', 'First asked', 'Last asked']);
            foreach ($top_terms as $r) {
                fputcsv($out, [
                    $r->term,
                    $r->hits,
                    $r->is_scan ? 'Scan' : 'Typed',
                    $r->staff_count,
                    $r->locations,
                    $r->first_asked,
                    $r->last_asked,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function normalizeTerm($raw): string
    {
        $term = preg_replace('/\s+/u', ' ', trim((string) $raw));

        return mb_strtolower(mb_substr($term, 0, 191));
    }

    protected function lastMissInTypingRun(int $business_id, $user_id)
    {
        $query = PosSearchMiss::where('business_id', $business_id)
            ->where('created_at', '>=', now()->subSeconds(self::TYPING_RUN_SECONDS));

        if (is_null($user_id)) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $user_id);
        }

        return $query->orderByDesc('id')->first();
    }

    protected function sameTypingRun(string $term, string $previous): bool
    {
        return strpos($term, $previous) === 0 || strpos($previous, $term) === 0;
    }

    protected function resolveWindow(string $period): array
    {
        $now = \Carbon::now();
        switch ($period) {
            case 'today':
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'yesterday':
                return [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()];
            case 'this_week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfDay()];
            case 'last_7':
                return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
            case 'this_month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
            case 'this_quarter':
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()];
            case 'last_90':
                return [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()];
            case 'last_30':
            default:
                return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
        }
    }
}
