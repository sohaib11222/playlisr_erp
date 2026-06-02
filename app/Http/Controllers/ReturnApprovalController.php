<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

// Read-only audit log of manager-approved returns/exchanges.
// Returns require manager sign-off (Week 4 cash-control rollout). The gate
// lives in SellReturnController@store; each approval is appended to a JSON
// file under storage/app (no migration, same pattern as the other admin
// audit logs). This page lets Sarah scan recent approvals — who returned
// what, who approved it, and why — without digging through transactions.
class ReturnApprovalController extends Controller
{
    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $path = storage_path('app/return-approvals-' . $business_id . '.json');

        $rows = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $rows = $decoded;
            }
        }

        $days = (int) $request->input('days', 30);
        if ($days <= 0 || $days > 365) {
            $days = 30;
        }
        $cutoff = now()->subDays($days)->toDateTimeString();
        $rows = array_values(array_filter($rows, function ($r) use ($cutoff) {
            return !empty($r['created_at']) && $r['created_at'] >= $cutoff;
        }));

        // Newest first.
        usort($rows, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        $totals = [
            'count'         => count($rows),
            'self_approved' => count(array_filter($rows, function ($r) { return !empty($r['self_approved']); })),
            'amount'        => round(array_sum(array_map(function ($r) { return (float) ($r['amount'] ?? 0); }, $rows)), 2),
        ];

        return view('admin.return_approvals', [
            'rows'    => $rows,
            'totals'  => $totals,
            'days'    => $days,
        ]);
    }
}
