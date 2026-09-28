<?php

namespace App\Http\Controllers;

use App\Utils\RegisterReconUtil;
use Illuminate\Http\Request;

/**
 * /register-recon — admin page behind the daily #register-reconciliation
 * Slack post: preview any day's flags, set the channel webhook, post now.
 * The same digest posts automatically every morning for yesterday
 * (register-recon:post, scheduled in Console\Kernel).
 */
class RegisterReconController extends Controller
{
    private function requireAdmin(): void
    {
        $u = auth()->user();
        $is_admin = false;
        try {
            $is_admin = $u && ($u->can('superadmin') || $u->hasAnyPermission('Admin#' . $u->business_id)
                || $u->hasRole('Admin#' . $u->business_id));
        } catch (\Throwable $e) {
        }
        if (!$is_admin) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function dateFrom(Request $request): string
    {
        $date = (string) $request->input('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = \Carbon\Carbon::now('America/Los_Angeles')->subDay()->format('Y-m-d');
        }
        return $date;
    }

    public function index(Request $request)
    {
        $this->requireAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $date = $this->dateFrom($request);

        $report = null;
        $slack_text = '';
        $error = null;
        try {
            $report = RegisterReconUtil::build($business_id, $date, $request->session());
            $slack_text = RegisterReconUtil::formatSlack($report);
        } catch (\Throwable $e) {
            \Log::warning('register recon preview failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $error = $e->getMessage();
        }

        $settings = RegisterReconUtil::settings();
        $webhook = RegisterReconUtil::webhook();
        $masked = $webhook !== '' ? '...' . substr($webhook, -8) : '';
        $posted = $settings['posted'] ?? [];
        $prev_date = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        $next_date = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');
        $allow_next = $next_date < \Carbon\Carbon::now('America/Los_Angeles')->format('Y-m-d');

        // Registers from the last 3 days for the "log a missed safe drop" form.
        $recent_registers = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'u.id', '=', 'cr.user_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 'cr.location_id')
            ->where('cr.business_id', $business_id)
            ->where('cr.created_at', '>=', \Carbon\Carbon::now()->subDays(3))
            ->orderByDesc('cr.created_at')
            ->get(['cr.id', 'cr.created_at', 'cr.status', 'u.first_name', 'bl.name as loc']);

        return view('register_recon.index', compact(
            'date', 'report', 'slack_text', 'error', 'masked', 'posted', 'prev_date', 'next_date', 'allow_next', 'recent_registers'
        ));
    }

    public function saveSettings(Request $request)
    {
        $this->requireAdmin();
        $url = trim((string) $request->input('slack_webhook'));
        if ($url !== '' && strpos($url, 'https://hooks.slack.com/') !== 0) {
            return redirect()->back()->with('status', [
                'success' => 0,
                'msg' => 'That does not look like a Slack incoming-webhook URL (should start with https://hooks.slack.com/).',
            ]);
        }
        RegisterReconUtil::saveSettings(['slack_webhook' => $url]);
        return redirect()->back()->with('status', [
            'success' => 1,
            'msg' => $url === '' ? 'Webhook cleared.' : 'Webhook saved. The daily post goes out at 8am.',
        ]);
    }

    /**
     * A cashier moved cash to the safe but entered $0 (Manolo 9/28: $100,
     * deposit #217, at open). Record the deposit and, for an open-time drop,
     * take it off the opening cash so the drawer check doesn't show it as
     * missing. Admin-only; before/after values go to the activity log.
     */
    public function logMissedDrop(Request $request)
    {
        $this->requireAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $regId  = (int) $request->input('register_id');
        $amount = round((float) $request->input('amount'), 2);
        $phase  = $request->input('phase') === 'close' ? 'close' : 'open';
        $seq    = (int) $request->input('deposit_seq');

        $reg = \DB::table('cash_registers')->where('business_id', $business_id)->where('id', $regId)->first();
        if (!$reg || $amount <= 0 || $seq <= 0) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Pick a register and enter the amount and deposit number.']);
        }
        if (\DB::table('cash_deposits')->where('business_id', $business_id)->where('location_id', $reg->location_id)->where('deposit_seq', $seq)->exists()) {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => "Deposit #{$seq} is already logged for that store."]);
        }
        $initialRow = \DB::table('cash_register_transactions')->where('cash_register_id', $reg->id)->where('transaction_type', 'initial')->first();
        $before = ['safe_drop_amount' => $reg->safe_drop_amount, 'initial' => $initialRow->amount ?? null];

        \DB::transaction(function () use ($business_id, $reg, $amount, $phase, $seq, $initialRow) {
            $now = \Carbon\Carbon::now()->format('Y-m-d H:i:s');
            $user = \App\User::find($reg->user_id);
            \DB::table('cash_deposits')->insert([
                'business_id' => $business_id, 'location_id' => $reg->location_id, 'cash_register_id' => $reg->id,
                'user_id' => $reg->user_id, 'cashier_name' => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
                'deposit_seq' => $seq, 'amount' => $amount, 'phase' => $phase,
                'deposited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            \DB::table('cash_registers')->where('id', $reg->id)->update([
                'safe_drop_amount' => \DB::raw('COALESCE(safe_drop_amount, 0) + ' . $amount),
            ]);
            // Open-time drop: the cashier's count included this cash, so the
            // opening balance was recorded too high.
            if ($phase === 'open' && $initialRow) {
                \DB::table('cash_register_transactions')->where('id', $initialRow->id)
                    ->update(['amount' => max(0, (float) $initialRow->amount - $amount)]);
            }
        });

        try {
            activity()->causedBy(auth()->user())->withProperties([
                'register_id' => $reg->id, 'amount' => $amount, 'phase' => $phase, 'deposit_seq' => $seq, 'before' => $before,
            ])->log('register_recon_missed_drop');
        } catch (\Throwable $e) {
        }

        return redirect()->back()->with('status', ['success' => 1, 'msg' => "Logged \${$amount} safe drop (#{$seq}) on register {$reg->id}."]);
    }

    public function postNow(Request $request)
    {
        $this->requireAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $date = $this->dateFrom($request);
        if (RegisterReconUtil::webhook() === '') {
            return redirect()->back()->with('status', ['success' => 0, 'msg' => 'Set the Slack webhook first.']);
        }
        try {
            $report = RegisterReconUtil::build($business_id, $date, $request->session());
            $ok = RegisterReconUtil::postToSlack(RegisterReconUtil::formatSlack($report), RegisterReconUtil::slackBlocks($report));
        } catch (\Throwable $e) {
            \Log::warning('register recon post failed: ' . $e->getMessage());
            $ok = false;
        }
        if ($ok) {
            $posted = RegisterReconUtil::settings()['posted'] ?? [];
            $posted[$date] = \Carbon\Carbon::now('America/Los_Angeles')->format('Y-m-d g:ia');
            RegisterReconUtil::saveSettings(['posted' => array_slice($posted, -60, null, true)]);
        }
        return redirect()->back()->with('status', [
            'success' => $ok ? 1 : 0,
            'msg' => $ok ? 'Posted to Slack.' : 'Slack post failed - check the webhook.',
        ]);
    }
}
