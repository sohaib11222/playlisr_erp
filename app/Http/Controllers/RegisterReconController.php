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

        return view('register_recon.index', compact(
            'date', 'report', 'slack_text', 'error', 'masked', 'posted', 'prev_date', 'next_date', 'allow_next'
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
