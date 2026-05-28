<?php

namespace App\Http\Controllers;

use App\Contact;
use App\Services\NivessaBackendCreditSyncService;
use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class StoreCreditSyncController extends Controller
{
    /** @var BusinessUtil */
    protected $businessUtil;

    /** @var NivessaBackendCreditSyncService */
    protected $syncService;

    public function __construct(BusinessUtil $businessUtil, NivessaBackendCreditSyncService $syncService)
    {
        $this->businessUtil = $businessUtil;
        $this->syncService = $syncService;
    }

    public function index(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');
        $q = trim((string) $request->query('q', ''));
        $onlyMismatch = (int) $request->query('only_mismatch', 0) === 1;

        $contactsQuery = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->select(['id', 'name', 'email', 'mobile', 'balance'])
            ->orderBy('name');

        if ($q !== '') {
            $contactsQuery->where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%')
                    ->orWhere('mobile', 'like', '%' . $q . '%');
            });
        }

        $contacts = $contactsQuery->limit(400)->get();
        $emailList = $contacts->pluck('email')->all();
        $backendBalances = $this->syncService->fetchBalancesByEmail($emailList);

        $rows = [];
        foreach ($contacts as $c) {
            $email = strtolower(trim((string) $c->email));
            $erpBalance = round((float) ($c->balance ?? 0), 2);
            $backend = $backendBalances[$email] ?? ['exists' => false, 'balance' => 0.0];
            $backendBalance = round((float) ($backend['balance'] ?? 0), 2);
            $mismatch = abs($erpBalance - $backendBalance) > 0.009;

            if ($onlyMismatch && !$mismatch) {
                continue;
            }

            $rows[] = (object) [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'email' => $email,
                'mobile' => (string) ($c->mobile ?? ''),
                'erp_balance' => $erpBalance,
                'backend_exists' => !empty($backend['exists']),
                'backend_balance' => $backendBalance,
                'mismatch' => $mismatch,
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a->mismatch !== $b->mismatch) {
                return $a->mismatch ? -1 : 1;
            }
            return strcasecmp($a->name, $b->name);
        });

        return view('admin.store_credit_sync', [
            'rows' => $rows,
            'q' => $q,
            'only_mismatch' => $onlyMismatch,
        ]);
    }

    public function reconcile(Request $request)
    {
        $this->guardAdmin();
        $business_id = (int) $request->session()->get('user.business_id');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'strategy' => 'required|in:sum,erp,backend',
            'backend_balance' => 'required|numeric',
        ]);

        $contact = Contact::where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail((int) $data['contact_id']);

        $email = strtolower(trim((string) ($contact->email ?? '')));
        if ($email === '') {
            return back()->with('status', ['type' => 'error', 'msg' => 'Customer email is required for sync reconciliation.']);
        }

        $erpBalance = round((float) ($contact->balance ?? 0), 2);
        $backendBalance = round((float) $data['backend_balance'], 2);

        if ($data['strategy'] === 'sum') {
            $target = round($erpBalance + $backendBalance, 2);
        } elseif ($data['strategy'] === 'backend') {
            $target = $backendBalance;
        } else {
            $target = $erpBalance;
        }

        $backendDelta = round($target - $backendBalance, 2);
        if (abs($backendDelta) > 0.009) {
            $ok = $this->syncService->syncDeltaByEmail(
                $email,
                $backendDelta,
                'erp_manual_reconcile_' . $data['strategy'],
                ['contact_id' => (int) $contact->id, 'strategy' => $data['strategy']]
            );
            if (!$ok) {
                return back()->with('status', ['type' => 'error', 'msg' => 'Could not update backend balance. No ERP changes were saved.']);
            }
        }

        $contact->balance = $target;
        if (Schema::hasColumn('contacts', 'balance_notes')) {
            $stamp = now()->format('Y-m-d H:i');
            $line = sprintf(
                '[%s] sync reconcile (%s) -> ERP/back-end set to $%s (old ERP: $%s, old backend: $%s)',
                $stamp,
                $data['strategy'],
                number_format($target, 2),
                number_format($erpBalance, 2),
                number_format($backendBalance, 2)
            );
            $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
        }
        $contact->save();

        return back()->with('status', [
            'type' => 'success',
            'msg' => 'Reconciled successfully. New synced balance: $' . number_format($target, 2),
        ]);
    }

    protected function guardAdmin()
    {
        $user = auth()->user();
        if (!$user || !$this->businessUtil->is_admin($user)) {
            abort(403, 'Admins only.');
        }
    }
}
