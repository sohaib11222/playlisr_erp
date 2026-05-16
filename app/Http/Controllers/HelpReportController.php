<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use DB;

class HelpReportController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'This report is admin-only.');
        }

        $business_id = $request->session()->get('user.business_id');
        $period = $request->input('period', 'last_30');

        [$start, $end] = $this->resolveWindow($period);
        $start_str = $start->toDateTimeString();
        $end_str   = $end->toDateTimeString();

        if (!\Schema::hasTable('help_searches')) {
            return view('report.help_searches')->with([
                'period' => $period, 'start' => $start, 'end' => $end,
                'total_searches' => 0, 'unique_users' => 0, 'zero_result_total' => 0,
                'top_queries' => collect(), 'zero_result_queries' => collect(),
                'by_user' => collect(), 'recent' => collect(),
                'migration_pending' => true,
            ]);
        }

        $base = DB::table('help_searches')
            ->where('help_searches.business_id', $business_id)
            ->whereBetween('help_searches.created_at', [$start_str, $end_str]);

        $total_searches = (clone $base)->count();
        $unique_users   = (clone $base)->whereNotNull('help_searches.user_id')->distinct('help_searches.user_id')->count('help_searches.user_id');
        $zero_result_total = (clone $base)->where('help_searches.result_count', 0)->count();

        $top_queries = (clone $base)
            ->select('query', DB::raw('COUNT(*) as hits'), DB::raw('MAX(created_at) as last_searched'), DB::raw('AVG(result_count) as avg_results'))
            ->groupBy('query')
            ->orderByDesc('hits')
            ->orderByDesc(DB::raw('MAX(created_at)'))
            ->limit(25)
            ->get();

        $zero_result_queries = (clone $base)
            ->where('result_count', 0)
            ->select('query', DB::raw('COUNT(*) as hits'), DB::raw('MAX(created_at) as last_searched'))
            ->groupBy('query')
            ->orderByDesc('hits')
            ->orderByDesc(DB::raw('MAX(created_at)'))
            ->limit(25)
            ->get();

        $by_user = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'help_searches.user_id')
            ->select(
                'help_searches.user_id',
                DB::raw("CONCAT(COALESCE(users.surname,''), ' ', COALESCE(users.first_name,''), ' ', COALESCE(users.last_name,'')) as employee"),
                DB::raw('COUNT(*) as searches'),
                DB::raw('SUM(CASE WHEN help_searches.result_count = 0 THEN 1 ELSE 0 END) as zero_results'),
                DB::raw('MAX(help_searches.created_at) as last_searched')
            )
            ->groupBy('help_searches.user_id', 'users.surname', 'users.first_name', 'users.last_name')
            ->orderByDesc('searches')
            ->get();

        $recent = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'help_searches.user_id')
            ->select(
                'help_searches.query',
                'help_searches.result_count',
                'help_searches.created_at',
                DB::raw("CONCAT(COALESCE(users.surname,''), ' ', COALESCE(users.first_name,''), ' ', COALESCE(users.last_name,'')) as employee")
            )
            ->orderByDesc('help_searches.created_at')
            ->limit(50)
            ->get();

        return view('report.help_searches')->with(compact(
            'period', 'start', 'end',
            'total_searches', 'unique_users', 'zero_result_total',
            'top_queries', 'zero_result_queries', 'by_user', 'recent'
        ));
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
            case 'last_30':
            default:
                return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
        }
    }
}
