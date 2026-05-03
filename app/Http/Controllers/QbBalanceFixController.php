<?php

namespace App\Http\Controllers;

use App\Services\QuickBooksService;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;

/**
 * One-shot QuickBooks balance correction tool.
 *
 * Lists each active bank/credit-card account with its current QB
 * CurrentBalance, lets the owner type the actual balance per account,
 * and posts a journal entry per row that moves the QB balance to match.
 * Offset is Opening Balance Equity so the P&L isn't touched.
 *
 * Why this exists: QB's "Reconcile" flow keeps under-correcting because
 * the bank feed re-imports while you reconcile. This bypasses that —
 * single API call per account, balance lands exactly where you tell it.
 */
class QbBalanceFixController extends Controller
{
    /** @var BusinessUtil */
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    public function index(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $qb = new QuickBooksService($business_id);
        $configured = $qb->isConfigured();

        $accounts = [];
        $obe_id = null;
        $error = null;

        if ($configured) {
            $bank = $qb->getBankAccounts();
            if (!empty($bank['success'])) {
                $accounts = $bank['accounts'] ?? [];
                // Need the QB Id per account too, not just the display fields.
                // getBankAccounts as written doesn't return the Id, so re-query.
                $accounts = $this->refetchAccountsWithIds($qb);
            } else {
                $error = $bank['msg'] ?? 'Could not load bank accounts.';
            }
            $obe_id = $qb->getOpeningBalanceEquityId();
            if (empty($obe_id)) {
                $error = $error ?: 'Could not find "Opening Balance Equity" account in QuickBooks.';
            }
        }

        return view('admin.qb_balance_fix', compact('configured', 'accounts', 'obe_id', 'error'));
    }

    public function apply(Request $request)
    {
        $this->guardAdmin();
        $business_id = $request->session()->get('user.business_id');
        $qb = new QuickBooksService($business_id);

        $obe_id = $qb->getOpeningBalanceEquityId();
        if (empty($obe_id)) {
            return back()->with('status', ['type' => 'error', 'msg' => 'Could not find Opening Balance Equity account.']);
        }

        $targets = $request->input('target', []); // [account_id => target balance]
        $accounts = $this->refetchAccountsWithIds($qb);

        $results = [];
        foreach ($accounts as $a) {
            $aid = $a['id'];
            if (!array_key_exists($aid, $targets) || $targets[$aid] === '' || $targets[$aid] === null) {
                continue; // Sarah didn't enter a value for this row → skip.
            }
            $target = (float)$targets[$aid];
            $current = (float)$a['balance'];
            $delta = $target - $current;
            if (abs($delta) < 0.005) {
                $results[] = ['name' => $a['name'], 'msg' => 'Already correct, skipped.'];
                continue;
            }
            $note = sprintf('Balance correction: %s from $%s to $%s',
                $a['name'], number_format($current, 2), number_format($target, 2));
            $r = $qb->postBalanceCorrectionJE($aid, $a['type'], $delta, $obe_id, $note);
            if (!empty($r['success'])) {
                $results[] = [
                    'name' => $a['name'],
                    'msg'  => sprintf('Posted JE #%s — %s $%s', $r['je_id'] ?? '?', $r['direction'] ?? '', number_format($r['amount'], 2)),
                    'ok'   => true,
                    'je_id' => $r['je_id'] ?? null,
                ];
            } else {
                $results[] = [
                    'name' => $a['name'],
                    'msg'  => 'Failed: ' . ($r['msg'] ?? 'unknown'),
                    'ok'   => false,
                ];
            }
        }

        return back()->with('apply_results', $results);
    }

    /**
     * QuickBooksService::getBankAccounts() doesn't expose the Id field,
     * which we need to target the JE. Re-run the query here returning
     * everything we need.
     */
    protected function refetchAccountsWithIds(QuickBooksService $qb)
    {
        $reflection = new \ReflectionClass($qb);
        $method = $reflection->getMethod('apiRequest');
        $method->setAccessible(true);
        $query = "SELECT Id, Name, AccountType, AccountSubType, CurrentBalance, Active "
               . "FROM Account WHERE AccountType IN ('Bank','Credit Card') AND Active = true MAXRESULTS 100";
        $result = $method->invoke($qb, 'GET', '/query', null, ['query' => $query]);
        if (empty($result['success'])) return [];
        $rows = $result['data']['QueryResponse']['Account'] ?? [];
        if (isset($rows['Id'])) $rows = [$rows];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'      => (string)($r['Id'] ?? ''),
                'name'    => $r['Name'] ?? '',
                'type'    => $r['AccountType'] ?? '',
                'subtype' => $r['AccountSubType'] ?? '',
                'balance' => (float)($r['CurrentBalance'] ?? 0),
            ];
        }
        return $out;
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
